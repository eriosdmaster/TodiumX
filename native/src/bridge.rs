//! Bridge protocol between embedded JS runtime and PHP host.
//! Provides bidirectional RPC for @minecraft/server API calls from scripts to server.

use std::collections::{HashMap, VecDeque};
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::sync::{Arc, Mutex, OnceLock};

use rquickjs::{Context, Function, Runtime, Value, JsLifetime};

static BRIDGES: OnceLock<Mutex<HashMap<u64, ScriptBridge>>> = OnceLock::new();
static NEXT_ID: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(2_000_000);

struct ScriptBridge {
    _runtime: Runtime,
    context: Context<'static>,
    request_queue: VecDeque<BridgeRequest>,
    response_queue: VecDeque<BridgeResponse>,
    script_id: u64,
}

struct BridgeRequest {
    id: u64,
    method: String,
    params: Value<'static>,
}

struct BridgeResponse {
    id: u64,
    result: Value<'static>,
    error: Option<String>,
}

fn bridges() -> &'static Mutex<HashMap<u64, ScriptBridge>> {
    BRIDGES.get_or_init(|| Mutex::new(HashMap::new()))
}

fn with_bridge<T>(id: u64, f: impl FnOnce(&mut ScriptBridge) -> T) -> Option<T> {
    bridges().lock().ok()?.get_mut(&id).map(f)
}

fn create_bridge_context(shim_js: &str, pack_name: &str) -> (u64, Runtime, Context<'static>) {
    let id = NEXT_ID.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
    let runtime = Runtime::new().expect("QuickJS runtime creation failed");
    // SAFETY: we keep the Runtime alive inside ScriptBridge; 'static context is valid for the bridge lifetime
    let context = Context::full(&runtime).expect("QuickJS context creation failed");
    (id, runtime, context)
}

/// Initialize a bridge-enabled script runtime. Returns handle (>0) or 0.
#[no_mangle]
pub extern "C" fn rx_bridge_new(pack_name: *const c_char, shim_js: *const c_char) -> u64 {
    std::panic::catch_unwind(|| unsafe {
        let name = if pack_name.is_null() { "" } else { CStr::from_ptr(pack_name).to_str().unwrap_or("") };
        let shim = if shim_js.is_null() { "" } else { CStr::from_ptr(shim_js).to_str().unwrap_or("") };
        let (id, runtime, context) = create_bridge_context(shim, name);

        let mut bridges = bridges().lock().expect("bridges mutex poisoned");
        bridges.insert(id, ScriptBridge {
            _runtime: runtime,
            context,
            request_queue: VecDeque::new(),
            response_queue: VecDeque::new(),
            script_id: id,
        });

        // Inject bridge protocol into JS global: __mc_bridge_call(method, params) -> Promise
        let _ = with_bridge(id, |b| {
            let ctx = b.context.clone();
            let _ = ctx.with(|ctx| {
                let global = ctx.globals();
                // __mc_bridge_call(method: string, params: any): Promise<any>
                let call_fn = Function::new(ctx.clone(), move |method: String, params: Value| -> rquickjs::Promise {
                    let (promise, resolver) = rquickjs::Promise::new(ctx.clone()).unwrap();
                    let mut guard = bridges().lock().unwrap();
                    if let Some(bridge) = guard.get_mut(&id) {
                        let req_id = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos() as u64;
                        bridge.request_queue.push_back(BridgeRequest { id: req_id, method, params });
                    }
                    promise
                });
                let _ = global.set("__mc_bridge_call", call_fn);
                // Expose bridge-aware shim
                let setup = format!("globalThis.__TODIUMX_PACK__ = {};\n{}", json_string(name), shim);
                let _ = ctx.eval::<(), _>(setup.as_str());
                Ok::<_, rquickjs::Error>(())
            });
        });
        id
    }).unwrap_or(0)
}

/// Evaluates user script code in the bridge context.
#[no_mangle]
pub extern "C" fn rx_bridge_eval(handle: u64, code: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if code.is_null() { return 0; }
        let code = CStr::from_ptr(code).to_str().unwrap_or("");
        with_bridge(handle, |b| {
            let ctx = b.context.clone();
            let _ = ctx.with(|ctx| ctx.eval::<(), _>(code));
        }).is_some() as i32
    }).unwrap_or(0)
}

