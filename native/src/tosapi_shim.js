"use strict";

/**
 * TodiumX Script API shim, executed inside the embedded QuickJS runtime
 * (raspberry-native, rquickjs) that lives in the native library.
 *
 * The API surface is DATA-DRIVEN: PHP sends a manifest (ScriptApiRegistry)
 * with every surface row (path, since version, kind) and this shim rebuilds
 * the @minecraft/server module per pack version from it — nothing here is
 * written per API. See SCRIPT_API.md ("adding an API").
 *
 * The Rust side injects these globals before this file is evaluated:
 *   __tosapiPush(opJson)       queue one op for the PHP server to drain
 *   __tosapiPropsSave(json)    persist dynamic properties (Rust writes the file)
 *   __tosapiInitialProps       the dynamic properties loaded from disk, as a JSON string
 *
 * The Rust side calls these globals:
 *   __tosapiRegisterPack(id, name)         remember a pack for log prefixes
 *   __tosapiSetPack(id)                    set the pack of the code being evaluated
 *   __tosapiSetManifest(manifestJson)      the ScriptApiRegistry manifest (once, before packs)
 *   __tosapiOnTick(tick, playersJson)      server tick: timers + player snapshot
 *   __tosapiOnEmit(event, requestId, dataJson) -> JSON response or null (sync beforeEvents)
 *   __tosapiResolveCommand(requestId, ok, output)   resolve a runCommand/apicall promise
 *   __tosapiEval(code) -> JSON {ok, result}        /scriptapi eval
 *
 * The @minecraft/server module itself is served by the Rust module loader
 * (native/src/script.rs) PER PACK VERSION: the facade re-exports
 * globalThis.__tosapi.buildApi(version) filtered by that version.
 *
 * Op protocol (see SCRIPT_API.md):
 *   loaded/ready  log  message  playerMessage  command  apicall  response  done
 *
 * Kinds the builder understands:
 *   local  – implementation in LOCAL_IMPL below
 *   bridge – promise; queues {"op":"apicall", path, args}; ScriptManager
 *            dispatches it to the PHP bridge and resolves/rejects the promise
 *   event  – subscription point fed by __tosapiOnEmit (generic payload builder)
 *   getter – property read each time (GETTER_IMPL)
 *   const  – constant value (CONST_IMPL)
 *   stub   – name exists in vanilla but not here; warns and no-ops
 */

/* eslint-disable no-restricted-globals */

const MAX_TEXT = 4096;
const MAX_API_ARGS = 32;
const MAX_API_ARGS_JSON = 65536;

/** state */
let currentTick = 0;
let players = [];
let currentPack = null;

/** @type {Map<number, {kind, due, every, fn, pack}>} */
const timers = new Map();
let nextRunId = 1;

/** @type {Map<number, {kind: "command"|"apicall", source?: string, settle: Function}>} pending bridge requests */
const commandPromises = new Map();
let nextCommandId = 1;

/** @type {Map<string, {event, pack, callback, options}>} */
const eventHandlers = new Map();
let subscribeId = 1;

/** @type {Map<string, string>} pack id -> name, for log prefixes */
const packNames = new Map();

/** @type {Map<number, Set<string>>} player id -> session tags */
const playerTags = new Map();

/** @type {{maxVersion: string, surfaces: list}|null} the PHP registry manifest */
let manifest = null;

/** per-version @minecraft/server objects, built from the manifest */
const apiCache = new Map();

/** fired once, on the first tick after load */
let worldLoadFired = false;

let dynamicProps = {};
try {
	dynamicProps = JSON.parse(globalThis.__tosapiInitialProps || "{}") || {};
} catch (e) {
	dynamicProps = {};
}

function out(payload) {
	__tosapiPush(JSON.stringify(payload));
}

function fmt(value) {
	if (typeof value === "string") return value;
	if (value instanceof Error) return value.stack || String(value);
	try { return JSON.stringify(value); } catch (e) { return String(value); }
}

function clip(text) {
	text = String(text);
	return text.length > MAX_TEXT ? text.slice(0, MAX_TEXT) + "..." : text;
}

function log(level, packId, text) {
	out({ op: "log", level, pack: packId === undefined ? null : packId, text: clip(text) });
}

