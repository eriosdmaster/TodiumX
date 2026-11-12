//! Work finished on the worker threads waits here until PHP picks it up in its tick.

use std::collections::VecDeque;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;

static NEXT_ID: AtomicU64 = AtomicU64::new(1);

static DONE: Mutex<VecDeque<(u64, Vec<u8>)>> = Mutex::new(VecDeque::new());

/// A new ID for a piece of work, never 0.
pub fn next_id() -> u64 {
    NEXT_ID.fetch_add(1, Ordering::Relaxed)
}

pub fn complete(id: u64, data: Vec<u8>) {
    {
        let mut results = results().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        results.waiting.insert(id, std::time::Instant::now());
        results.completed += 1;
    }

    lock().push_back((id, data));
}

/// When completed results entered the queue, plus how long they had to wait, so the report can
/// point at work that is finished but not collected yet and is holding up the tick.
#[derive(Default)]
struct Results {
    waiting: std::collections::HashMap<u64, std::time::Instant>,
    completed: u64,
    total_wait_ns: u64,
    max_wait_ns: u64,
}

/// What the result queue looks like right now.
pub struct Stats {
    pub completed: u64,
    pub pending: usize,
    pub total_wait_ns: u64,
    pub max_wait_ns: u64,
}

static RESULTS: OnceLock<Mutex<Results>> = OnceLock::new();

fn results() -> &'static Mutex<Results> {
    RESULTS.get_or_init(|| Mutex::new(Results::default()))
}

pub fn stats() -> Stats {
    let results = results().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    Stats {
        completed: results.completed,
        pending: results.waiting.len(),
        total_wait_ns: results.total_wait_ns,
        max_wait_ns: results.max_wait_ns,
    }
}

pub fn take() -> Option<(u64, Vec<u8>)> {
    let (id, data) = lock().pop_front()?;

    {
        let mut results = results().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        if let Some(started) = results.waiting.remove(&id) {
            let nanos = started.elapsed().as_nanos().min(u64::MAX as u128) as u64;
            results.total_wait_ns += nanos;
            results.max_wait_ns = results.max_wait_ns.max(nanos);
        }
    }

    Some((id, data))
}

fn lock() -> std::sync::MutexGuard<'static, VecDeque<(u64, Vec<u8>)>> {
    //a thread that panicked while holding the lock left the queue as it was, it is still fine to use
    DONE.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}