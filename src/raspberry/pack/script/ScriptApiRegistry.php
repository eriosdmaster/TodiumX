<?php

declare(strict_types=1);

namespace raspberry\pack\script;

/**
 * The data table that drives the whole Script API surface.
 *
 * Every @minecraft/server surface this server offers is ONE row here. The rows
 * travel to the embedded QuickJS host as a manifest (see ScriptManager::load()),
 * and the shim builds the per-version API objects from that manifest — nothing
 * in the shim or in the Rust library is written per API. Adding a new API is:
 *
 *   1. one row in self::SURFACES below,
 *   2. if the API needs the server to do something, a handler for its path in
 *      ScriptManager::dispatchApiCall() plus a method on ScriptApiBridge /
 *      ServerScriptBridge,
 *   3. (events only) the place on the server that emits the event.
 *
 * Kinds:
 *   local  – implemented by the shim itself (timers, dynamic properties, …)
 *   bridge – the shim queues an "apicall" op; ScriptManager dispatches it to the
 *            ScriptApiBridge and resolves the script's promise with the JSON result
 *   event  – a subscription point; the server feeds it through NativeScriptHost::emit()
 *   getter – a property (read each time), e.g. system.currentTick
 *   const  – a constant value exported by the module
 *   stub   – a name the API officially has but this server does not implement;
 *            calling/subscribing logs a warning instead of throwing an ImportError
 *
 * "since" is the first @minecraft/server version the row exists in; a pack that
 * declares an older version does not see the row at all (its module is built
 * without it — the version decides the surface, automatically).
 */
final class ScriptApiRegistry{

	public const KIND_LOCAL = "local";
	public const KIND_BRIDGE = "bridge";
	public const KIND_EVENT = "event";
	public const KIND_GETTER = "getter";
	public const KIND_CONST = "const";
	public const KIND_STUB = "stub";

	/**
	 * The version this registry implements in full. Packs may declare newer
	 * versions (they load with a warning), but rows newer than this do not exist.
	 */
	public const MAX_VERSION = "1.16.0";