const consoleForPack = (packId) => ({
	log: (...args) => log("info", packId, args.map(fmt).join(" ")),
	info: (...args) => log("info", packId, args.map(fmt).join(" ")),
	warn: (...args) => log("warning", packId, args.map(fmt).join(" ")),
	error: (...args) => log("error", packId, args.map(fmt).join(" ")),
	debug: (...args) => log("debug", packId, args.map(fmt).join(" "))
});

globalThis.console = consoleForPack(null);

// ---------------------------------------------------------------------------
// dynamic properties (string | number | boolean | {x,y,z}), world + per player
// ---------------------------------------------------------------------------

function propsFor(scope) {
	const bucket = dynamicProps[scope];
	return bucket && typeof bucket === "object" ? bucket : (dynamicProps[scope] = {});
}

function getDynamicProperty(scope, key) {
	const entry = propsFor(scope)[key];
	return entry === undefined ? undefined : entry.v;
}

function setDynamicProperty(scope, key, value) {
	if (value !== undefined && value !== null && typeof value !== "string" && typeof value !== "number" && typeof value !== "boolean") {
		if (typeof value === "object" && typeof value.x === "number" && typeof value.y === "number" && typeof value.z === "number") {
			value = { x: value.x, y: value.y, z: value.z };
		} else {
			throw new TypeError("dynamic property values must be string, number, boolean or Vector3, got " + typeof value);
		}
	}
	propsFor(scope)[key] = { t: value === undefined ? "undefined" : typeof value, v: value };
	try { __tosapiPropsSave(JSON.stringify(dynamicProps)); } catch (e) {
		log("error", undefined, "cannot persist dynamic properties: " + fmt(e));
	}
}

function clearDynamicProperty(scope, key) {
	delete propsFor(scope)[key];
	try { __tosapiPropsSave(JSON.stringify(dynamicProps)); } catch (e) {
		log("error", undefined, "cannot persist dynamic properties: " + fmt(e));
	}
}

// ---------------------------------------------------------------------------
// shared entity / dimension objects (always the full local set, see docs)
// ---------------------------------------------------------------------------

function makeLocation(position) {
	return { x: position.x, y: position.y, z: position.z };
}

function freshSnapshot(id, fallback) {
	for (const player of players) {
		if (player.id === id) return player;
	}
	return fallback;
}

function makePlayer(snapshot) {
	const scope = "player:" + snapshot.name;
	const player = {
		id: snapshot.id,
		name: snapshot.name,
		type: "minecraft:player",
		sendMessage(message) {
			out({ op: "playerMessage", player: snapshot.id, text: clip(fmt(message)) });
		},
		runCommand(commandString) {
			return runCommand(snapshot.name, commandString);
		},
		runCommandAsync(commandString) {
			return runCommand(snapshot.name, commandString);
		},
		getDynamicProperty(key) {
			return getDynamicProperty(scope, key);
		},
		setDynamicProperty(key, value) {
			setDynamicProperty(scope, key, value);
		},
		clearDynamicProperty(key) {
			clearDynamicProperty(scope, key);
		},
		addTag(tag) {
			if (typeof tag !== "string") throw new TypeError("tag must be a string");
			let tags = playerTags.get(snapshot.id);
			if (tags === undefined) {
				tags = new Set();
				playerTags.set(snapshot.id, tags);
			}
			if (tags.has(tag)) return false;
			tags.add(tag);
			return true;
		},
		getTags() {
			const tags = playerTags.get(snapshot.id);
			return tags === undefined ? [] : [...tags];
		},
		removeTag(tag) {
			const tags = playerTags.get(snapshot.id);
			return tags === undefined ? false : tags.delete(tag);
		},
		clearTags() {
			const tags = playerTags.get(snapshot.id);
			if (tags !== undefined) tags.clear();
		},
		getGameMode() {
			return snapshot.gameMode === undefined ? undefined : snapshot.gameMode;
		},
		teleport(...args) {
			let x, y, z, dimension;
			if (args.length > 0 && typeof args[0] === "object" && args[0] !== null && typeof args[0].x === "number") {
				x = args[0].x; y = args[0].y; z = args[0].z;
				dimension = args[1];
			} else {
				x = args[0]; y = args[1]; z = args[2];
				dimension = args[3];
			}
			const dimensionId = dimension === undefined || dimension === null
				? null
				: (typeof dimension === "string" ? dimension : String(dimension.id));
			return bridgeCall("player.teleport", [snapshot.id, x, y, z, dimensionId]);
		},
		toString() {
			return "Player [name: " + snapshot.name + "]";
		}
	};
	Object.defineProperty(player, "dimension", {
		get: () => makeDimension(freshSnapshot(snapshot.id, snapshot).dimension)
	});
	Object.defineProperty(player, "location", {
		get: () => makeLocation(freshSnapshot(snapshot.id, snapshot))
	});
	Object.defineProperty(player, "rotation", {
		get: () => {
			const fresh = freshSnapshot(snapshot.id, snapshot);
			return { x: fresh.pitch === undefined ? 0 : fresh.pitch, y: fresh.yaw === undefined ? 0 : fresh.yaw };
		}
	});
	return player;
}

