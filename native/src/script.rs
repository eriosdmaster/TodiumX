//! The Script API host: behavior pack scripts ("script" manifest modules) run in
//! an embedded QuickJS runtime (rquickjs 0.9) inside the native library — no
//! Node.js involved.
//!
//! One runtime serves every active pack, like vanilla: each pack entry is
//! compiled as an ES module named "<pack-id>|<entry path>" and its relative
//! imports resolve inside the pack through the custom module loader below;
//! `@minecraft/server` is a fixed facade module re-exporting the shim's API.
//!
//! All orchestration lives in the JS shim (native/src/tosapi_shim.js); Rust
//! keeps the module loader, the dynamic property file, the op queue and the
//! C ABI PHP calls (see raspberry\pack\script\NativeScriptHost):
//!
//!   rx_api_host_new(data_dir) -> handle
//!   rx_api_host_load(handle, packs_json)              [{id,name,entry,files:{rel:src}}]
//!   rx_api_host_tick(handle, tick, players_json)      timers + snapshot
//!   rx_api_host_emit(handle, event, request_id, data_json, out, out_len)
//!                                                     sync events (beforeChatSend)
//!   rx_api_host_eval(handle, code, out, out_len)      /scriptapi eval (5s cap)
//!   rx_api_host_command_result(handle, request_id, ok, output)  resolve promises
//!   rx_api_host_drain(handle, out, out_len)           queued ops as NDJSON
//!   rx_api_host_free(handle)

use std::collections::HashMap;
use std::ffi::CStr;
use std::os::raw::c_char;
use std::path::Path;
use std::sync::atomic::{AtomicI64, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{SystemTime, UNIX_EPOCH};

use rquickjs::loader::{Loader, Resolver};
use rquickjs::module::Declared;
use rquickjs::function::IntoArgs;
use rquickjs::{Context, Coerced, Ctx, Error, Function, Module, Null, Runtime};

const SHIM: &str = include_str!("tosapi_shim.js");

/** the module behavior packs import; re-exports what the shim built */
const SERVER_MODULE_FACADE: &str = r#"
const api = globalThis.__tosapi.api;
export const world = api.world;
export const system = api.system;
export const MinecraftDimensionTypes = api.MinecraftDimensionTypes;
"#;

/** modules packs may try to import that this Script API does not provide */
const UNSUPPORTED_MODULES: &[&str] = &[
    "@minecraft/server-ui",
    "@minecraft/server-net",
    "@minecraft/server-admin",
    "@minecraft/server-gametest",
    "@minecraft/server-editor",
    "@minecraft/server-economy",
    "@minecraft/server-vanilla-data",
];

const MEMORY_LIMIT: usize = 256 * 1024 * 1024;
const STACK_LIMIT: usize = 1024 * 1024;
const MAX_PENDING_OPS: usize = 4096;
const MAX_JOBS_PER_TURN: usize = 10_000;
const EVAL_DEADLINE_MS: u64 = 5_000;

type PackStore = HashMap<String, Pack>;

struct Pack {
    files: HashMap<String, String>,
}

struct ScriptVm {
    // field order matters: the context must be dropped before the runtime
    context: Context,
    _runtime: Runtime,
    ops: Arc<Mutex<Vec<String>>>,
    store: Arc<Mutex<PackStore>>,
    /// unix ms deadline while eval runs; 0 disables the interrupt
    deadline: Arc<AtomicI64>,
}

type SharedVm = Arc<Mutex<ScriptVm>>;

static VMS: OnceLock<Mutex<HashMap<u64, SharedVm>>> = OnceLock::new();
static NEXT_ID: AtomicU64 = AtomicU64::new(9_000_000);

fn vms() -> &'static Mutex<HashMap<u64, SharedVm>> {
    VMS.get_or_init(|| Mutex::new(HashMap::new()))
}

fn with_vm<T>(id: u64, f: impl FnOnce(&mut ScriptVm) -> T) -> Option<T> {
    let vm = vms().lock().ok()?.get(&id).cloned()?;
    let mut guard = vm.lock().ok()?;
    Some(f(&mut guard))
}

