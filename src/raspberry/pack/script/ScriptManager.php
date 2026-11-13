<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use raspberry\native\Native;
use raspberry\pack\BehaviorPack;
use raspberry\pack\BehaviorPackManager;
use raspberry\utils\Logger;

use function array_sum;
use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function strlen;
use function strtolower;
use function substr;

use const JSON_UNESCAPED_SLASHES;

/**
 * Loads the "script" modules of active behavior packs into the Script API host
 * (the embedded QuickJS runtime of the native library, rquickjs 0.9 — no
 * Node.js involved) and bridges server events, ticks, commands and API calls
 * between PHP and the scripts.
 *
 * The @minecraft/server surface is data-driven: ScriptApiRegistry declares
 * every row (path, since version, kind) and the shim rebuilds the module per
 * pack version from the manifest sent along with the pack payload. Adding a
 * new API means adding a registry row (plus a bridge handler when the server
 * has to act) — not touching this class. See SCRIPT_API.md.
 */
final class ScriptManager{

	private const MAX_SCRIPT_BYTES = 8388608; //8 MiB across all packs, like the official content size guards
	private const MAX_SCRIPT_FILES = 2000;

	private ?NativeScriptHost $host = null;

	private ?string $startError = null;

	/** @var array<string, array{name: string, entry: string, ok: bool, error: ?string, version?: ?string}> pack uuid => load state */
	private array $scriptPacks = [];

	private bool $loaded = false;

	public function __construct(
		private BehaviorPackManager $packs,
		private Logger $logger,
		private ScriptApiBridge $bridge,
		private string $dataDir,
		private bool $enabled = true,
		private string $versionSetting = ScriptApiVersion::AUTO,
		private string $versionCheck = ScriptApiVersion::CHECK_WARN
	){}

	/**
	 * The Script API version the server presents: "auto" (every pack runs with the version it declares)
	 * or a fixed version like "1.16.0", the highest @minecraft/server version the server accepts.
	 */
	public function getVersionSetting() : string{
		return $this->versionSetting;
	}

	/**
	 * @return bool false when $version is not "auto" or a version
	 */
	public function setVersionSetting(string $version) : bool{
		$normalized = ScriptApiVersion::normalize($version);

		if($normalized === null){
			return false;
		}

		$this->versionSetting = $normalized;

		return true;
	}

	/**
	 * What happens to a pack that needs a newer version than the server presents: "warn" (log it, load
	 * the pack), "strict" (do not load it) or "off" (say nothing).
	 */
	public function getVersionCheck() : string{
		return $this->versionCheck;
	}

	public function setVersionCheck(string $check) : void{
		$this->versionCheck = ScriptApiVersion::normalizeCheck($check);
	}

	/**
	 * Why a pack that declares $declared cannot run on this server, or null when it can. Only a fixed
	 * version can refuse a pack; "auto" has no limit.
	 */
	private function getVersionProblem(?string $declared) : ?string{
		if($declared === null || $this->versionSetting === ScriptApiVersion::AUTO){
			return null;
		}

		$declaredVersion = ScriptApiVersion::normalize($declared);

		if($declaredVersion === null || $declaredVersion === ScriptApiVersion::AUTO){
			return null;
		}

		if(ScriptApiVersion::compare($declaredVersion, $this->versionSetting) > 0){
			return "needs @minecraft/server " . $declared . ", but the server Script API version is " . $this->versionSetting;
		}

		return null;
	}

	/**
	 * Notes that do not stop a pack from loading: an unreadable version, or APIs the surface may not have.
	 */
	private function getVersionNote(?string $declared) : ?string{
		if($declared === null){
			return null;
		}

		$declaredVersion = ScriptApiVersion::normalize($declared);

		if($declaredVersion === null || $declaredVersion === ScriptApiVersion::AUTO){
			return "declares the @minecraft/server version '" . $declared . "', which is not a version like 1.16.0";
		}

		if(ScriptApiVersion::compare($declaredVersion, ScriptApiVersion::IMPLEMENTED) > 0){
			return "declares @minecraft/server " . $declared . ", newer than the " . ScriptApiVersion::IMPLEMENTED . " this Script API implements; APIs added after it may be missing";
		}

		return null;
	}