function runCommand(sourceName, commandString) {
	const reqId = nextCommandId++;
	const promise = new Promise((resolve) => {
		commandPromises.set(reqId, {
			kind: "command",
			source: sourceName,
			settle: resolve
		});
	});
	out({ op: "command", reqId, cmd: clip(commandString), source: sourceName });
	return promise;
}

const dimensions = new Map();

function makeDimension(id) {
	let dimension = dimensions.get(id);
	if (dimension !== undefined) return dimension;

	dimension = {
		id,
		getPlayers() {
			return players.filter((p) => p.dimension === id).map(makePlayer);
		},
		runCommand(commandString) {
			return runCommand("dimension:" + id, commandString);
		},
		runCommandAsync(commandString) {
			return runCommand("dimension:" + id, commandString);
		},
		getDynamicProperty(key) {
			return getDynamicProperty("dimension:" + id, key);
		},
		setDynamicProperty(key, value) {
			setDynamicProperty("dimension:" + id, key, value);
		},
		clearDynamicProperty(key) {
			clearDynamicProperty("dimension:" + id, key);
		},
		toString() {
			return "Dimension [id: " + id + "]";
		}
	};
	dimensions.set(id, dimension);
	return dimension;
}

// ---------------------------------------------------------------------------
// bridge calls (registry kind "bridge"): promise resolved by the next tick,
// when ScriptManager answers the "apicall" op through the command channel
// ---------------------------------------------------------------------------

/** makes bridge args JSON-safe: entity refs become {id,name}, Vector3-like stay {x,y,z} */
function prepArg(value) {
	if (value === null || value === undefined) return value;
	if (typeof value === "object") {
		if (value instanceof Error) return fmt(value);
		if (typeof value.id === "number" && typeof value.name === "string") return { id: value.id, name: value.name };
		if (typeof value.x === "number" && typeof value.y === "number" && typeof value.z === "number") return { x: value.x, y: value.y, z: value.z };
	}
	return value;
}

function bridgeCall(path, args) {
	const safeArgs = (args === undefined || args === null ? [] : [...args]).map(prepArg);
	if (safeArgs.length > MAX_API_ARGS) {
		return Promise.reject(new Error(path + " accepts at most " + MAX_API_ARGS + " arguments"));
	}
	let argsJson;
	try {
		argsJson = JSON.stringify(safeArgs);
	} catch (e) {
		return Promise.reject(new Error(path + " arguments are not serializable: " + fmt(e)));
	}
	if (argsJson.length > MAX_API_ARGS_JSON) {
		return Promise.reject(new Error(path + " arguments exceed " + MAX_API_ARGS_JSON + " bytes"));
	}

	const reqId = nextCommandId++;
	const promise = new Promise((resolve, reject) => {
		commandPromises.set(reqId, {
			kind: "apicall",
			path,
			settle: (value) => resolve(value),
			fail: (message) => reject(new Error(message))
		});
	});
	out({ op: "apicall", reqId, path, args: safeArgs });
	return promise;
}

// ---------------------------------------------------------------------------
// events: one subscription registry shared by every phase and every version
// ---------------------------------------------------------------------------

function subscribeList(eventName, unsupported) {
	const subscribe = (callback, options) => {
		if (unsupported) {
			log("warning", undefined, eventName + " is not supported by the TodiumX Script API; the subscription was ignored");
			return { unsubscribe() {} };
		}
		if (typeof callback !== "function") {
			throw new TypeError("callback must be a function");
		}
		const key = eventName + "#" + subscribeId++;
		eventHandlers.set(key, { event: eventName, pack: currentPack, callback, options: options || {} });
		return { unsubscribe() { eventHandlers.delete(key); } };
	};
	return {
		subscribe,
		unsubscribe(callback) {
			for (const [key, entry] of eventHandlers) {
				if (entry.event === eventName && entry.callback === callback) eventHandlers.delete(key);
			}
		}
	};
}