/// Polls a pending host call request from the script.
/// Returns 1 and writes method/params JSON into out/out_len; caller frees via rx_free.
/// Returns 0 if no request pending.
#[no_mangle]
pub extern "C" fn rx_bridge_poll_request(handle: u64, out_method: *mut *mut c_char, out_method_len: *mut usize, out_params: *mut *mut c_char, out_params_len: *mut usize) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if out_method.is_null() || out_params.is_null() { return 0; }
        *out_method = std::ptr::null_mut(); *out_method_len = 0;
        *out_params = std::ptr::null_mut(); *out_params_len = 0;
        match with_bridge(handle, |b| b.request_queue.pop_front()) {
            Some(req) => {
                let method_json = Value::stringify(&req.params).unwrap_or_else(|_| "{}".to_string());
                let m_bytes = req.method.into_bytes();
                let p_bytes = method_json.into_bytes();
                let m_ptr = CString::new(m_bytes).unwrap().into_raw();
                let p_ptr = CString::new(p_bytes).unwrap().into_raw();
                *out_method = m_ptr as *mut c_char; *out_method_len = CStr::from_ptr(m_ptr).to_bytes().len();
                *out_params = p_ptr as *mut c_char; *out_params_len = CStr::from_ptr(p_ptr).to_bytes().len();
                1
            }
            None => 0,
        }
    }).unwrap_or(0)
}

/// Submits a host call response from PHP to the script promise.
#[no_mangle]
pub extern "C" fn rx_bridge_submit_response(handle: u64, request_id: u64, result_json: *const c_char, error_json: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        let result = if result_json.is_null() { "null".to_string() } else { CStr::from_ptr(result_json).to_str().unwrap_or("null").to_string() };
        let error = if error_json.is_null() { None } else { CStr::from_ptr(error_json).to_str().ok().map(|s| s.to_string()) };
        with_bridge(handle, |b| {
            b.response_queue.push_back(BridgeResponse { id: request_id, result: Value::from_json(&b.context, &result).unwrap_or(Value::Undefined), error });
        }).is_some() as i32
    }).unwrap_or(0)
}

/// Polls a completed response for a script promise (used by JS when promise resolves).
/// Returns 1 and writes result/error JSON into out/out_len; caller frees via rx_free.
#[no_mangle]
pub extern "C" fn rx_bridge_poll_response(handle: u64, out_result: *mut *mut c_char, out_result_len: *mut usize, out_error: *mut *mut c_char, out_error_len: *mut usize) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if out_result.is_null() || out_error.is_null() { return 0; }
        *out_result = std::ptr::null_mut(); *out_result_len = 0;
        *out_error = std::ptr::null_mut(); *out_error_len = 0;
        match with_bridge(handle, |b| b.response_queue.pop_front()) {
            Some(resp) => {
                let r = Value::stringify(&resp.result).unwrap_or_else(|_| "null".to_string());
                let e = resp.error.unwrap_or_default();
                let r_ptr = CString::new(r).unwrap().into_raw();
                let e_ptr = CString::new(e).unwrap().into_raw();
                *out_result = r_ptr as *mut c_char; *out_result_len = CStr::from_ptr(r_ptr).to_bytes().len();
                *out_error = e_ptr as *mut c_char; *out_error_len = CStr::from_ptr(e_ptr).to_bytes().len();
                1
            }
            None => 0,
        }
    }).unwrap_or(0)
}

/// Polls log lines from the script (console.* etc).
#[no_mangle]
pub extern "C" fn rx_bridge_poll_log(handle: u64, out: *mut *mut c_char, out_len: *mut usize) -> i32 {
    0 // simplified; reuse rx_script_poll_log if needed
}

#[no_mangle]
pub extern "C" fn rx_bridge_free(handle: u64) {
    let _ = std::panic::catch_unwind(|| {
        bridges().lock().ok().map(|mut g| g.remove(&handle));
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