	public function isEnabled() : bool{
		return $this->enabled;
	}

	public function isRunning() : bool{
		return $this->host !== null && $this->host->isRunning();
	}

	/**
	 * Discovers script packs among the active behavior packs and boots the host.
	 *
	 * @return int how many script packs were handed to the host
	 */
	public function load() : int{
		if(!$this->enabled){
			return 0;
		}
		$this->stop();

		$candidates = [];
		/** @var array<string, array{name: string, entry: string, ok: bool, error: ?string, version?: ?string}> packs the version check refused */
		$refused = [];
		$totalBytes = 0;
		$totalFiles = 0;

		foreach($this->packs->getActivePacks() as $pack){
			$manifest = $pack->getManifest();
			$entry = $manifest->getScriptEntry();

			if(!$manifest->hasScripts() || $entry === null){
				continue;
			}

			try{
				$files = $this->readPackScripts($pack, $totalBytes, $totalFiles);
			}catch(ScriptException $exception){
				$this->scriptPacks[$pack->getUuid()] = [
					"name" => $pack->getName(),
					"entry" => $entry,
					"ok" => false,
					"error" => $exception->getMessage(),
					"version" => $manifest->getScriptModuleVersions()["@minecraft/server"] ?? null
				];
				$this->logger->error("[ScriptAPI] pack " . $pack->getName() . ": " . $exception->getMessage());
				continue;
			}

			$declared = $manifest->getScriptModuleVersions()["@minecraft/server"] ?? null;

			if($this->versionCheck !== ScriptApiVersion::CHECK_OFF){
				$problem = $this->getVersionProblem($declared);

				if($problem !== null && $this->versionCheck === ScriptApiVersion::CHECK_STRICT){
					$refused[$pack->getUuid()] = [
						"name" => $pack->getName(),
						"entry" => $entry,
						"ok" => false,
						"error" => "pack " . $problem,
						"version" => $declared
					];
					$this->logger->error("[ScriptAPI] pack " . $pack->getName() . " " . $problem . " (script-api-version-check=strict, pack not loaded)");
					continue;
				}

				$problem ??= $this->getVersionNote($declared);

				if($problem !== null){
					$this->logger->warning("[ScriptAPI] pack " . $pack->getName() . " " . $problem);
				}
			}

			$totalBytes += array_sum(array_map(strlen(...), $files));
			$totalFiles += count($files);
			$candidates[$pack->getUuid()] = [
				"pack" => $pack,
				"entry" => $entry,
				"files" => $files,
				"version" => $declared
			];
		}

		if($candidates !== [] && $this->versionSetting !== ScriptApiVersion::AUTO && $this->versionCheck !== ScriptApiVersion::CHECK_OFF
			&& ScriptApiVersion::compare($this->versionSetting, ScriptApiVersion::IMPLEMENTED) > 0){
			$this->logger->warning("[ScriptAPI] script-api-version " . $this->versionSetting . " is newer than the " . ScriptApiVersion::IMPLEMENTED . " this Script API implements; packs are accepted up to that version, but APIs added after " . ScriptApiVersion::IMPLEMENTED . " do not exist");
		}

		if($candidates === []){
			$this->scriptPacks += $refused;
			$this->logger->info("[ScriptAPI] no behavior pack has script modules; host not started");
			return 0;
		}

		$host = new NativeScriptHost($this->dataDir);
		$error = null;

		if(!$host->start($error)){
			$this->startError = $error ?? "unknown host error";
			$this->scriptPacks += $refused;
			$this->logger->warning("[ScriptAPI] " . $this->startError . " Script packs: " . count($candidates) . " (not loaded).");
			return 0;
		}

		$this->host = $host;
		$this->scriptPacks = [];

		$payload = [];
		foreach($candidates as $uuid => $candidate){
			$this->scriptPacks[$uuid] = [
				"name" => $candidate["pack"]->getName(),
				"entry" => $candidate["entry"],
				"ok" => false,
				"error" => null,
				"version" => $candidate["version"]
			];
			$payload[] = [
				"id" => $uuid,
				"name" => $candidate["pack"]->getName(),
				"entry" => $candidate["entry"],
				"version" => $candidate["version"],
				"files" => $candidate["files"]
			];
		}

		//the host loads and evaluates synchronously; the ops are queued on return.
		//the API manifest rides along: the shim rebuilds the @minecraft/server
		//module per pack version from it (ScriptApiRegistry is the only place
		//APIs are declared)
		$host->loadPacks($payload, ScriptApiRegistry::manifest());
		foreach($host->drain() as $op){
			$this->waitLoadOp($op);
		}
		$this->loaded = true;

		$ok = 0;
		foreach($this->scriptPacks as $state){
			if($state["ok"]){
				$ok++;
			}elseif(is_string($state["error"])){
				$this->logger->error("[ScriptAPI] pack " . $state["name"] . ": " . $state["error"]);
			}
		}
		$this->logger->info("[ScriptAPI] loaded " . $ok . "/" . count($this->scriptPacks) . " script pack(s)");
		$this->scriptPacks += $refused;

		return $ok;
	}