function dispatchEvent(eventName, data, before) {
	const entries = [];
	for (const entry of eventHandlers.values()) {
		if (entry.event === eventName) entries.push(entry);
	}
	let cancelled = false;

	const buildEvent = () => {
		if (before) {
			const event = {
				cancelReason: undefined,
				target: undefined
			};
			Object.defineProperty(event, "cancel", {
				get: () => cancelled,
				set: (value) => {
					if (value) cancelled = true;
				}
			});
			return event;
		}
		return {};
	};

	for (const entry of entries) {
		try {
			const event = buildEvent();
			switch (eventName) {
				case "after:playerJoin":
					event.playerName = data.name;
					event.player = makePlayer(data);
					break;
				case "after:playerSpawn":
					event.player = makePlayer(data);
					event.initialSpawn = data.initialSpawn === undefined ? true : data.initialSpawn;
					break;
				case "after:playerLeave":
					event.playerName = data.name;
					event.playerId = data.id;
					break;
				case "after:chatSend":
					event.player = makePlayer(data.player);
					event.message = data.message;
					event.sendToTargets = false;
					event.targets = [];
					break;
				case "before:chatSend":
					event.player = makePlayer(data.player);
					event.message = data.message;
					break;
				case "after:scriptEventReceive":
					event.id = data.id;
					event.message = data.message;
					event.sourceEntity = undefined;
					event.initiator = undefined;
					break;
				case "after:playerBreakBlock":
				case "after:playerPlaceBlock":
					event.player = data.player === undefined ? undefined : makePlayer(data.player);
					event.block = {
						type: data.block,
						location: { x: data.x, y: data.y, z: data.z }
					};
					if (typeof data.dimension === "string") event.dimension = makeDimension(data.dimension);
					break;
				case "after:worldLoad":
					break; //no payload yet
				default:
					if (!before) log("warning", entry.pack, "event '" + eventName + "' has no payload builder; the handler received an empty object");
			}
			const namespaces = entry.options && entry.options.namespaces;
			if (eventName === "after:scriptEventReceive" && Array.isArray(namespaces) && namespaces.length > 0) {
				let matches = false;
				for (const ns of namespaces) {
					if (String(data.id || "").indexOf(ns + ":") === 0) matches = true;
				}
				if (!matches) continue;
			}
			entry.callback(event);
		} catch (error) {
			log("error", entry.pack, "error in " + eventName + " handler: " + fmt(error));
		}
	}
	return cancelled;
}

// ---------------------------------------------------------------------------
// the legacy world/system objects: the FULL local surface, always available to
// /scriptapi eval and used as the fallback when PHP sends no manifest
// ---------------------------------------------------------------------------

function worldSendMessage(message, targetPlayers) {
	const text = clip(fmt(message));
	if (Array.isArray(targetPlayers) && targetPlayers.length > 0) {
		for (const player of targetPlayers) {
			if (player && typeof player.id === "number") {
				out({ op: "playerMessage", player: player.id, text });
			}
		}
		return;
	}
	out({ op: "message", text });
}

function worldGetPlayers(options) {
	const list = players.map(makePlayer);
	if (options && typeof options.name === "string") {
		return list.filter((player) => player.name === options.name);
	}
	return list;
}

function systemSendScriptEvent(id, message) {
	if (typeof id !== "string" || id.indexOf(":") < 0) {
		throw new RangeError("script event id must look like 'namespace:event'");
	}
	dispatchEvent("after:scriptEventReceive", { id, message: String(message === undefined ? "" : message) }, false);
}

function schedule(kind, every, callback) {
	if (typeof callback !== "function") {
		throw new TypeError("callback must be a function");
	}
	const runId = nextRunId++;
	timers.set(runId, { kind, due: currentTick + every, every, fn: callback, pack: currentPack });
	return runId;
}

const MinecraftDimensionTypes = Object.freeze({
	overworld: "overworld",
	nether: "nether",
	theEnd: "the end"
});

/** global lexical binding: /scriptapi eval sees it, modules import it from the facade */
const TicksPerSecond = 20;

