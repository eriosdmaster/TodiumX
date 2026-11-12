//! Named scope timers and the performance report.
//!
//! The server records how long a tick, a session update, a world save or a chunk encode took
//! and reads the numbers back to see what is eating the tick. It must never be able to crash
//! PHP, so every entry point below is a plain function that only touches this module's own
//! state and the extern wrappers in `lib.rs` wrap each call in `catch_unwind`.
//!
//! A scope keeps its count, total, min, max and a moving average. The moving average uses a
//! fixed size ring buffer of the most recent samples, so one slow frame shows up in the numbers
//! without hiding the trend.

use crate::completions;
use crate::pool;

use std::collections::HashMap;
use std::sync::{Mutex, MutexGuard, OnceLock};
use std::time::Instant;

/// How many recent samples a scope keeps for the moving average.
const SAMPLES: usize = 120;

#[derive(Default)]
struct Scope {
    count: u64,
    total_ns: u64,
    min_ns: u64,
    max_ns: u64,
    /// most recent durations, oldest first until it wraps around
    samples: Vec<u64>,
    next_sample: usize,
}

impl Scope {
    fn record(&mut self, nanos: u64) {
        if self.count == 0 {
            self.min_ns = nanos;
            self.max_ns = nanos;
        } else {
            self.min_ns = self.min_ns.min(nanos);
            self.max_ns = self.max_ns.max(nanos);
        }

        self.count += 1;
        self.total_ns += nanos;

        if self.samples.len() < SAMPLES {
            self.samples.push(nanos);
        } else {
            self.samples[self.next_sample] = nanos;
            self.next_sample = (self.next_sample + 1) % SAMPLES;
        }
    }

    fn average_ms(&self) -> f64 {
        if self.count == 0 {
            0.0
        } else {
            self.total_ns as f64 / self.count as f64 / 1_000_000.0
        }
    }

    fn moving_average_ms(&self) -> f64 {
        if self.samples.is_empty() {
            return 0.0;
        }

        let total: u64 = self.samples.iter().sum();
        total as f64 / self.samples.len() as f64 / 1_000_000.0
    }
}

#[derive(Default)]
struct Profiler {
    scopes: HashMap<String, Scope>,
    /// scopes started with `begin` and not closed yet, scope name => start time
    open: HashMap<String, Instant>,
    /// when the last frame was marked, used for the duration between frames
    last_frame: Option<Instant>,
    frames: u64,
}

static PROFILER: OnceLock<Mutex<Profiler>> = OnceLock::new();

fn lock() -> MutexGuard<'static, Profiler> {
    PROFILER
        .get_or_init(|| Mutex::new(Profiler::default()))
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
}

/// Starts timing `name`. A second `begin` for the same name overrides the first.
pub fn begin(name: &str) {
    lock().open.insert(name.to_owned(), Instant::now());
}

/// Stops timing `name` and records how long it took. Does nothing when `begin` was not called.
pub fn end(name: &str) {
    let mut profiler = lock();
    let Some(started) = profiler.open.remove(name) else {
        return;
    };

    let nanos = clamp_nanos(started.elapsed());
    profiler
        .scopes
        .entry(name.to_owned())
        .or_default()
        .record(nanos);
}

/// Marks the end of a frame, recording the time since the previous one under "frame".
pub fn frame() {
    let mut profiler = lock();
    let now = Instant::now();

    if let Some(previous) = profiler.last_frame {
        let nanos = clamp_nanos(now.duration_since(previous));
        profiler
            .scopes
            .entry("frame".to_owned())
            .or_default()
            .record(nanos);
    }

    profiler.last_frame = Some(now);
    profiler.frames += 1;
}

/// Forgets every scope, open timer and frame. The pool and result queue keep their counters.
pub fn reset() {
    let mut profiler = lock();
    profiler.scopes.clear();
    profiler.open.clear();
    profiler.last_frame = None;
    profiler.frames = 0;
}

fn clamp_nanos(duration: std::time::Duration) -> u64 {
    duration.as_nanos().min(u64::MAX as u128) as u64
}

/// Builds the JSON PHP reads back. Timings are milliseconds with four decimals, which is well
/// below the timer resolution and keeps the report readable.
pub fn report() -> String {
    let profiler = lock();
    let pool = pool::stats();
    let results = completions::stats();

    let mut out = String::with_capacity(256 + profiler.scopes.len() * 128);
    out.push_str("{\"frames\":");
    out.push_str(&profiler.frames.to_string());

    out.push_str(",\"scopes\":{");
    let mut first = true;
    for (name, scope) in &profiler.scopes {
        if !first {
            out.push(',');
        }
        first = false;

        push_name(&mut out, name);
        out.push_str(":{\"count\":");
        out.push_str(&scope.count.to_string());
        out.push_str(",\"total_ms\":");
        push_ms(&mut out, scope.total_ns);
        out.push_str(",\"min_ms\":");
        push_ms(&mut out, scope.min_ns);
        out.push_str(",\"max_ms\":");
        push_ms(&mut out, scope.max_ns);
        out.push_str(",\"avg_ms\":");
        push_number(&mut out, scope.average_ms());
        out.push_str(",\"moving_avg_ms\":");
        push_number(&mut out, scope.moving_average_ms());
        out.push('}');
    }
    out.push('}');

    out.push_str(",\"pool\":{\"queued\":");
    out.push_str(&pool.queued.to_string());
    out.push_str(",\"executed\":");
    out.push_str(&pool.executed.to_string());
    out.push_str(",\"workers\":");
    out.push_str(&pool.workers.to_string());
    out.push('}');

    out.push_str(",\"completions\":{\"completed\":");
    out.push_str(&results.completed.to_string());
    out.push_str(",\"pending\":");
    out.push_str(&results.pending.to_string());
    out.push_str(",\"total_wait_ms\":");
    push_ms(&mut out, results.total_wait_ns);
    out.push_str(",\"max_wait_ms\":");
    push_ms(&mut out, results.max_wait_ns);
    out.push('}');

    out.push('}');
    out
}

fn push_ms(out: &mut String, nanos: u64) {
    push_number(out, nanos as f64 / 1_000_000.0);
}

fn push_number(out: &mut String, value: f64) {
    out.push_str(&format!("{:.4}", value));
}

/// Writes `value` as a JSON string, escaping what a scope name could contain.
fn push_name(out: &mut String, value: &str) {
    out.push('"');
    for character in value.chars() {
        match character {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            control if (control as u32) < 0x20 => {
                out.push_str(&format!("\\u{:04x}", control as u32))
            }
            other => out.push(other),
        }
    }
    out.push('"');
}