	/**
	 * @return array<string, string> relative path => source code
	 */
	private function readPackScripts(BehaviorPack $pack, int $bytesSoFar, int $filesSoFar) : array{
		$files = [];
		foreach($pack->listPackFiles("scripts/") as $relative){
			$contents = $pack->readContentFile($relative);
			if(strtolower(substr($relative, -3)) !== ".js"){
				continue; //non-script files under scripts/ are allowed but not shipped to the host
			}
			$files[$relative] = $contents;
		}

		if($files === []){
			throw new ScriptException("manifest declares a script module but no .js file under scripts/ exists");
		}
		$entry = $pack->getManifest()->getScriptEntry();
		if($entry !== null && !isset($files[$entry])){
			throw new ScriptException("script entry '" . $entry . "' does not exist in the pack");
		}

		$bytes = array_sum(array_map(strlen(...), $files));
		if($bytesSoFar + $bytes > self::MAX_SCRIPT_BYTES){
			throw new ScriptException("script sources exceed the " . intdiv(self::MAX_SCRIPT_BYTES, 1048576) . " MiB limit (" . $bytes . " bytes in this pack)");
		}
		if($filesSoFar + count($files) > self::MAX_SCRIPT_FILES){
			throw new ScriptException("too many script files (limit " . self::MAX_SCRIPT_FILES . ")");
		}

		return $files;
	}

	private function waitLoadOp(array $op) : void{
		$kind = $op["op"] ?? null;

		if($kind === "loaded" && is_string($op["id"] ?? null) && isset($this->scriptPacks[$op["id"]])){
			$this->scriptPacks[$op["id"]]["ok"] = $op["ok"] === true;
			$this->scriptPacks[$op["id"]]["error"] = is_string($op["error"] ?? null) ? $op["error"] : null;
		}else{
			$this->handleOp($op);
		}
	}

	/**
	 * Called from Server::tick(): advances script timers, sends the fresh player
	 * snapshot and processes everything the host produced (logs, messages, commands).
	 */
	public function tick(int $serverTick) : void{
		$host = $this->host;

		if($host === null || !$host->isRunning()){
			return;
		}

		$host->tick($serverTick, $this->bridge->getOnlinePlayers());

		foreach($host->drain() as $op){
			$this->handleOp($op);
		}
	}

