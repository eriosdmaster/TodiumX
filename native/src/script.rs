//! Embedded JavaScript runtime for behavior pack scripts (no Node.js required).
//! Uses QuickJS via rquickjs. Logs are queued and polled by PHP.

use std::collections::{HashMap, VecDeque};
use std::ffi::CStr;
use std::os::raw::c_char;
use std::sync::{Mutex, OnceLock};

use rquickjs::{Context, Runtime};

struct ScriptVm {
    _runtime: Runtime,
    context: Context<'static>,
    logs: VecDeque<String>,
}

static VMS: OnceLock<Mutex<HashMap<u64, ScriptVm>>> = OnceLock::new();
static NEXT_ID: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(1_000_000);

fn vms() -> &'static Mutex<HashMap<u64, ScriptVm>> {
    VMS.get_or_init(|| Mutex::new(HashMap::new()))
}

fn with_vm<T>(id: u64, f: impl FnOnce(&mut ScriptVm) -> T) -> Option<T> {
    vms().lock().ok()?.get_mut(&id).map(f)
}

fn push_log(vm: &mut ScriptVm, level: &str, msg: String) {
    vm.logs.push_back(format!("[{level}] {msg}"));
    if vm.logs.len() > 500 {
        vm.logs.pop_front();
    }
}

fn install_console(ctx: &Context<'static>, id: u64) {
    let _ = ctx.with(|ctx| {
        let global = ctx.globals();
        let console = rquickjs::Object::new(ctx.clone()).ok();
        if let Some(console) = console {
            for (name, level) in [("log", "LOG"), ("info", "INFO"), ("warn", "WARN"), ("error", "ERROR"), ("debug", "DEBUG")] {
                let lvl = level.to_string();
                let f = rquickjs::Function::new(ctx.clone(), move |msg: rquickjs::function::Rest<String>| {
                    let text = msg.0.join(" ");
                    if let Some(mut guard) = vms().lock().ok() {
                        if let Some(vm) = guard.get_mut(&id) {
                            push_log(vm, &lvl, text);
                        }
                    }
                });
                if let Ok(f) = f {
                    let _ = console.set(name, f);
                }
            }
            let _ = global.set("console", console);
        }
        // Minimal timer shims
        let _ = ctx.eval::<(), _>("globalThis.setTimeout = globalThis.setTimeout || function(fn,ms){ try{ if(typeof fn==='function') fn(); }catch(e){ console.error(e); } return 0; }; globalThis.setInterval = globalThis.setInterval || function(){ return 0; }; globalThis.clearTimeout = function(){}; globalThis.clearInterval = function(){};");
        Ok::<_, rquickjs::Error>(())
    });
}

pub fn create_runtime(shim_js: &str, pack_name: &str) -> u64 {
    let id = NEXT_ID.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
    let runtime = match Runtime::new() {
        Ok(r) => r,
        Err(_) => return 0,
    };
    let context = match Context::full(&runtime) {
        Ok(c) => c,
        Err(_) => return 0,
    };
    {
        let mut guard = match vms().lock() {
            Ok(g) => g,
            Err(_) => return 0,
        };
        guard.insert(id, ScriptVm { _runtime: runtime, context, logs: VecDeque::new() });
    }
    install_console(&with_vm(id, |vm| vm.context.clone()).unwrap_or_else(|| Context::full(&Runtime::new().unwrap()).unwrap()), id);
    let _ = with_vm(id, |vm| {
        install_console(&vm.context, id);
        push_log(vm, "INFO", format!("Script runtime created for pack '{pack_name}'"));
        let setup = format!("globalThis.__TODIUMX_PACK__ = {};\n{}", json_string(pack_name), shim_js);
        let _ = vm.context.with(|ctx| ctx.eval::<(), _>(setup.as_str()));
    });
    id
}

pub fn eval_script(id: u64, code: &str) -> bool {
    with_vm(id, |vm| {
        let result = vm.context.with(|ctx| ctx.eval::<rquickjs::Value, _>(code));
        match result {
            Ok(_) => true,
            Err(e) => {
                push_log(vm, "ERROR", format!("Script error: {e}"));
                false
            }
        }
    }).unwrap_or(false)
}

pub fn poll_log(id: u64) -> Option<String> {
    with_vm(id, |vm| vm.logs.pop_front()).flatten()
}

pub fn free_runtime(id: u64) {
    if let Ok(mut guard) = vms().lock() {
        guard.remove(&id);
    }
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
pub extern "C" fn rx_script_poll_log(handle: u64, out: *mut *mut c_char, out_len: *mut usize) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if out.is_null() || out_len.is_null() { return 0; }
        *out = std::ptr::null_mut();
        *out_len = 0;
        match poll_log(handle) {
            Some(s) => {
                let mut b = s.into_bytes();
                let len = b.len();
                let ptr = b.as_mut_ptr() as *mut c_char;
                std::mem::forget(b);
                *out = ptr;
                *out_len = len;
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