fn now_ms() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0)
}

fn push_op(ops: &Mutex<Vec<String>>, json: String) {
    if let Ok(mut queue) = ops.lock() {
        if queue.len() < MAX_PENDING_OPS {
            queue.push(json);
        }
    }
}

fn push_log(ops: &Mutex<Vec<String>>, level: &str, text: String) {
    let payload = serde_json::json!({ "op": "log", "level": level, "pack": null, "text": text });
    push_op(ops, payload.to_string());
}

/// Module names are "<pack-id>|<relative/path.js>"; the loader needs both halves.
fn split_name(name: &str) -> (&str, &str) {
    match name.find('|') {
        Some(index) => (&name[..index], &name[index + 1..]),
        None => ("", name),
    }
}

fn normalize_path(path: &str) -> String {
    let mut parts: Vec<&str> = Vec::new();
    for segment in path.split('/') {
        match segment {
            "" | "." => {}
            ".." => {
                parts.pop();
            }
            other => parts.push(other),
        }
    }
    parts.join("/")
}

struct TosapiResolver {
    store: Arc<Mutex<PackStore>>,
}

impl Resolver for TosapiResolver {
    fn resolve<'js>(&mut self, _ctx: &Ctx<'js>, base: &str, name: &str) -> rquickjs::Result<String> {
        if name == "@minecraft/server" {
            return Ok(name.to_string());
        }

        let deny = |message: String| Err(Error::Resolving {
            base: base.to_string(),
            name: name.to_string(),
            message: Some(message),
        });

        if UNSUPPORTED_MODULES.contains(&name) {
            return deny(format!("module '{name}' is not provided by the TodiumX Script API"));
        }
        if !name.starts_with('.') {
            return deny(format!(
                "external module '{name}' is not allowed in behavior pack scripts"
            ));
        }

        let (pack, base_path) = split_name(base);
        let dir = match base_path.rfind('/') {
            Some(index) => &base_path[..index],
            None => "",
        };
        let joined = normalize_path(&format!("{dir}/{name}"));

        let store = self.store.lock().map_err(|_| Error::Unknown)?;
        let pack_files = store.get(pack).map(|pack| &pack.files);
        if let Some(files) = pack_files {
            for candidate in [&joined, &format!("{joined}.js"), &format!("{joined}/index.js")] {
                if files.contains_key(candidate) {
                    return Ok(format!("{pack}|{candidate}"));
                }
            }
        }
        drop(store);

        deny(format!("cannot resolve import '{name}' from '{base}'"))
    }
}

struct TosapiLoader {
    store: Arc<Mutex<PackStore>>,
}

impl Loader for TosapiLoader {
    fn load<'js>(&mut self, ctx: &Ctx<'js>, name: &str) -> rquickjs::Result<Module<'js, Declared>> {
        if name == "@minecraft/server" {
            return Module::declare(ctx.clone(), name, SERVER_MODULE_FACADE);
        }

        let (pack, path) = split_name(name);
        let source = match self.store.lock() {
            Ok(store) => store
                .get(pack)
                .and_then(|pack| pack.files.get(path))
                .cloned(),
            Err(_) => None,
        };

        match source {
            Some(source) => Module::declare(ctx.clone(), name, source),
            None => Err(Error::Loading {
                name: name.to_string(),
                message: Some("module source is missing from the pack".to_string()),
            }),
        }
    }
}

fn exception_text(ctx: &Ctx<'_>, error: &Error) -> String {
    if matches!(error, Error::Exception) {
        let value = ctx.catch();
        if let Some(exception) = value.as_exception() {
            if let Some(stack) = exception.stack() {
                return stack;
            }
        }
    }
    error.to_string()
}

fn drain_jobs(vm: &ScriptVm) {
    for _ in 0..MAX_JOBS_PER_TURN {
        match vm._runtime.execute_pending_job() {
            Ok(true) => continue,
            Ok(false) => break,
            Err(exception) => {
                push_log(&vm.ops, "error", format!("script job failed: {exception}"));
                break;
            }
        }
    }
}