	private function handleOp(array $op) : void{
		$kind = $op["op"] ?? null;

		switch($kind){
			case "log":
				$level = is_string($op["level"] ?? null) ? $op["level"] : "info";
				$prefix = "[ScriptAPI" . (is_string($op["pack"] ?? null) ? " " . $op["pack"] : "") . "] ";
				match($level){
					"error" => $this->logger->error($prefix . ($op["text"] ?? "")),
					"warning" => $this->logger->warning($prefix . ($op["text"] ?? "")),
					"debug" => $this->logger->debug($prefix . ($op["text"] ?? "")),
					default => $this->logger->info($prefix . ($op["text"] ?? ""))
				};
				return;

			case "message":
				$text = is_string($op["text"] ?? null) ? $op["text"] : "";
				if($text !== ""){
					$this->bridge->broadcastMessage($text);
				}
				return;

			case "playerMessage":
				if(is_int($op["player"] ?? null) && is_string($op["text"] ?? null)){
					$this->bridge->sendPlayerMessage($op["player"], $op["text"]);
				}
				return;

			case "command":
				$this->executeScriptCommand(
					is_int($op["reqId"] ?? null) ? $op["reqId"] : 0,
					is_string($op["cmd"] ?? null) ? $op["cmd"] : "",
					is_string($op["source"] ?? null) ? $op["source"] : "script"
				);
				return;

			case "apicall":
				$this->dispatchApiCall(
					is_int($op["reqId"] ?? null) ? $op["reqId"] : 0,
					is_string($op["path"] ?? null) ? $op["path"] : "",
					is_array($op["args"] ?? null) ? $op["args"] : []
				);
				return;

			case "response":
				return; //responses are handled by their callers

			case "loaded":
			case "done":
				return;

			default:
				$this->logger->debug("[ScriptAPI] ignoring unknown host op " . (is_string($kind) ? $kind : "?"));
		}
	}

	/**
	 * Handles one "apicall" op from the shim: dispatches the registry path to
	 * the bridge and resolves the script's promise through the command-result
	 * channel. The promise resolves with the JSON-decoded return value, or
	 * rejects with the error message. Bridge calls therefore settle on the
	 * next tick, like runCommand().
	 *
	 * @param list<mixed> $args
	 */
	private function dispatchApiCall(int $reqId, string $path, array $args) : void{
		if($reqId <= 0 || $path === ""){
			return;
		}

		try{
			$result = match(true){
				$path === "world.getEntity" => $this->bridge->getEntityById(self::intArg($args, 0)),
				$path === "world.getEntities" => $this->bridge->getEntities(),
				$path === "world.getBlock" => $this->bridge->getBlockInfo(self::intArg($args, 0), self::intArg($args, 1), self::intArg($args, 2)),
				$path === "player.teleport" => $this->bridge->teleportEntity(
					self::intArg($args, 0),
					self::floatArg($args, 1),
					self::floatArg($args, 2),
					self::floatArg($args, 3),
					is_string($args[4] ?? null) ? $args[4] : null
				),
				preg_match('/^world\.scoreboard\.([A-Za-z]+)$/', $path, $match) === 1
					=> $this->bridge->scoreboardOperation($match[1], $args),
				default => throw new ScriptException("no bridge handler for '" . $path . "'")
			};
			$this->host?->commandResult($reqId, true, json_encode($result, JSON_UNESCAPED_SLASHES));
		}catch(\Throwable $exception){
			$this->logger->error("[ScriptAPI] " . $path . " failed: " . $exception::class . ": " . $exception->getMessage());
			$this->host?->commandResult($reqId, false, $exception->getMessage());
		}
	}

	private static function intArg(array $args, int $index) : int{
		$value = $args[$index] ?? 0;

		return is_int($value) || is_float($value) ? (int) $value : 0;
	}

	private static function floatArg(array $args, int $index) : float{
		$value = $args[$index] ?? 0;

		return is_int($value) || is_float($value) ? (float) $value : 0.0;
	}

	private function executeScriptCommand(int $reqId, string $commandLine, string $source) : void{
		if($reqId <= 0){
			return;
		}

		if($commandLine === ""){
			$this->host?->commandResult($reqId, false, "empty command");
			return;
		}

		$sender = new ScriptCommandSender($source, $this->logger, $this->bridge->getPermissionManager());

		try{
			$ok = $this->bridge->executeCommandLine($commandLine, $sender);
		}catch(\Throwable $exception){
			$this->logger->error("[ScriptAPI] command '" . $commandLine . "' crashed: " . $exception::class . ": " . $exception->getMessage());
			$ok = false;
		}

		$this->host?->commandResult($reqId, $ok, $sender->collectOutput());
	}

