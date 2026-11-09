//! Bridge protocol between embedded JS runtime and PHP host.
//! Provides bidirectional RPC for @minecraft/server API calls from scripts to server.
//!
//! Flow: JS calls `__mc_bridge_call(method, params)` -> a Promise is created in JS and the request
//! (method + params as JSON text) is queued here. PHP polls it with `rx_bridge_poll_request`, handles
//! it and answers with `rx_bridge_submit_response`, which settles the JS Promise.
//!
//! Only plain Strings/numbers cross the queues, never JS values, so nothing here borrows from the
//! JS runtime (that is what made the old version fail to compile and to be `Send`).

use std::collections::{HashMap, VecDeque};
use std::ffi::CStr;
use std::os::raw::c_char;
use std::sync::{Arc, Mutex, OnceLock};

use rquickjs::{Context, Function, Runtime};

/// JS side of the bridge. Promises live entirely in JS; Rust only moves JSON strings around.
const BRIDGE_JS: &str = r#"
(function () {
  var g = globalThis;
  var pending = new Map();
  var next = 0;
  g.__mc_bridge_call = function (method, params) {
    return new Promise(function (resolve, reject) {
      var id = ++next;
      pending.set(id, { resolve: resolve, reject: reject });
      try {
        var json = JSON.stringify(params === undefined ? null : params);
        __mc_bridge_enqueue(id, String(method), json === undefined ? "null" : json);
      } catch (e) {
        pending.delete(id);
        reject(e);
      }
    });
  };
  g.__mc_bridge_resolve = function (id, resultJson, error) {
    var p = pending.get(id);
    if (!p) return;
    pending.delete(id);
    if (error) { p.reject(new Error(error)); return; }
    var value = null;
    try { value = resultJson ? JSON.parse(resultJson) : null; } catch (e) { p.reject(e); return; }
    p.resolve(value);
  };
})();
"#;

struct BridgeRequest {
    id: u64,
    method: String,
    params_json: String,
}

struct ScriptBridge {
    // field order matters: the context must be dropped before the runtime
    context: Context,
    runtime: Runtime,
    /// filled by JS (through `__mc_bridge_enqueue`), drained by PHP; separate lock so JS can enqueue while we are inside `eval`
    requests: Arc<Mutex<VecDeque<BridgeRequest>>>,
    /// ids already handed to PHP and still waiting for an answer, oldest first
    in_flight: VecDeque<u64>,
}

type SharedBridge = Arc<Mutex<ScriptBridge>>;

static BRIDGES: OnceLock<Mutex<HashMap<u64, SharedBridge>>> = OnceLock::new();
static NEXT_ID: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(2_000_000);

fn bridges() -> &'static Mutex<HashMap<u64, SharedBridge>> {
    BRIDGES.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Runs `f` on one bridge. The global map lock is released before `f` runs, so scripts can never deadlock on it.
fn with_bridge<T>(id: u64, f: impl FnOnce(&mut ScriptBridge) -> T) -> Option<T> {
    let bridge = bridges().lock().ok()?.get(&id).cloned()?;
    let mut guard = bridge.lock().ok()?;
    Some(f(&mut guard))
}

fn run_pending_jobs(runtime: &Runtime) {
    while let Ok(true) = runtime.execute_pending_job() {}
}

fn create_bridge(shim_js: &str, pack_name: &str) -> Option<u64> {
    let runtime = Runtime::new().ok()?;
    let context = Context::full(&runtime).ok()?;
    let requests: Arc<Mutex<VecDeque<BridgeRequest>>> = Arc::new(Mutex::new(VecDeque::new()));

    let queue = requests.clone();
    context.with(|ctx| -> rquickjs::Result<()> {
        let enqueue = Function::new(ctx.clone(), move |id: f64, method: String, params: String| {
            if let Ok(mut q) = queue.lock() {
                q.push_back(BridgeRequest { id: id as u64, method, params_json: params });
            }
        })?;
        ctx.globals().set("__mc_bridge_enqueue", enqueue)?;
        ctx.eval::<(), _>(BRIDGE_JS)?;
        let setup = format!("globalThis.__TODIUMX_PACK__ = {};\n{}", json_string(pack_name), shim_js);
        ctx.eval::<(), _>(setup)?;
        Ok(())
    }).ok()?;
    run_pending_jobs(&runtime);

    let id = NEXT_ID.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
    let bridge = ScriptBridge { context, runtime, requests, in_flight: VecDeque::new() };
    bridges().lock().ok()?.insert(id, Arc::new(Mutex::new(bridge)));
    Some(id)
}