fn save_props(data_dir: &str, json: &str) {
    let folder = Path::new(data_dir);
    if std::fs::create_dir_all(folder).is_err() {
        return;
    }
    let target = folder.join("dynamic_properties.json");
    let temp = folder.join(".dynamic_properties.json.tmp");
    if std::fs::write(&temp, json).is_ok() {
        let _ = std::fs::rename(&temp, &target);
    }
}

fn create_host(data_dir: &str) -> u64 {
    let runtime = match Runtime::new() {
        Ok(runtime) => runtime,
        Err(_) => return 0,
    };
    runtime.set_memory_limit(MEMORY_LIMIT);
    runtime.set_max_stack_size(STACK_LIMIT);

    let deadline = Arc::new(AtomicI64::new(0));
    let interrupt_deadline = deadline.clone();
    runtime.set_interrupt_handler(Some(Box::new(move || {
        let deadline = interrupt_deadline.load(Ordering::Relaxed);
        deadline != 0 && now_ms() > deadline as u64
    })));

    let store: Arc<Mutex<PackStore>> = Arc::new(Mutex::new(HashMap::new()));
    let ops: Arc<Mutex<Vec<String>>> = Arc::new(Mutex::new(Vec::new()));

    //unhandled promise rejections (top-level await in an entry module, dropped
    //runCommand promises) surface here instead of vanishing
    let rejection_ops = ops.clone();
    runtime.set_host_promise_rejection_tracker(Some(Box::new(
        move |_ctx, _promise, reason, handled| {
            if !handled {
                push_log(
                    &rejection_ops,
                    "error",
                    format!("unhandled script promise rejection: {}", exception_value_text(&reason)),
                );
            }
        },
    )));

    //register before any module is compiled or evaluated
    runtime.set_loader(
        TosapiResolver {
            store: store.clone(),
        },
        TosapiLoader {
            store: store.clone(),
        },
    );

    let context = match Context::full(&runtime) {
        Ok(context) => context,
        Err(_) => return 0,
    };

    let initial_props = std::fs::read_to_string(Path::new(data_dir).join("dynamic_properties.json"))
        .unwrap_or_else(|_| "{}".to_string());

    let push_ops = ops.clone();
    let save_dir = data_dir.to_string();
    let setup = context.with(|ctx| -> rquickjs::Result<()> {
        let globals = ctx.globals();
        globals.set("__tosapiInitialProps", initial_props)?;
        globals.set(
            "__tosapiPush",
            Function::new(ctx.clone(), move |op: String| {
                push_op(&push_ops, op);
            })?,
        )?;
        globals.set(
            "__tosapiPropsSave",
            Function::new(ctx.clone(), move |json: String| {
                save_props(&save_dir, &json);
            })?,
        )?;
        ctx.eval::<(), _>(SHIM)?;
        Ok(())
    });

    if let Err(error) = setup {
        push_log(&ops, "error", format!("shim failed to load: {error}"));
    } else {
        push_log(
            &ops,
            "info",
            "TodiumX Script API host created (embedded QuickJS, rquickjs 0.9)".to_string(),
        );
    }

    let id = NEXT_ID.fetch_add(1, Ordering::Relaxed);
    match vms().lock() {
        Ok(mut guard) => {
            guard.insert(
                id,
                Arc::new(Mutex::new(ScriptVm {
                    context,
                    _runtime: runtime,
                    ops,
                    store,
                    deadline,
                })),
            );
            id
        }
        Err(_) => 0,
    }
}

/// Calls a global shim function with an already-active Ctx and returns its
/// string result (None on any error; the shim logs its own failures).
fn global_call<'js, A: IntoArgs<'js>>(ctx: &Ctx<'js>, name: &str, args: A) -> Option<String> {
    let function: Function = ctx.globals().get(name).ok()?;
    function.call::<A, String>(args).ok()
}