	// ---------------------------------------------------------------------------
	// events PHP -> JS (players are snapshots: {id, name, x, y, z, dimension})
	// ---------------------------------------------------------------------------

	public function emitPlayerJoin(array $player) : void{
		$this->emit("playerJoin", $player);
	}

	public function emitPlayerLeave(array $player) : void{
		$this->emit("playerLeave", $player);
	}

	public function emitChatAfter(array $player, string $message) : void{
		$this->emit("chatSend", [
			"player" => $player,
			"message" => $message
		]);
	}

	/**
	 * Synchronous path for world.beforeEvents.chatSend: runs the script handlers
	 * and reports whether any of them cancelled the message.
	 */
	public function emitChatBefore(array $player, string $message) : bool{
		$host = $this->host;

		if($host === null || !$host->isRunning()){
			return false;
		}

		$response = $host->emit("beforeChatSend", [
			"player" => $player,
			"message" => $message
		]);

		return is_array($response) && ($response["cancelled"] ?? false) === true;
	}

	public function emitScriptEvent(string $id, string $message) : void{
		$this->emit("scriptEvent", ["id" => $id, "message" => $message]);
	}

	/**
	 * Fires world.afterEvents.playerBreakBlock (ScriptEventSink, from BlockBreaker).
	 */
	public function emitPlayerBreakBlock(array $player, string $blockType, int $x, int $y, int $z) : void{
		$this->emit("playerBreakBlock", [
			"player" => $player,
			"block" => $blockType,
			"x" => $x,
			"y" => $y,
			"z" => $z,
			"dimension" => $player["dimension"] ?? null
		]);
	}

	/**
	 * Fires world.afterEvents.playerPlaceBlock (ScriptEventSink, from BlockPlacer).
	 */
	public function emitPlayerPlaceBlock(array $player, string $blockType, int $x, int $y, int $z) : void{
		$this->emit("playerPlaceBlock", [
			"player" => $player,
			"block" => $blockType,
			"x" => $x,
			"y" => $y,
			"z" => $z,
			"dimension" => $player["dimension"] ?? null
		]);
	}

	private function emit(string $event, array $data) : void{
		$this->host?->emit($event, $data);
	}

	// ---------------------------------------------------------------------------
	// eval (needs the pack capability "script_eval")
	// ---------------------------------------------------------------------------

	public function hasEvalCapability() : bool{
		foreach($this->packs->getActivePacks() as $pack){
			if(in_array("script_eval", $pack->getManifest()->getCapabilities(), true)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluates JS in the host's shared context; returns [ok, result].
	 *
	 * @return array{0: bool, 1: string}
	 */
	public function evalScript(string $code) : array{
		$host = $this->host;

		if($host === null || !$host->isRunning()){
			return [false, "the Script API host is not running"];
		}

		$response = $host->evalCode($code);

		if(!is_array($response)){
			return [false, "the Script API host did not answer"];
		}

		return [$response["ok"] === true, is_string($response["result"] ?? null) ? $response["result"] : ""];
	}

	// ---------------------------------------------------------------------------
	// lifecycle
	// ---------------------------------------------------------------------------

	public function stop() : void{
		$this->loaded = false;
		$this->host?->stop();
		$this->host = null;
	}

	public function reload() : int{
		return $this->load();
	}

	/**
	 * @return array{enabled: bool, running: bool, engine: ?string, error: ?string, packs: list<array{uuid: string, name: string, entry: string, ok: bool, error: ?string, version: ?string}>}
	 */
	public function getStatus() : array{
		$packs = [];

		foreach($this->scriptPacks as $uuid => $state){
			$packs[] = [
				"uuid" => $uuid,
				"name" => $state["name"],
				"entry" => $state["entry"],
				"ok" => $state["ok"],
				"error" => $state["error"],
				"version" => $state["version"] ?? null
			];
		}

		return [
			"enabled" => $this->enabled,
			"running" => $this->isRunning(),
			"engine" => Native::isLoaded() ? "embedded QuickJS (rquickjs 0.9)" : null,
			"error" => $this->startError,
			"packs" => $packs
		];
	}
}