/// Initialize a bridge-enabled script runtime. Returns handle (>0) or 0.
#[no_mangle]
pub extern "C" fn rx_bridge_new(pack_name: *const c_char, shim_js: *const c_char) -> u64 {
    std::panic::catch_unwind(|| unsafe {
        let name = if pack_name.is_null() { "" } else { CStr::from_ptr(pack_name).to_str().unwrap_or("") };
        let shim = if shim_js.is_null() { "" } else { CStr::from_ptr(shim_js).to_str().unwrap_or("") };
        create_bridge(shim, name).unwrap_or(0)
    }).unwrap_or(0)
}

/// Evaluates user script code in the bridge context. Returns 1 when it ran without a JS error.
#[no_mangle]
pub extern "C" fn rx_bridge_eval(handle: u64, code: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if code.is_null() { return 0; }
        let code = CStr::from_ptr(code).to_str().unwrap_or("");
        with_bridge(handle, |b| {
            let ok = b.context.with(|ctx| ctx.eval::<(), _>(code).is_ok());
            run_pending_jobs(&b.runtime);
            ok as i32
        }).unwrap_or(0)
    }).unwrap_or(0)
}

/// Polls a pending host call request from the script.
/// Returns 1 and writes method/params JSON into the out pointers; caller frees each via rx_free.
/// Returns 0 if no request pending.
#[no_mangle]
pub extern "C" fn rx_bridge_poll_request(handle: u64, out_method: *mut *mut u8, out_method_len: *mut usize, out_params: *mut *mut u8, out_params_len: *mut usize) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if out_method.is_null() || out_method_len.is_null() || out_params.is_null() || out_params_len.is_null() { return 0; }
        *out_method = std::ptr::null_mut(); *out_method_len = 0;
        *out_params = std::ptr::null_mut(); *out_params_len = 0;
        let request = with_bridge(handle, |b| {
            let req = b.requests.lock().ok()?.pop_front()?;
            b.in_flight.push_back(req.id);
            Some(req)
        }).flatten();
        match request {
            Some(req) => {
                crate::give(req.method.into_bytes(), out_method, out_method_len);
                crate::give(req.params_json.into_bytes(), out_params, out_params_len);
                1
            }
            None => 0,
        }
    }).unwrap_or(0)
}

/// Submits a host call response from PHP and settles the script's Promise.
/// `request_id` is matched against the requests handed out; if it matches none, the oldest one is answered.
/// An empty or null `error_json` means success.
#[no_mangle]
pub extern "C" fn rx_bridge_submit_response(handle: u64, request_id: u64, result_json: *const c_char, error_json: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        let result = if result_json.is_null() { "null".to_string() } else { CStr::from_ptr(result_json).to_str().unwrap_or("null").to_string() };
        let error = if error_json.is_null() { String::new() } else { CStr::from_ptr(error_json).to_str().unwrap_or("").to_string() };
        with_bridge(handle, |b| {
            let id = match b.in_flight.iter().position(|&x| x == request_id) {
                Some(pos) => b.in_flight.remove(pos),
                None => b.in_flight.pop_front(),
            };
            let Some(id) = id else { return 0 };
            let ok = b.context.with(|ctx| -> rquickjs::Result<()> {
                let resolve: Function = ctx.globals().get("__mc_bridge_resolve")?;
                resolve.call::<_, ()>((id as f64, result, error))
            }).is_ok();
            run_pending_jobs(&b.runtime);
            ok as i32
        }).unwrap_or(0)
    }).unwrap_or(0)
}

/// Polls log lines from the script (console.* etc).
#[no_mangle]
pub extern "C" fn rx_bridge_poll_log(_handle: u64, _out: *mut *mut c_char, _out_len: *mut usize) -> i32 {
    0 // simplified; reuse rx_script_poll_log if needed
}

#[no_mangle]
pub extern "C" fn rx_bridge_free(handle: u64) {
    let _ = std::panic::catch_unwind(|| {
        // take the bridge out first, drop it after the map lock is released
        let removed = bridges().lock().ok().and_then(|mut g| g.remove(&handle));
        drop(removed);
    });
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