/// Loads the packs into the store, evaluates each entry module and reports
/// "loaded" ops. packs_json: [{"id","name","entry","files":{rel:source}}].
fn load_packs(vm: &ScriptVm, packs_json: &str) -> bool {
    let parsed: serde_json::Value = match serde_json::from_str(packs_json) {
        Ok(parsed) => parsed,
        Err(error) => {
            push_log(&vm.ops, "error", format!("invalid pack payload: {error}"));
            return false;
        }
    };

    let packs = match parsed.as_array() {
        Some(packs) => packs.clone(),
        None => {
            push_log(&vm.ops, "error", "pack payload must be an array".to_string());
            return false;
        }
    };

    for pack in packs {
        let id = pack.get("id").and_then(|value| value.as_str()).unwrap_or("").to_string();
        let name = pack.get("name").and_then(|value| value.as_str()).unwrap_or("unnamed pack").to_string();
        let entry = pack.get("entry").and_then(|value| value.as_str()).unwrap_or("").to_string();
        let mut files: HashMap<String, String> = HashMap::new();
        if let Some(map) = pack.get("files").and_then(|value| value.as_object()) {
            for (path, source) in map {
                if let Some(source) = source.as_str() {
                    files.insert(path.clone(), source.to_string());
                }
            }
        }

        if id.is_empty() || entry.is_empty() {
            push_log(&vm.ops, "error", "pack payload needs id and entry".to_string());
            continue;
        }

        vm.store.lock().ok().map(|mut store| {
            store.insert(id.clone(), Pack { files });
        });

        let entry_source = vm
            .store
            .lock()
            .ok()
            .and_then(|store| store.get(&id).and_then(|pack| pack.files.get(&entry)).cloned());

        let module_name = format!("{id}|{entry}");
        let evaluated: Result<(), String> = match entry_source {
            Some(source) => vm.context.with(|ctx| {
                global_call(&ctx, "__tosapiRegisterPack", (id.as_str(), name.as_str()));
                global_call(&ctx, "__tosapiSetPack", (id.as_str(),));

                match Module::evaluate::<String, String>(ctx.clone(), module_name, source) {
                    Ok(_) => {
                        global_call(&ctx, "__tosapiSetPack", (Null,));
                        Ok(())
                    }
                    Err(error) => {
                        global_call(&ctx, "__tosapiSetPack", (Null,));
                        Err(format!("script failed: {}", exception_text(&ctx, &error)))
                    }
                }
            }),
            None => Err(format!("script entry '{entry}' is missing from the pack payload")),
        };

        //must run OUTSIDE context.with(): execute_pending_job locks the runtime
        //the closure above still holds on this thread
        drain_jobs(vm);

        let (ok, error) = match evaluated {
            Err(message) => (false, Some(message)),
            Ok(()) => (true, None),
        };

        let payload = serde_json::json!({ "op": "loaded", "id": id, "ok": ok, "error": error });
        push_op(&vm.ops, payload.to_string());
    }

    true
}

fn exception_value_text(value: &rquickjs::Value<'_>) -> String {
    if let Some(exception) = value.as_exception() {
        if let Some(stack) = exception.stack() {
            return stack;
        }
    }
    match value.get::<Coerced<String>>() {
        Ok(coerced) => coerced.0,
        Err(_) => String::new(),
    }
}

// --- C ABI ---

#[no_mangle]
pub extern "C" fn rx_api_host_new(data_dir: *const c_char) -> u64 {
    std::panic::catch_unwind(|| unsafe {
        let data_dir = if data_dir.is_null() {
            "."
        } else {
            CStr::from_ptr(data_dir).to_str().unwrap_or(".")
        };
        create_host(data_dir)
    })
    .unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_api_host_load(handle: u64, packs_json: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if packs_json.is_null() {
            return 0;
        }
        let packs_json = CStr::from_ptr(packs_json).to_str().unwrap_or("");
        with_vm(handle, |vm| load_packs(vm, packs_json) as i32).unwrap_or(0)
    })
    .unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_api_host_tick(handle: u64, tick: i64, players_json: *const c_char) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        let players_json = if players_json.is_null() {
            "[]"
        } else {
            CStr::from_ptr(players_json).to_str().unwrap_or("[]")
        };
        with_vm(handle, |vm| {
            vm.context
                .with(|ctx| global_call(&ctx, "__tosapiOnTick", (tick, players_json)));
            drain_jobs(vm);
            1
        })
        .unwrap_or(0)
    })
    .unwrap_or(0)
}

