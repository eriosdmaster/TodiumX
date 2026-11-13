<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use raspberry\native\Native;

use function explode;
use function is_array;
use function json_decode;
use function json_encode;
use function trim;

use const JSON_UNESCAPED_SLASHES;

/**
 * The PHP side of the Script API host: a thin FFI wrapper over the embedded
 * QuickJS runtime in the native library (rquickjs 0.9, no Node.js involved).
 *
 * The protocol is the same op stream the node host used (see SCRIPT_API.md):
 * PHP pushes packs / ticks / events in, scripts queue ops back, and everything
 * the scripts produced arrives through drain().
 */
final class NativeScriptHost{

	private int $handle = 0;

	public function __construct(
		private string $dataDir
	){}

	public function isRunning() : bool{
		return $this->handle > 0;
	}

	/**
	 * Creates the embedded runtime. A false return leaves a reason in $error.
	 */
	public function start(?string &$error = null) : bool{
		if($this->handle > 0){
			return true;
		}

		if(!Native::isLoaded()){
			$error = "the native library is not loaded (" . (Native::getError() ?? "unknown reason") . "). Build it with: php build/make-native.php";
			return false;
		}

		$handle = Native::apiHostNew($this->dataDir);
		if($handle <= 0){
			$error = "cannot create the embedded QuickJS runtime";
			return false;
		}

		$this->handle = $handle;
		return true;
	}

	/**
	 * Hands every pack's script sources to the host at once, together with the
	 * Script API manifest (ScriptApiRegistry) the shim builds the API surface
	 * from. Packs carry their declared @minecraft/server version in "version".
	 *
	 * @param list<array{id: string, name: string, entry: string, version?: ?string, files: array<string, string>}> $packs
	 * @param array{maxVersion: string, surfaces: list<array<string, mixed>>} $apiManifest
	 */
	public function loadPacks(array $packs, array $apiManifest = []) : bool{
		if($this->handle <= 0 || $packs === []){
			return false;
		}

		if($apiManifest === []){
			//old native library without manifest support: plain array payload
			return Native::apiHostLoad($this->handle, json_encode($packs, JSON_UNESCAPED_SLASHES));
		}

		$payload = ["packs" => $packs, "api" => $apiManifest];

		return Native::apiHostLoad($this->handle, json_encode($payload, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Server tick: advances script timers and refreshes the player snapshot.
	 */
	public function tick(int $tick, array $players) : bool{
		if($this->handle <= 0){
			return false;
		}

		return Native::apiHostTick($this->handle, $tick, json_encode($players, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Delivers an event to the scripts. beforeEvents are synchronous: the
	 * response array comes straight back (e.g. ["cancelled" => true]).
	 *
	 * @return array<string, mixed>|null
	 */
	public function emit(string $event, array $data, ?int $requestId = null) : ?array{
		if($this->handle <= 0){
			return null;
		}

		$response = Native::apiHostEmit($this->handle, $event, $requestId ?? 0, json_encode($data, JSON_UNESCAPED_SLASHES));

		return is_array($response) ? $response : null;
	}

	/**
	 * Evaluates JS in the host context (/scriptapi eval). Returns ["ok" => bool, "result" => string].
	 *
	 * @return array{ok: bool, result: string}|null
	 */
	public function evalCode(string $code) : ?array{
		if($this->handle <= 0){
			return null;
		}

		$response = Native::apiHostEval($this->handle, $code);

		return is_array($response) ? $response : null;
	}

	/**
	 * Resolves a runCommand promise the scripts are waiting on.
	 */
	public function commandResult(int $requestId, bool $ok, string $output) : void{
		if($this->handle <= 0 || $requestId <= 0){
			return;
		}

		Native::apiHostCommandResult($this->handle, $requestId, $ok, $output);
	}

	/**
	 * Reads everything the scripts queued since the last call, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function drain() : array{
		if($this->handle <= 0){
			return [];
		}

		$batch = Native::apiHostDrain($this->handle);
		if($batch === null || $batch === ""){
			return [];
		}

		$ops = [];
		foreach(explode("\n", $batch) as $line){
			$line = trim($line);
			if($line === ""){
				continue;
			}
			$op = json_decode($line, true);
			if(is_array($op)){
				$ops[] = $op;
			}
		}

		return $ops;
	}

	public function stop() : void{
		if($this->handle <= 0){
			return;
		}

		Native::apiHostFree($this->handle);
		$this->handle = 0;
	}

	public function __destruct(){
		$this->stop();
	}
}