	/**
	 * @var list<array{path: string, since: string, kind: string, event?: string, phase?: string, value?: mixed}>
	 */
	private const SURFACES = [
		// --- module constants -------------------------------------------------
		["path" => "TicksPerSecond", "since" => "1.0.0", "kind" => self::KIND_CONST, "value" => 20],
		["path" => "MinecraftDimensionTypes", "since" => "1.0.0", "kind" => self::KIND_CONST, "value" => "@MinecraftDimensionTypes"],

		// --- world ------------------------------------------------------------
		["path" => "world.sendMessage", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "world.getAllPlayers", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "world.getPlayers", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "world.getDimension", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "world.getDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "world.setDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "world.clearDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "world.getEntity", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.getEntities", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.getBlock", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],

		// --- world.scoreboard (vanilla shape, backed by ScoreboardsAPI) -------
		["path" => "world.scoreboard.getObjective", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.getObjectives", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.addObjective", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.removeObjective", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.getScore", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.setScore", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.addScore", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "world.scoreboard.removeScore", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],

		// --- world.afterEvents --------------------------------------------------
		["path" => "world.afterEvents.playerJoin", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "playerJoin", "phase" => "after"],
		["path" => "world.afterEvents.playerLeave", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "playerLeave", "phase" => "after"],
		["path" => "world.afterEvents.playerSpawn", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "playerSpawn", "phase" => "after"],
		["path" => "world.afterEvents.chatSend", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "chatSend", "phase" => "after"],
		["path" => "world.afterEvents.playerSendMessage", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "chatSend", "phase" => "after"],
		["path" => "world.afterEvents.playerBreakBlock", "since" => "1.16.0", "kind" => self::KIND_EVENT, "event" => "playerBreakBlock", "phase" => "after"],
		["path" => "world.afterEvents.playerPlaceBlock", "since" => "1.16.0", "kind" => self::KIND_EVENT, "event" => "playerPlaceBlock", "phase" => "after"],
		["path" => "world.afterEvents.worldLoad", "since" => "1.16.0", "kind" => self::KIND_EVENT, "event" => "worldLoad", "phase" => "after"],

		// --- world.beforeEvents -------------------------------------------------
		["path" => "world.beforeEvents.chatSend", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "chatSend", "phase" => "before"],
		["path" => "world.beforeEvents.playerSendMessage", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "chatSend", "phase" => "before"],

		// --- system -------------------------------------------------------------
		["path" => "system.currentTick", "since" => "1.0.0", "kind" => self::KIND_GETTER],
		["path" => "system.run", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "system.runTimeout", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "system.runInterval", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "system.clearRun", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "system.sendScriptEvent", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "system.afterEvents.scriptEventReceive", "since" => "1.0.0", "kind" => self::KIND_EVENT, "event" => "scriptEventReceive", "phase" => "after"],
		["path" => "system.beforeEvents.startup", "since" => "1.0.0", "kind" => self::KIND_STUB],

		// --- player / dimension wrappers (documentation of the local entity set;
		// entity objects always expose the full local set, see SCRIPT_API.md) ---
		["path" => "player.sendMessage", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "player.runCommand", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "player.runCommandAsync", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "player.location", "since" => "1.0.0", "kind" => self::KIND_GETTER],
		["path" => "player.dimension", "since" => "1.0.0", "kind" => self::KIND_GETTER],
		["path" => "player.getDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "player.setDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "player.clearDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "player.teleport", "since" => "1.16.0", "kind" => self::KIND_BRIDGE],
		["path" => "player.getGameMode", "since" => "1.16.0", "kind" => self::KIND_LOCAL],
		["path" => "player.rotation", "since" => "1.16.0", "kind" => self::KIND_GETTER],
		["path" => "player.addTag", "since" => "1.16.0", "kind" => self::KIND_LOCAL],
		["path" => "player.getTags", "since" => "1.16.0", "kind" => self::KIND_LOCAL],
		["path" => "player.removeTag", "since" => "1.16.0", "kind" => self::KIND_LOCAL],
		["path" => "player.clearTags", "since" => "1.16.0", "kind" => self::KIND_LOCAL],
		["path" => "dimension.getPlayers", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "dimension.runCommand", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "dimension.runCommandAsync", "since" => "1.0.0", "kind" => self::KIND_LOCAL],
		["path" => "dimension.getDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "dimension.setDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
		["path" => "dimension.clearDynamicProperty", "since" => "1.1.0", "kind" => self::KIND_LOCAL],
	];

	private function __construct(){
	}

	/**
	 * Every row of the registry, newest last.
	 *
	 * @return list<array{path: string, since: string, kind: string, event?: string, phase?: string, value?: mixed}>
	 */
	public static function surfaces() : array{
		return self::SURFACES;
	}

	/**
	 * The rows a pack that declares $version gets. Unknown/future versions are
	 * capped at MAX_VERSION: the surface never grows beyond what the server has.
	 *
	 * @return list<array{path: string, since: string, kind: string, event?: string, phase?: string, value?: mixed}>
	 */
	public static function surfacesFor(string $version) : array{
		$effective = self::cap($version);
		$result = [];

		foreach(self::SURFACES as $row){
			if(ScriptApiVersion::compare($row["since"], $effective) <= 0){
				$result[] = $row;
			}
		}

		return $result;
	}

	/**
	 * The manifest handed to the script host: the full table plus the highest
	 * version any row needs. The shim rebuilds the API objects per pack version
	 * from this alone.
	 *
	 * @return array{maxVersion: string, surfaces: list<array<string, mixed>>}
	 */
	public static function manifest() : array{
		return [
			"maxVersion" => self::MAX_VERSION,
			"surfaces" => self::SURFACES
		];
	}

	/**
	 * Versions above MAX_VERSION collapse onto it; anything that is not a real
	 * version falls back to MAX_VERSION as well (the shim only gets asked with
	 * normalized strings, but stay safe).
	 */
	private static function cap(string $version) : string{
		$normalized = ScriptApiVersion::normalize($version) ?? self::MAX_VERSION;

		return ScriptApiVersion::compare($normalized, self::MAX_VERSION) > 0 ? self::MAX_VERSION : $normalized;
	}
}