/// Synchronous emit (beforeEvents cancel path). Writes the JSON response into
/// out/out_len ("null" when the event has no response) and returns 1.
#[no_mangle]
pub extern "C" fn rx_api_host_emit(
    handle: u64,
    event: *const c_char,
    request_id: i64,
    data_json: *const c_char,
    out: *mut *mut u8,
    out_len: *mut usize,
) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if event.is_null() || out.is_null() || out_len.is_null() {
            return 0;
        }
        *out = std::ptr::null_mut();
        *out_len = 0;

        let event = CStr::from_ptr(event).to_str().unwrap_or("");
        let data_json = if data_json.is_null() {
            "{}"
        } else {
            CStr::from_ptr(data_json).to_str().unwrap_or("{}")
        };

        with_vm(handle, |vm| {
            let response = vm
                .context
                .with(|ctx| global_call(&ctx, "__tosapiOnEmit", (event, request_id, data_json)))
                .unwrap_or_else(|| "null".to_string());
            drain_jobs(vm);
            crate::give(response.into_bytes(), out, out_len);
            1
        })
        .unwrap_or(0)
    })
    .unwrap_or(0)
}

/// Evaluates JS for /scriptapi eval and writes {"ok":bool,"result":str} into out.
#[no_mangle]
pub extern "C" fn rx_api_host_eval(
    handle: u64,
    code: *const c_char,
    out: *mut *mut u8,
    out_len: *mut usize,
) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if code.is_null() || out.is_null() || out_len.is_null() {
            return 0;
        }
        *out = std::ptr::null_mut();
        *out_len = 0;

        let code = CStr::from_ptr(code).to_str().unwrap_or("");
        with_vm(handle, |vm| {
            vm.deadline.store(
                (now_ms() + EVAL_DEADLINE_MS) as i64,
                Ordering::Relaxed,
            );
            let response = vm
                .context
                .with(|ctx| global_call(&ctx, "__tosapiEval", (code,)))
                .unwrap_or_else(|| r#"{"ok":false,"result":"the host is busy"}"#.to_string());
            vm.deadline.store(0, Ordering::Relaxed);
            drain_jobs(vm);
            crate::give(response.into_bytes(), out, out_len);
            1
        })
        .unwrap_or(0)
    })
    .unwrap_or(0)
}

/// Resolves a pending runCommand promise; its .then callbacks may queue more ops.
#[no_mangle]
pub extern "C" fn rx_api_host_command_result(
    handle: u64,
    request_id: i64,
    ok: i32,
    output: *const c_char,
) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        let output = if output.is_null() {
            ""
        } else {
            CStr::from_ptr(output).to_str().unwrap_or("")
        };
        with_vm(handle, |vm| {
            vm.context
                .with(|ctx| global_call(&ctx, "__tosapiResolveCommand", (request_id, ok, output)));
            drain_jobs(vm);
            1
        })
        .unwrap_or(0)
    })
    .unwrap_or(0)
}

/// Pops every queued op as one NDJSON blob (lines joined with \n). Returns 1
/// when there was something to read.
#[no_mangle]
pub extern "C" fn rx_api_host_drain(handle: u64, out: *mut *mut u8, out_len: *mut usize) -> i32 {
    std::panic::catch_unwind(|| unsafe {
        if out.is_null() || out_len.is_null() {
            return 0;
        }
        *out = std::ptr::null_mut();
        *out_len = 0;

        with_vm(handle, |vm| {
            let batch = vm
                .ops
                .lock()
                .ok()
                .map(|mut ops| {
                    let joined = ops.join("\n");
                    ops.clear();
                    joined
                })
                .unwrap_or_default();

            if batch.is_empty() {
                return 0;
            }
            crate::give(batch.into_bytes(), out, out_len);
            1
        })
        .unwrap_or(0)
    })
    .unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_api_host_free(handle: u64) {
    let _ = std::panic::catch_unwind(|| {
        //take the VM out first; dropping it happens after the map lock is released
        let removed = vms().lock().ok().and_then(|mut guard| guard.remove(&handle));
        drop(removed);
    });
}
