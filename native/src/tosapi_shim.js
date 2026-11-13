"use strict";

/**
 * TodiumX Script API shim, executed inside the embedded QuickJS runtime
 * (raspberry-native, rquickjs) that lives in the native library.
 *
 * The Rust side injects these globals before this file is evaluated:
 *   __tosapiPush(opJson)       queue one op for the PHP server to drain
 *   __tosapiPropsSave(json)    persist dynamic properties (Rust writes the file)
 *   __tosapiInitialProps       the dynamic properties loaded from disk, as a JSON string
 *
 * The Rust side calls these globals:
 *   __tosapiRegisterPack(id, name)         remember a pack for log prefixes
 *   __tosapiSetPack(id)                    set the pack of the code being evaluated
 *   __tosapiOnTick(tick, playersJson)      server tick: timers + player snapshot
 *   __tosapiOnEmit(event, requestId, dataJson) -> JSON response or null (sync beforeEvents)
 *   __tosapiResolveCommand(requestId, ok, output)   resolve a runCommand promise
 *   __tosapiEval(code) -> JSON {ok, result}        /scriptapi eval
 *
 * The `@minecraft/server` module itself is served by the Rust module loader
 * (native/src/script.rs) with a tiny facade that re-exports globalThis.__tosapi.api.
 *
 * Op protocol (see SCRIPT_API.md) is identical to the one PHP handles:
 *   loaded/ready  log  message  playerMessage  command  response  done
 */

"use strict";

/* eslint-disable no-restricted-globals */

const MAX_TEXT = 4096;

/** state */
let currentTick = 0;
let players = [];
let currentPack = null;

/** @type {Map<number, {kind, due, every, fn, pack}>} */
const timers = new Map();
let nextRunId = 1;

/** @type {Map<number, Function>} pending runCommand requests waiting for commandResult */
const commandPromises = new Map();
let nextCommandId = 1;

/** @type {Map<string, {event, pack, callback, options}>} */
const eventHandlers = new Map();
let subscribeId = 1;

/** @type {Map<string, string>} pack id -> name, for log prefixes */
const packNames = new Map();

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
// @minecraft/server objects
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
	return player;
}

function runCommand(sourceName, commandString) {
	const reqId = nextCommandId++;
	const promise = new Promise((resolve) => {
		commandPromises.set(reqId, (result) => {
			if (!result.ok) {
				log("warning", undefined, "runCommand by " + sourceName + " failed: " + result.output);
			}
			resolve({ success: result.ok, statusMessage: result.output });
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
	}
	return container;
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

const world = {
	sendMessage(message, targetPlayers) {
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
	},
	getAllPlayers() {
		return players.map(makePlayer);
	},
	getPlayers(options) {
		const list = players.map(makePlayer);
		if (options && typeof options.name === "string") {
			return list.filter((player) => player.name === options.name);
		}
		return list;
	},
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
	afterEvents: null,
	beforeEvents: null
};
world.afterEvents = makeEventSet("after:", false);
world.beforeEvents = makeEventSet("before:", true);

const MinecraftDimensionTypes = Object.freeze({
	overworld: "overworld",
	nether: "nether",
	theEnd: "the end"
});

function schedule(kind, every, callback) {
	if (typeof callback !== "function") {
		throw new TypeError("callback must be a function");
	}
	const runId = nextRunId++;
	timers.set(runId, { kind, due: currentTick + every, every, fn: callback, pack: currentPack });
	return runId;
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
		if (typeof id !== "string" || id.indexOf(":") < 0) {
			throw new RangeError("script event id must look like 'namespace:event'");
		}
		dispatchEvent("after:scriptEventReceive", { id, message: String(message === undefined ? "" : message) }, false);
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
	scriptEventReceive: {
		subscribe(callback, options) {
			if (typeof callback !== "function") throw new TypeError("callback must be a function");
			const key = "after:scriptEventReceive#" + subscribeId++;
			eventHandlers.set(key, { event: "after:scriptEventReceive", pack: currentPack, callback, options: options || {} });
			return { unsubscribe() { eventHandlers.delete(key); } };
		},
		unsubscribe(callback) {
			for (const [key, entry] of eventHandlers) {
				if (entry.event === "after:scriptEventReceive" && entry.callback === callback) eventHandlers.delete(key);
			}
		}
	}
};

globalThis.__tosapi = {
	api: { world, system, MinecraftDimensionTypes }
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
		default:
			log("warning", undefined, "unknown emit event '" + event + "'");
	}
	return "null";
};

globalThis.__tosapiResolveCommand = (requestId, ok, output) => {
	const resolve = commandPromises.get(requestId);
	if (resolve !== undefined) {
		commandPromises.delete(requestId);
		resolve({ ok: ok === 1 || ok === true, output: String(output === undefined ? "" : output) });
	}
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

log("info", undefined, "TodiumX Script API host ready (embedded QuickJS via rquickjs 0.9)");
