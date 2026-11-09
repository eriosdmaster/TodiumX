//! Embedded JavaScript runtime for behavior pack scripts (no Node.js required).
//! Uses QuickJS via rquickjs. Logs are queued and polled by PHP.

use std::collections::{HashMap, VecDeque};
use std::ffi::CStr;
use std::os::raw::c_char;
use std::sync::{Arc, Mutex, OnceLock};

use rquickjs::function::Rest;
use rquickjs::{Coerced, Context, Runtime};

type Logs = Arc<Mutex<VecDeque<String>>>;

struct ScriptVm {
    // field order matters: the context must be dropped before the runtime
    context: Context,
    _runtime: Runtime,
    /// shared with the console functions, so console.log never needs the global lock (no deadlock while eval runs)
    logs: Logs,
}

type SharedVm = Arc<Mutex<ScriptVm>>;

static VMS: OnceLock<Mutex<HashMap<u64, SharedVm>>> = OnceLock::new();
static NEXT_ID: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(1_000_000);

fn vms() -> &'static Mutex<HashMap<u64, SharedVm>> {
    VMS.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Runs `f` on one VM; the global map lock is released before `f` runs.
fn with_vm<T>(id: u64, f: impl FnOnce(&mut ScriptVm) -> T) -> Option<T> {
    let vm = vms().lock().ok()?.get(&id).cloned()?;
    let mut guard = vm.lock().ok()?;
    Some(f(&mut guard))
}

fn push_log(logs: &Logs, level: &str, msg: String) {
    if let Ok(mut q) = logs.lock() {
        q.push_back(format!("[{level}] {msg}"));
        if q.len() > 500 {
            q.pop_front();
        }
    }
}

fn install_console(ctx: &Context, logs: &Logs) {
    let _ = ctx.with(|ctx| -> rquickjs::Result<()> {
        let global = ctx.globals();
        let console = rquickjs::Object::new(ctx.clone())?;
        for (name, level) in [("log", "LOG"), ("info", "INFO"), ("warn", "WARN"), ("error", "ERROR"), ("debug", "DEBUG")] {
            let lvl = level.to_string();
            let logs = logs.clone();
            let f = rquickjs::Function::new(ctx.clone(), move |args: Rest<Coerced<String>>| {
                let text = args.0.into_iter().map(|c| c.0).collect::<Vec<_>>().join(" ");
                push_log(&logs, &lvl, text);
            })?;
            console.set(name, f)?;
        }
        global.set("console", console)?;
        // Minimal timer shims
        let _ = ctx.eval::<(), _>("globalThis.setTimeout = globalThis.setTimeout || function(fn,ms){ try{ if(typeof fn==='function') fn(); }catch(e){ console.error(e); } return 0; }; globalThis.setInterval = globalThis.setInterval || function(){ return 0; }; globalThis.clearTimeout = function(){}; globalThis.clearInterval = function(){};");
        Ok(())
    });
}

pub fn create_runtime(shim_js: &str, pack_name: &str) -> u64 {
    let runtime = match Runtime::new() {
        Ok(r) => r,
        Err(_) => return 0,
    };
    let context = match Context::full(&runtime) {
        Ok(c) => c,
        Err(_) => return 0,
    };
    let logs: Logs = Arc::new(Mutex::new(VecDeque::new()));
    install_console(&context, &logs);
    push_log(&logs, "INFO", format!("Script runtime created for pack '{pack_name}'"));
    let setup = format!("globalThis.__TODIUMX_PACK__ = {};\n{}", json_string(pack_name), shim_js);
    if let Err(e) = context.with(|ctx| ctx.eval::<(), _>(setup).map_err(|e| e.to_string())) {
        push_log(&logs, "ERROR", format!("Shim error: {e}"));
    }

    let id = NEXT_ID.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
    match vms().lock() {
        Ok(mut guard) => {
            guard.insert(id, Arc::new(Mutex::new(ScriptVm { context, _runtime: runtime, logs })));
            id
        }
        Err(_) => 0,
    }
}

pub fn eval_script(id: u64, code: &str) -> bool {
    with_vm(id, |vm| {
        let result = vm.context.with(|ctx| ctx.eval::<(), _>(code).map_err(|e| e.to_string()));
        match result {
            Ok(()) => true,
            Err(e) => {
                push_log(&vm.logs, "ERROR", format!("Script error: {e}"));
                false
            }
        }
    }).unwrap_or(false)
}

pub fn poll_log(id: u64) -> Option<String> {
    with_vm(id, |vm| vm.logs.lock().ok()?.pop_front()).flatten()
}

pub fn free_runtime(id: u64) {
    // take the VM out first, drop it after the map lock is released
    let removed = vms().lock().ok().and_then(|mut g| g.remove(&id));
    drop(removed);
}

fn json_string(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '"' => out.push_str("\\\""),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('"');
    out
}

// --- C FFI ---

/// Creates an embedded JS runtime for a behavior pack. Returns handle (>0) or 0 on failure.
/// `shim_js` and `pack_name` are UTF-8 strings (not necessarily null-terminated if lengths used; we take C strings).
#[no_mangle]
pub extern "C" fn rx_script_new(pack_name: *const c_char, shim_js: *const c_char) -> u64 {
    std::panic::catch_unwind(|| unsafe {
        let name = if pack_name.is_null() { "" } else { CStr::from_ptr(pack_name).to_str().unwrap_or("") };
        let shim = if shim_js.is_null() { "" } else { CStr::from_ptr(shim_js).to_str().unwrap_or("") };
        create_runtime(shim, name)
    }).unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_script_eval(handle: u64, code: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if code.is_null() { return 0; }
        let code = CStr::from_ptr(code).to_str().unwrap_or("");
        if eval_script(handle, code) { 1 } else { 0 }
    }).unwrap_or(0)
}

/// Polls one log line. Returns 1 and writes malloc-allocated UTF-8 into *out/*out_len; caller frees via rx_free.
/// Returns 0 if no log available.
#[no_mangle]
pub extern "C" fn rx_script_poll_log(handle: u64, out: *mut *mut u8, out_len: *mut usize) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if out.is_null() || out_len.is_null() { return 0; }
        *out = std::ptr::null_mut();
        *out_len = 0;
        match poll_log(handle) {
            Some(s) => {
                crate::give(s.into_bytes(), out, out_len);
                1
            }
            None => 0,
        }
    }).unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_script_free(handle: u64) {
    let _ = std::panic::catch_unwind(|| free_runtime(handle));
}