const world = {
	sendMessage: worldSendMessage,
	getAllPlayers() {
		return players.map(makePlayer);
	},
	getPlayers: worldGetPlayers,
	getDimension(dimensionId) {
		return makeDimension(String(dimensionId));
	},
	getDynamicProperty(key) {
		return getDynamicProperty("world", key);
	},
	setDynamicProperty(key, value) {
		setDynamicProperty("world", key, value);
	},
	clearDynamicProperty(key) {
		clearDynamicProperty("world", key);
	},
	getEntity(entityId) {
		return bridgeCall("world.getEntity", [entityId]);
	},
	getEntities() {
		return bridgeCall("world.getEntities", []);
	},
	getBlock(x, y, z) {
		return bridgeCall("world.getBlock", [x, y, z]);
	},
	scoreboard: {},
	afterEvents: null,
	beforeEvents: null
};
for (const scoreboardMethod of ["getObjective", "getObjectives", "addObjective", "removeObjective", "getScore", "setScore", "addScore", "removeScore"]) {
	world.scoreboard[scoreboardMethod] = function(...args) {
		return bridgeCall("world.scoreboard." + scoreboardMethod, args);
	};
}
world.afterEvents = makeEventSet("after:", false);
world.beforeEvents = makeEventSet("before:", true);

function makeEventSet(prefix, before) {
	const container = {};
	const define = (name, event, unsupported = false) => {
		container[name] = subscribeList(event, unsupported);
	};
	if (before) {
		define("chatSend", prefix + "chatSend");
		define("playerSendMessage", prefix + "chatSend");
		define("playerBreakBlock", prefix + "playerBreakBlock", true);
		define("playerPlaceBlock", prefix + "playerPlaceBlock", true);
		define("playerInteractWithBlock", prefix + "playerInteractWithBlock", true);
		define("playerLeave", prefix + "playerLeave", true);
	} else {
		define("playerJoin", prefix + "playerJoin");
		define("playerLeave", prefix + "playerLeave");
		define("playerSpawn", prefix + "playerSpawn");
		define("chatSend", prefix + "chatSend");
		define("playerSendMessage", prefix + "chatSend");
		define("playerBreakBlock", prefix + "playerBreakBlock");
		define("playerPlaceBlock", prefix + "playerPlaceBlock");
		define("worldLoad", prefix + "worldLoad");
	}
	return container;
}

const system = {
	get currentTick() {
		return currentTick;
	},
	run(callback) {
		return schedule("once", 0, callback);
	},
	runTimeout(callback, timeoutTicks) {
		return schedule("once", Math.max(0, Math.trunc(timeoutTicks === undefined ? 0 : timeoutTicks)), callback);
	},
	runInterval(callback, intervalTicks) {
		return schedule("interval", Math.max(1, Math.trunc(intervalTicks === undefined ? 1 : intervalTicks)), callback);
	},
	clearRun(runId) {
		return timers.delete(runId);
	},
	sendScriptEvent(id, message) {
		systemSendScriptEvent(id, message);
	},
	beforeEvents: {
		startup: {
			subscribe(callback, options) {
				log("warning", undefined, "system.beforeEvents.startup is not supported by the TodiumX Script API; the subscription was ignored");
				return { unsubscribe() {} };
			},
			unsubscribe() {}
		}
	},
	afterEvents: null
};
system.afterEvents = {
	scriptEventReceive: subscribeList("after:scriptEventReceive", false)
};

// ---------------------------------------------------------------------------
// the registry-driven builder: per-version @minecraft/server objects
// ---------------------------------------------------------------------------

/** shim implementations for registry kind "local", by full path */
const LOCAL_IMPL = {
	"world.sendMessage": (message, targetPlayers) => worldSendMessage(message, targetPlayers),
	"world.getAllPlayers": () => players.map(makePlayer),
	"world.getPlayers": (options) => worldGetPlayers(options),
	"world.getDimension": (dimensionId) => makeDimension(String(dimensionId)),
	"world.getDynamicProperty": (key) => getDynamicProperty("world", key),
	"world.setDynamicProperty": (key, value) => setDynamicProperty("world", key, value),
	"world.clearDynamicProperty": (key) => clearDynamicProperty("world", key),
	"system.run": (callback) => schedule("once", 0, callback),
	"system.runTimeout": (callback, timeoutTicks) => schedule("once", Math.max(0, Math.trunc(timeoutTicks === undefined ? 0 : timeoutTicks)), callback),
	"system.runInterval": (callback, intervalTicks) => schedule("interval", Math.max(1, Math.trunc(intervalTicks === undefined ? 1 : intervalTicks)), callback),
	"system.clearRun": (runId) => timers.delete(runId),
	"system.sendScriptEvent": (id, message) => systemSendScriptEvent(id, message)
};

