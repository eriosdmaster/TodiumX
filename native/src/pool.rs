//! Worker threads for heavy jobs. The main thread hands work over and moves on, results come
//! back through completions.

use std::sync::mpsc::{self, Sender};
use std::sync::{Arc, Mutex, OnceLock};
use std::thread;

type Job = Box<dyn FnOnce() + Send + 'static>;

static POOL: OnceLock<Mutex<Sender<Job>>> = OnceLock::new();

/// How urgent a job is. High runs first, low only when nothing else waits, jobs of the same
/// priority keep the order they were queued in.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
pub enum Priority {
    Low = 0,
    Normal = 1,
    High = 2,
}

struct Queues {
    high: std::collections::VecDeque<Job>,
    normal: std::collections::VecDeque<Job>,
    low: std::collections::VecDeque<Job>,
    queued: u64,
    executed: u64,
}

/// Jobs waiting to run. `spawn` pushes here and then wakes a worker through `POOL`, so the
/// FIFO channel only carries wake-ups and each worker takes the most urgent job itself.
static JOBS: OnceLock<Mutex<Queues>> = OnceLock::new();

fn queues() -> &'static Mutex<Queues> {
    JOBS.get_or_init(|| {
        Mutex::new(Queues {
            high: std::collections::VecDeque::new(),
            normal: std::collections::VecDeque::new(),
            low: std::collections::VecDeque::new(),
            queued: 0,
            executed: 0,
        })
    })
}

fn push(priority: Priority, job: Job) {
    let mut queues = queues().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    match priority {
        Priority::High => queues.high.push_back(job),
        Priority::Normal => queues.normal.push_back(job),
        Priority::Low => queues.low.push_back(job),
    }
    queues.queued += 1;
}

fn pop() -> Option<Job> {
    let mut queues = queues().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    let job = queues
        .high
        .pop_front()
        .or_else(|| queues.normal.pop_front())
        .or_else(|| queues.low.pop_front());
    if job.is_some() {
        queues.queued = queues.queued.saturating_sub(1);
    }

    job
}

/// What the pool is doing right now, PHP reads it with `rx_pool_stats`.
pub struct PoolStats {
    pub queued: u64,
    pub executed: u64,
    pub workers: usize,
}

/// Depth of the queue and how many jobs have run since the process started.
pub fn stats() -> PoolStats {
    let queues = queues().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    PoolStats {
        queued: queues.queued,
        executed: queues.executed,
        workers: worker_count(),
    }
}

/// Leaves one core for the main thread, there is always at least one worker.
fn worker_count() -> usize {
    thread::available_parallelism().map_or(2, |cores| cores.get()).saturating_sub(1).clamp(1, 16)
}

fn start() -> Mutex<Sender<Job>> {
    let (sender, receiver) = mpsc::channel::<Job>();
    let receiver = Arc::new(Mutex::new(receiver));

    for number in 0..worker_count() {
        let receiver = Arc::clone(&receiver);

        thread::Builder::new()
            .name(format!("raspberry worker {number}"))
            .spawn(move || loop {
                let job = match receiver.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).recv() {
                    Ok(job) => job,
                    Err(_) => return,
                };

                //a job that panics only loses itself, the worker keeps going
                let _ = std::panic::catch_unwind(std::panic::AssertUnwindSafe(job));
            })
            .expect("could not start a worker thread");
    }

    Mutex::new(sender)
}

pub fn spawn(job: impl FnOnce() + Send + 'static) {
    spawn_with_priority(Priority::Normal, job);
}

/// Queues a job that runs before normal and low ones once a worker is free.
pub fn spawn_high(job: impl FnOnce() + Send + 'static) {
    spawn_with_priority(Priority::High, job);
}

/// Queues a job that only runs when nothing more urgent is waiting.
pub fn spawn_low(job: impl FnOnce() + Send + 'static) {
    spawn_with_priority(Priority::Low, job);
}

/// Queues a job, keeping the order of jobs that share a priority.
pub fn spawn_with_priority(priority: Priority, job: impl FnOnce() + Send + 'static) {
    push(priority, Box::new(job));
    wake();
}

/// Wakes one worker. The channel keeps the order it was written in, the worker takes the most
/// urgent job waiting instead of the wake-up itself.
fn wake() {
    let sender = POOL.get_or_init(start).lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    let wake: Job = Box::new(run_one);
    let _ = sender.send(wake);
}

fn run_one() {
    if let Some(job) = pop() {
        job();
        mark_executed();
    }
}

fn mark_executed() {
    let mut queues = queues().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    queues.executed += 1;
}