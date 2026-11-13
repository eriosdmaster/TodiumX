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
use function is_int;
use function is_string;
use function strlen;
use function strtolower;
use function substr;

/**
 * Loads the "script" modules of active behavior packs into the Script API host
 * (the embedded QuickJS runtime of the native library, rquickjs 0.9 — no
 * Node.js involved) and bridges server events, ticks and commands between PHP
 * and the scripts.
 *
 * Supported surface (see SCRIPT_API.md): world.beforeEvents.chatSend (cancellable),
 * world.afterEvents.playerJoin/playerLeave/playerSpawn/chatSend, system.run /
 * runTimeout / runInterval / clearRun, system.afterEvents.scriptEventReceive,
 * system.sendScriptEvent, world/Player/Dimension dynamic properties and
 * dimension/player runCommand.
 */
final class ScriptManager{

	private const MAX_SCRIPT_BYTES = 8388608; //8 MiB across all packs, like the official content size guards
	private const MAX_SCRIPT_FILES = 2000;

	private ?NativeScriptHost $host = null;

	private ?string $startError = null;

	/** @var array<string, array{name: string, entry: string, ok: bool, error: ?string}> pack uuid => load state */
	private array $scriptPacks = [];

	private bool $loaded = false;

	public function __construct(
		private BehaviorPackManager $packs,
		private Logger $logger,
		private ScriptApiBridge $bridge,
		private string $dataDir,
		private bool $enabled = true
	){}

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
					"error" => $exception->getMessage()
				];
				$this->logger->error("[ScriptAPI] pack " . $pack->getName() . ": " . $exception->getMessage());
				continue;
			}

			$totalBytes += array_sum(array_map(strlen(...), $files));
			$totalFiles += count($files);
			$candidates[$pack->getUuid()] = [
				"pack" => $pack,
				"entry" => $entry,
				"files" => $files
			];
		}

		if($candidates === []){
			$this->logger->info("[ScriptAPI] no behavior pack has script modules; host not started");
			return 0;
		}

		$host = new NativeScriptHost($this->dataDir);
		$error = null;

		if(!$host->start($error)){
			$this->startError = $error ?? "unknown host error";
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
				"error" => null
			];
			$payload[] = [
				"id" => $uuid,
				"name" => $candidate["pack"]->getName(),
				"entry" => $candidate["entry"],
				"files" => $candidate["files"]
			];
		}

		//the host loads and evaluates synchronously; the ops are queued on return
		$host->loadPacks($payload);
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

			case "response":
				return; //responses are handled by their callers

			case "loaded":
			case "done":
				return;

			default:
				$this->logger->debug("[ScriptAPI] ignoring unknown host op " . (is_string($kind) ? $kind : "?"));
		}
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
	 * @return array{enabled: bool, running: bool, engine: ?string, error: ?string, packs: list<array{uuid: string, name: string, entry: string, ok: bool, error: ?string}>}
	 */
	public function getStatus() : array{
		$packs = [];

		foreach($this->scriptPacks as $uuid => $state){
			$packs[] = [
				"uuid" => $uuid,
				"name" => $state["name"],
				"entry" => $state["entry"],
				"ok" => $state["ok"],
				"error" => $state["error"]
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