/** shim getters for registry kind "getter", by full path */
const GETTER_IMPL = {
	"system.currentTick": () => currentTick
};

/** shim values for registry kind "const", by full path */
const CONST_IMPL = {
	"TicksPerSecond": 20,
	"MinecraftDimensionTypes": MinecraftDimensionTypes
};

/** "1.16.0" style numeric compare; pre-release labels count as their numbers */
function versionCompare(a, b) {
	const partsA = String(a).split("-")[0].split(".");
	const partsB = String(b).split("-")[0].split(".");
	for (let i = 0; i < 3; i++) {
		const numA = Number(partsA[i] || 0) || 0;
		const numB = Number(partsB[i] || 0) || 0;
		if (numA !== numB) return numA < numB ? -1 : 1;
	}
	return 0;
}

function stubWarning(path) {
	return function(...args) {
		log("warning", undefined, path + " is not implemented by the TodiumX Script API; the call was ignored");
		return undefined;
	};
}

function stubEventSet(path) {
	return {
		subscribe(callback, options) {
			log("warning", undefined, path + " is not supported by the TodiumX Script API; the subscription was ignored");
			return { unsubscribe() {} };
		},
		unsubscribe() {}
	};
}

function installSurfaces(root, path, build) {
	const segments = path.split(".");
	let node = root;
	for (let i = 1; i < segments.length - 1; i++) {
		const key = segments[i];
		if (typeof node[key] !== "object" || node[key] === null) node[key] = {};
		node = node[key];
	}
	const leaf = segments[segments.length - 1];
	build(node, leaf);
}

globalThis.__tosapiSetManifest = (manifestJson) => {
	try {
		manifest = JSON.parse(manifestJson || "null");
	} catch (e) {
		manifest = null;
	}
	if (!manifest || !Array.isArray(manifest.surfaces)) manifest = null;
	if (manifest === null) {
		log("warning", undefined, "the Script API manifest is missing or invalid; the module surface falls back to the built-in set");
	}
	apiCache.clear();
	return "null";
};

globalThis.__tosapiBuildApi = (version) => {
	const key = String(version === undefined || version === "" ? "1.16.0" : version);
	if (apiCache.has(key)) return apiCache.get(key);
	if (manifest === null) {
		//no manifest: the full legacy surface (world/system above) is the API
		const legacy = { world, system, MinecraftDimensionTypes, TicksPerSecond: 20 };
		apiCache.set(key, legacy);
		return legacy;
	}

	const worldApi = {};
	const systemApi = {};
	const roots = { world: worldApi, system: systemApi };

	for (const row of manifest.surfaces) {
		if (versionCompare(row.since, key) > 0) continue; //row is newer than this pack's version

		if (row.path.indexOf(".") < 0) {
			//module-level constant
			if (row.kind === "const" && CONST_IMPL[row.path] !== undefined) {
				//installed below, once
			}
			continue;
		}

		const rootName = row.path.slice(0, row.path.indexOf("."));
		if (rootName === "player" || rootName === "dimension") {
			continue; //documentation rows: entity wrappers are hand-built with the full local set
		}
		const root = roots[rootName];
		if (root === undefined) {
			log("warning", undefined, "registry row '" + row.path + "' has an unknown root and was skipped");
			continue;
		}

		installSurfaces(root, row.path, (container, leaf) => {
			switch (row.kind) {
				case "local": {
					const impl = LOCAL_IMPL[row.path];
					if (impl === undefined) {
						log("warning", undefined, "registry row '" + row.path + "' has no local implementation");
						container[leaf] = stubWarning(row.path);
					} else {
						container[leaf] = impl;
					}
					break;
				}
				case "bridge":
					container[leaf] = function(...args) {
						return bridgeCall(row.path, args);
					};
					break;
				case "getter": {
					const impl = GETTER_IMPL[row.path];
					if (impl !== undefined) {
						Object.defineProperty(container, leaf, { enumerable: true, get: impl });
					}
					break;
				}
				case "event": {
					const eventName = (row.phase === "before" ? "before:" : "after:") + row.event;
					container[leaf] = subscribeList(eventName, false);
					break;
				}
				case "stub":
					container[leaf] = stubEventSet(row.path);
					break;
				default:
					log("warning", undefined, "registry row '" + row.path + "' has kind '" + row.kind + "', which this shim does not know");
			}
		});
	}

	const api = { world: worldApi, system: systemApi };
	for (const [path, value] of Object.entries(CONST_IMPL)) {
		const row = manifest.surfaces.find((entry) => entry.path === path);
		if (row !== undefined && versionCompare(row.since, key) <= 0) {
			api[path] = value;
		}
	}

	apiCache.set(key, api);
	return api;
};

globalThis.__tosapi = {
	get api() {
		return globalThis.__tosapiBuildApi("1.16.0");
	},
	buildApi: (version) => globalThis.__tosapiBuildApi(version)
};

// ---------------------------------------------------------------------------
// entry points called from Rust
// ---------------------------------------------------------------------------

globalThis.__tosapiRegisterPack = (id, name) => {
	packNames.set(String(id), String(name));
};

globalThis.__tosapiSetPack = (id) => {
	currentPack = id === null || id === undefined ? null : String(id);
	globalThis.console = consoleForPack(currentPack);
};

globalThis.__tosapiOnTick = (tick, playersJson) => {
	currentTick = Math.trunc(Number(tick) || 0);
	try {
		players = JSON.parse(playersJson || "[]") || [];
	} catch (e) {
		players = [];
	}

	if (!worldLoadFired) {
		worldLoadFired = true;
		dispatchEvent("after:worldLoad", {}, false);
	}

	for (const [runId, timer] of [...timers]) {
		if (timer.due > currentTick) continue;
		if (timer.kind === "once") timers.delete(runId);
		else timer.due = currentTick + timer.every;
		try {
			timer.fn();
		} catch (error) {
			log("error", timer.pack, "error in system." + (timer.kind === "once" ? "run/runTimeout" : "runInterval") + " callback: " + fmt(error));
		}
	}
	return "null";
};

globalThis.__tosapiOnEmit = (event, requestId, dataJson) => {
	let data = {};
	try {
		data = JSON.parse(dataJson || "{}") || {};
	} catch (e) {
		data = {};
	}
	switch (event) {
		case "beforeChatSend":
			return JSON.stringify({ cancelled: dispatchEvent("before:chatSend", data, true) });
		case "playerJoin":
			dispatchEvent("after:playerJoin", data, false);
			dispatchEvent("after:playerSpawn", Object.assign({}, data, { initialSpawn: true }), false);
			break;
		case "playerLeave":
			dispatchEvent("after:playerLeave", data, false);
			break;
		case "chatSend":
			dispatchEvent("after:chatSend", data, false);
			break;
		case "scriptEvent":
			dispatchEvent("after:scriptEventReceive", data, false);
			break;
		case "playerBreakBlock":
			dispatchEvent("after:playerBreakBlock", data, false);
			break;
		case "playerPlaceBlock":
			dispatchEvent("after:playerPlaceBlock", data, false);
			break;
		default:
			log("warning", undefined, "unknown emit event '" + event + "'");
	}
	return "null";
};

globalThis.__tosapiResolveCommand = (requestId, ok, output) => {
	const entry = commandPromises.get(requestId);
	if (entry === undefined) {
		return "null";
	}
	commandPromises.delete(requestId);
	const succeeded = ok === 1 || ok === true;

	if (entry.kind === "apicall") {
		if (!succeeded) {
			entry.fail(String(output === undefined ? "" : output));
			return "null";
		}
		let value = null;
		try {
			value = JSON.parse(output);
		} catch (e) {
			value = String(output === undefined ? "" : output);
		}
		entry.settle(value);
		return "null";
	}

	//kind "command": the classic runCommand result object
	if (!succeeded) {
		log("warning", undefined, "runCommand by " + (entry.source || "script") + " failed: " + String(output === undefined ? "" : output));
	}
	entry.settle({ success: succeeded, statusMessage: String(output === undefined ? "" : output) });
	return "null";
};

globalThis.__tosapiEval = (code) => {
	try {
		const result = (0, eval)(String(code));
		return JSON.stringify({ ok: true, result: clip(fmt(result)) });
	} catch (error) {
		return JSON.stringify({ ok: false, result: clip(fmt(error)) });
	}
};

log("info", undefined, "TodiumX Script API host ready (embedded QuickJS via rquickjs 0.9, registry-driven surface)");
