<?php

declare(strict_types=1);

/**
 * Script API test suite for TodiumX.
 * Run: php tools/test_script_api.php
 *
 * Uses the real PackManifest, BehaviorPack, BehaviorPackManager and ScriptManager
 * classes with a fake ScriptApiBridge, and drives the real embedded QuickJS host
 * from the native library (rquickjs 0.9). The runtime part is skipped with a
 * notice when the library is not built yet.
 */

error_reporting(E_ALL);

const PROJECT = __DIR__ . "/..";

spl_autoload_register(static function(string $class) : void{
	$prefix = "raspberry\\";
	if(str_starts_with($class, $prefix)){
		$path = PROJECT . "/src/raspberry/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
		if(is_file($path)){
			require $path;
		}
	}
});

use raspberry\lang\Language;
use raspberry\native\Native;
use raspberry\pack\BehaviorPackManager;
use raspberry\pack\script\ScriptApiBridge;
use raspberry\pack\script\ScriptApiRegistry;
use raspberry\pack\script\ScriptApiVersion;
use raspberry\pack\script\ScriptCommandSender;
use raspberry\pack\script\ScriptManager;
use raspberry\pack\script\ServerScriptBridge;
use raspberry\permission\PermissionManager;
use raspberry\utils\Logger;

$failures = 0;
$tests = 0;

function check(bool $condition, string $name) : void{
	global $failures, $tests;
	$tests++;
	if($condition){
		echo "  ok  {$name}\n";
	}else{
		$failures++;
		echo " FAIL {$name}\n";
	}
}

/**
 * Records everything a script asks the server to do, without a real server.
 */
final class FakeBridge implements ScriptApiBridge{
	/** @var list<string> */
	public array $broadcasts = [];
	/** @var list<string> */
	public array $executedCommands = [];
	/** @var array<int, list<string>> player id => messages */
	public array $playerMessages = [];
	/** @var list<array{id: int, name: string, x: float, y: float, z: float, dimension: string}> */
	public array $players = [];
	/** @var list<array{0: int, 1: float, 2: float, 3: float, 4: ?string}> */
	public array $teleports = [];
	/** @var list<array{method: string, args: list<mixed>}> */
	public array $scoreboardOps = [];

	public function broadcastMessage(string $message) : int{
		$this->broadcasts[] = $message;
		return count($this->players);
	}

	public function sendPlayerMessage(int $playerId, string $message) : bool{
		$this->playerMessages[$playerId][] = $message;
		return isset($this->playerMessages[$playerId]);
	}

	public function getOnlinePlayers() : array{
		return $this->players;
	}

	public function executeCommandLine(string $commandLine, ScriptCommandSender $sender) : bool{
		$this->executedCommands[] = $commandLine;
		$sender->sendMessage("ran: " . $commandLine);
		return true;
	}

	public function getCurrentTick() : int{
		return 0;
	}

	public function getPermissionManager() : PermissionManager{
		return new PermissionManager();
	}

	public function getEntityById(int $entityId) : ?array{
		foreach($this->players as $player){
			if($player["id"] === $entityId){
				return $player + ["type" => "minecraft:player"];
			}
		}
		return null;
	}

	public function getEntities() : array{
		$out = [];
		foreach($this->players as $player){
			$out[] = $player + ["type" => "minecraft:player"];
		}
		return $out;
	}

	public function teleportEntity(int $entityId, float $x, float $y, float $z, ?string $dimension) : bool{
		$this->teleports[] = [$entityId, $x, $y, $z, $dimension];
		return true;
	}

	public function getBlockInfo(int $x, int $y, int $z) : ?array{
		return ["type" => "minecraft:stone", "location" => ["x" => $x, "y" => $y, "z" => $z]];
	}

	public function scoreboardOperation(string $method, array $args) : mixed{
		$this->scoreboardOps[] = ["method" => $method, "args" => $args];
		return match($method){
			"getScore" => 42,
			"addScore" => 7,
			"getObjective" => null,
			"getObjectives" => [],
			"addObjective", "removeObjective", "setScore", "removeScore" => true,
			default => throw new \InvalidArgumentException("unknown scoreboard operation '" . $method . "'")
		};
	}
}

function broadcastText(array $broadcasts) : string{
	return implode("\n", $broadcasts);
}

/**
 * The real server calls tick() every 50ms, so an op sent now is answered by the
 * next tick; the test pumps ticks the same way before checking the results.
 */
function pump(ScriptManager $scriptManager, int $fromTick, int $toTick, int $stepMs = 10) : void{
	for($tick = $fromTick; $tick <= $toTick; $tick++){
		$scriptManager->tick($tick);
		usleep($stepMs * 1000);
	}
}

echo "TodiumX Script API test suite\n";
echo "=============================\n";

$logger = new Logger();
$language = new Language(Language::FALLBACK);

// ---------------------------------------------------------------------------
// 1) pack discovery and script file listing (folder + archive packs)
// ---------------------------------------------------------------------------
$tempDir = sys_get_temp_dir() . "/todiumx_scriptapi_test_" . getmypid();
@mkdir($tempDir . "/behavior_packs", 0777, true);
@mkdir($tempDir . "/script_data");

$exampleSource = PROJECT . "/behavior_packs/ExampleScriptAPIBP";
if(!is_dir($exampleSource)){
	die("Example pack missing: {$exampleSource}\n");
}
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($exampleSource, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY) as $file){
	$target = $tempDir . "/behavior_packs/ExampleScriptAPIBP/" . substr($file->getPathname(), strlen($exampleSource) + 1);
	@mkdir(dirname($target), 0777, true);
	copy($file->getPathname(), $target);
}

$manager = new BehaviorPackManager(
	$tempDir . "/behavior_packs",
	$tempDir . "/behavior_packs.json",
	$language,
	$logger
);
$manager->load();

check(count($manager->getActivePacks()) === 1, "example script pack auto-activates");
check($manager->hasScriptPacks(), "hasScriptPacks() detects the script module");

$pack = $manager->getActivePacks()[0];
$scriptFiles = $pack->listPackFiles("scripts/");
check($scriptFiles === ["scripts/main.js"], "listPackFiles() finds scripts in folder packs");
check($pack->getManifest()->getScriptEntry() === "scripts/main.js", "manifest script entry parsed");

//same pack delivered as a .zip archive (what .mcpack is); needs ext-zip
if(class_exists("ZipArchive")){
	$zip = new ZipArchive();
	$archivePath = $tempDir . "/ExampleScriptAPIBP.mcpack";
	check($zip->open($archivePath, ZipArchive::CREATE) === true, "create test .mcpack archive");
	$zip->addEmptyDir("ExampleScriptAPIBP");
	foreach(["manifest.json", "scripts/main.js"] as $file){
		$zip->addFile($exampleSource . "/" . $file, "ExampleScriptAPIBP/" . $file);
	}
	$zip->close();

	$archiveManager = new BehaviorPackManager(
		$tempDir . "/archives",
		$tempDir . "/archives.json",
		$language,
		$logger
	);
	@mkdir($tempDir . "/archives");
	copy($archivePath, $tempDir . "/archives/ExampleScriptAPIBP.mcpack");
	$archiveManager->load();
	$archivePacks = $archiveManager->getActivePacks();
	check(count($archivePacks) === 1, ".mcpack script pack auto-activates");
	check($archivePacks !== [] && $archivePacks[0]->listPackFiles("scripts/") === ["scripts/main.js"], "listPackFiles() finds scripts in archive packs");
}else{
	echo " SKIP archive pack checks (this PHP lacks ext-zip)\n";
}

// ---------------------------------------------------------------------------
// 1b) Script API versions (pure PHP, no native library needed)
// ---------------------------------------------------------------------------
echo "Script API version:\n";
check(ScriptApiVersion::normalize("1.16.0") === "1.16.0", "normalize keeps a plain version");
check(ScriptApiVersion::normalize("1.16") === "1.16.0", "normalize completes a two part version");
check(ScriptApiVersion::normalize("v2.0.0-beta") === "2.0.0-beta", "normalize drops the v and keeps the label");
check(ScriptApiVersion::normalize([1, 17, 0]) === "1.17.0", "normalize accepts a [1, 17, 0] array");
check(ScriptApiVersion::normalize("AUTO") === "auto", "normalize accepts auto in any case");
check(ScriptApiVersion::normalize("abc") === null && ScriptApiVersion::normalize("1") === null && ScriptApiVersion::normalize("") === null, "normalize refuses things that are not versions");
check(ScriptApiVersion::compare("1.16.0", "1.16.0") === 0, "equal versions compare as 0");
check(ScriptApiVersion::compare("1.17.0", "1.16.0") > 0 && ScriptApiVersion::compare("1.9.0", "1.16.0") < 0, "minor versions compare as numbers");
check(ScriptApiVersion::compare("2.0.0", "1.99.99") > 0, "a major version beats any minor");
check(ScriptApiVersion::compare("1.17.0-beta", "1.17.0") < 0 && ScriptApiVersion::compare("1.17.0-beta", "1.16.0") > 0, "x.y.z-beta is between the previous release and x.y.z");
check(ScriptApiVersion::normalizeCheck("STRICT") === "strict" && ScriptApiVersion::normalizeCheck("nope") === "warn", "normalizeCheck falls back to warn");

$versionTemp = sys_get_temp_dir() . "/todiumx_version_" . bin2hex(random_bytes(4));
@mkdir($versionTemp . "/behavior_packs/NewPack/scripts", 0777, true);
file_put_contents($versionTemp . "/behavior_packs/NewPack/manifest.json", json_encode([
	"format_version" => 2,
	"header" => ["name" => "New Pack", "uuid" => "b7e1a2c4-1111-4a2b-9c3d-0a1b2c3d4e5f", "version" => [1, 0, 0], "min_engine_version" => [1, 20, 0]],
	"modules" => [["type" => "script", "language" => "javascript", "uuid" => "c8f2b3d5-2222-4b3c-8d4e-1b2c3d4e5f60", "version" => [1, 0, 0], "entry" => "scripts/main.js"]],
	"dependencies" => [["module_name" => "@minecraft/server", "version" => "2.0.0-beta"]]
]));
file_put_contents($versionTemp . "/behavior_packs/NewPack/scripts/main.js", "import { world } from '@minecraft/server';\n");
$versionManifest = \raspberry\pack\PackManifest::fromFile($versionTemp . "/behavior_packs/NewPack/manifest.json");
check(($versionManifest->getScriptModuleVersions()["@minecraft/server"] ?? null) === "2.0.0-beta", "PackManifest reads the declared @minecraft/server version");
check($versionManifest->getDependencies() === [], "a script module dependency is still not a pack dependency");

$versionLogger = new Logger();
$versionLanguage = new Language(Language::FALLBACK);
$versionPacks = new BehaviorPackManager($versionTemp . "/behavior_packs", $versionTemp . "/behavior_packs.json", $versionLanguage, $versionLogger);
$versionPacks->load();

$noCheck = new ScriptManager($versionPacks, $versionLogger, new FakeBridge(), $versionTemp . "/data", true, "auto", "warn");
check($noCheck->getVersionSetting() === "auto", "ScriptManager starts with the version it was given");
check($noCheck->setVersionSetting("1.16.0") && $noCheck->getVersionSetting() === "1.16.0", "setVersionSetting() changes the version");
check(!$noCheck->setVersionSetting("banana") && $noCheck->getVersionSetting() === "1.16.0", "setVersionSetting() refuses a bad version and keeps the old one");

$strict = new ScriptManager($versionPacks, $versionLogger, new FakeBridge(), $versionTemp . "/data", true, "1.16.0", "strict");
$strict->load();
$strictPack = $strict->getStatus()["packs"][0] ?? null;
check($strictPack !== null && $strictPack["ok"] === false && $strictPack["version"] === "2.0.0-beta", "strict refuses a pack that needs a newer version than the server presents");
check($strict->isRunning() === false, "strict leaves the host off when it refused every pack");

// ---------------------------------------------------------------------------
// 1c) Script API registry (pure PHP: the data table that drives the surface)
// ---------------------------------------------------------------------------
echo "Script API registry:\n";
$allRows = ScriptApiRegistry::surfaces();
check(count($allRows) > 40, "the registry declares the full surface (" . count($allRows) . " rows)");
check(ScriptApiRegistry::MAX_VERSION === ScriptApiVersion::IMPLEMENTED, "MAX_VERSION matches the version the server implements");

$rowsFor116 = ScriptApiRegistry::surfacesFor("1.16.0");
$rowsFor100 = ScriptApiRegistry::surfacesFor("1.0.0");
check(count($rowsFor116) === count($allRows), "a 1.16.0 pack sees every row");
check(count($rowsFor100) < count($rowsFor116), "a 1.0.0 pack sees fewer rows");

$paths100 = array_column($rowsFor100, "path");
$paths116 = array_column($rowsFor116, "path");
check(in_array("world.sendMessage", $paths100, true), "1.0.0 keeps the classic surface (world.sendMessage)");
check(!in_array("world.getEntity", $paths100, true), "1.0.0 does not get world.getEntity");
check(in_array("world.getEntity", $paths116, true), "1.16.0 gets world.getEntity");
check(in_array("world.scoreboard.addObjective", $paths116, true), "1.16.0 gets the scoreboard bridge");
check(!in_array("world.afterEvents.playerBreakBlock", $paths100, true), "1.0.0 does not get playerBreakBlock");
check(in_array("world.afterEvents.playerBreakBlock", $paths116, true), "1.16.0 gets playerBreakBlock");

$manifest = ScriptApiRegistry::manifest();
check($manifest["maxVersion"] === "1.16.0" && is_array($manifest["surfaces"]) && count($manifest["surfaces"]) === count($allRows), "manifest carries maxVersion and every row");
check(ScriptApiRegistry::surfacesFor("2.5.0-beta") === $rowsFor116, "versions above MAX_VERSION are capped at the implemented surface");

$eventRow = null;
foreach($rowsFor116 as $row){
	if($row["path"] === "world.afterEvents.playerBreakBlock"){
		$eventRow = $row;
	}
}
check($eventRow !== null && $eventRow["kind"] === ScriptApiRegistry::KIND_EVENT && $eventRow["event"] === "playerBreakBlock", "event rows carry the emit name and phase");

// ---------------------------------------------------------------------------
// 2) ScriptManager end-to-end against the real native host
// ---------------------------------------------------------------------------
$nativePath = PROJECT . "/bin/native/" . Native::getLibraryName();
if(!Native::load($nativePath)){
	echo " SKIP native library not available: " . Native::getError() . "\n";
	echo "       build it with: php build/make-native.php\n";
	echo "\nResult: {$tests} checks, {$failures} failure(s) (runtime tests skipped)\n";
	exit(0);
}
echo "using native QuickJS host (rquickjs 0.9): {$nativePath}\n";

$bridge = new FakeBridge();
$scriptManager = new ScriptManager(
	$manager,
	$logger,
	$bridge,
	$tempDir . "/script_data",
	true
);

$loaded = $scriptManager->load();
check($loaded === 1, "ScriptManager loads 1 script pack");
check($scriptManager->isRunning(), "Script API host is running");

$status = $scriptManager->getStatus();
check($status["engine"] === "embedded QuickJS (rquickjs 0.9)", "getStatus() reports the embedded engine");
check($status["packs"] !== [] && $status["packs"][0]["ok"], "pack reported ok by the host");
check(broadcastText($bridge->broadcasts) !== "" || count($bridge->executedCommands) > 0, "boot script event reached the bridge");

// 3) playerJoin after event
$bridge->broadcasts = [];
$scriptManager->emitPlayerJoin(["id" => 1, "name" => "Alex", "x" => 0.0, "y" => 64.0, "z" => 0.0, "dimension" => "world"]);
pump($scriptManager, 1, 8);
check(str_contains(broadcastText($bridge->broadcasts), "Alex"), "playerJoin event fires world.afterEvents.playerJoin");
check(str_contains(broadcastText($bridge->broadcasts), "joins acumulados"), "world dynamic property incremented by script");
check(str_contains(broadcastText($bridge->broadcasts), "mundo carregado"), "worldLoad fired once on the first tick after load");

// 4) system timers: schedule a timeout via eval, then advance ticks
$bridge->broadcasts = [];
[$ok, $result] = $scriptManager->evalScript("system.runTimeout(() => world.sendMessage('timer-hit'), 5) !== undefined");
check($ok, "evalScript executes (script_eval capability): " . ($ok ? "ok" : $result));
pump($scriptManager, 9, 19);
check(str_contains(broadcastText($bridge->broadcasts), "timer-hit"), "system.runTimeout fires on the tick that is due");

// 5) beforeEvents.chatSend can cancel and pass messages through
pump($scriptManager, 20, 20);
check($scriptManager->emitChatBefore(["id" => 1, "name" => "Alex", "x" => 0.0, "y" => 64.0, "z" => 0.0, "dimension" => "world"], "please spam the chat") === true, "beforeEvents.chatSend cancels a forbidden message");
check($scriptManager->emitChatBefore(["id" => 1, "name" => "Alex", "x" => 0.0, "y" => 64.0, "z" => 0.0, "dimension" => "world"], "hello world") === false, "beforeEvents.chatSend lets normal messages through");

// 6) afterEvents.chatSend with !ping responder
$bridge->playerMessages = [];
$scriptManager->emitChatAfter(["id" => 1, "name" => "Alex", "x" => 0.0, "y" => 64.0, "z" => 0.0, "dimension" => "world"], "!ping");
pump($scriptManager, 21, 26);
check(str_contains(implode("\n", $bridge->playerMessages[1] ?? []), "pong"), "afterEvents.chatSend answers with a targeted playerMessage");

// 7) dimension.runCommand from JS reaches the bridge
$bridge->executedCommands = [];
$scriptManager->evalScript("world.getDimension('overworld').runCommand('say hi from script')");
pump($scriptManager, 27, 30);
check(in_array("say hi from script", $bridge->executedCommands, true), "dimension.runCommand dispatches through the bridge");

// 8) /scriptevent style events
$bridge->broadcasts = [];
$scriptManager->emitScriptEvent("demo:greet", "hello scripts");
pump($scriptManager, 31, 34);
check(str_contains(broadcastText($bridge->broadcasts), "demo:greet"), "scriptevent reaches system.afterEvents.scriptEventReceive");

// 9) player snapshot -> getAllPlayers + player.sendMessage + player.runCommand
$bridge->players = [["id" => 7, "name" => "Steve", "x" => 1.5, "y" => 70.0, "z" => -3.0, "dimension" => "world", "yaw" => 90.0, "pitch" => -10.0, "gameMode" => "Survival", "health" => 20.0]];
pump($scriptManager, 35, 36); //snapshot travels with the next tick op
[$ok, $result] = $scriptManager->evalScript("world.getAllPlayers().map(p => p.name).join(',')");
check($ok && $result === "Steve", "getAllPlayers() reflects the tick snapshot: " . $result);

$bridge->playerMessages = [];
$scriptManager->evalScript("world.getAllPlayers()[0].sendMessage('hi steve')");
pump($scriptManager, 37, 39);
check(str_contains(implode("\n", $bridge->playerMessages[7] ?? []), "hi steve"), "player.sendMessage is delivered to the right player id");

$bridge->executedCommands = [];
$scriptManager->evalScript("world.getAllPlayers()[0].runCommand('help')");
pump($scriptManager, 40, 43);
check(in_array("help", $bridge->executedCommands, true), "player.runCommand dispatches through the bridge");

// 9b) registry-driven surface on the 1.16.0 module + entity objects
[$ok, $result] = $scriptManager->evalScript("[typeof world.getEntity, typeof world.getEntities, typeof world.scoreboard.addObjective, typeof world.afterEvents.playerBreakBlock, typeof world.afterEvents.worldLoad, TicksPerSecond].join('|')");
check($ok && $result === "function|function|function|object|object|20", "the 1.16.0 module exposes the registry surface: " . $result);

[$ok, $result] = $scriptManager->evalScript("const p = world.getAllPlayers()[0]; [typeof p.teleport, typeof p.getGameMode, typeof p.addTag].join('|')");
check($ok && $result === "function|function|function", "entity objects expose the new local/bridge members");

// 9c) session tags + getGameMode + rotation (sync, no bridge round trip)
[$ok, $result] = $scriptManager->evalScript("const p = world.getAllPlayers()[0]; p.addTag('vip') && p.addTag('vip') === false && p.getTags()[0] === 'vip' && p.getGameMode() === 'Survival' && p.rotation.y === 90 ? 'sync-ok' : 'sync-fail'");
check($ok && $result === "sync-ok", "player tags, getGameMode and rotation work synchronously: " . $result);

// 9d) apicall bridge: world.getEntities round trip (op -> PHP -> promise next tick)
$bridge->broadcasts = [];
$scriptManager->evalScript("world.getEntities().then((entities) => world.sendMessage('entities-count:' + entities.length + ':' + entities[0].name)).catch((error) => world.sendMessage('entities-error:' + error))");
pump($scriptManager, 44, 48);
check(str_contains(broadcastText($bridge->broadcasts), "entities-count:1:Steve"), "world.getEntities() promise resolves through the apicall bridge");

// 9e) apicall bridge: world.getEntity by id (unknown id resolves null, like vanilla's undefined)
$bridge->broadcasts = [];
$scriptManager->evalScript("world.getEntity(7).then((entity) => world.sendMessage('found:' + entity.name + ':' + entity.type + ':' + entity.gameMode)).catch((error) => world.sendMessage('entity-error:' + error))");
$scriptManager->evalScript("world.getEntity(999).then((entity) => world.sendMessage('bad-null:' + JSON.stringify(entity))).catch(() => world.sendMessage('missing-rejected'))");
pump($scriptManager, 49, 53);
check(str_contains(broadcastText($bridge->broadcasts), "found:Steve:minecraft:player:Survival"), "world.getEntity() returns the server snapshot");
check(str_contains(broadcastText($bridge->broadcasts), "bad-null:null"), "world.getEntity() resolves null for an unknown id");

// 9f) apicall bridge: teleport reaches the bridge with the right arguments
$bridge->teleports = [];
$scriptManager->evalScript("world.getAllPlayers()[0].teleport(10, 20, 30, 'nether')");
$scriptManager->evalScript("world.getAllPlayers()[0].teleport({x: 1, y: 2, z: 3})");
pump($scriptManager, 54, 57);
check(count($bridge->teleports) === 2 && $bridge->teleports[0] === [7, 10.0, 20.0, 30.0, "nether"], "player.teleport(x,y,z,dimension) dispatches to the bridge");
check($bridge->teleports[1] === [7, 1.0, 2.0, 3.0, null], "player.teleport(location) defaults the dimension to the current one");

// 9g) apicall bridge: scoreboard operations arrive as method + args
$bridge->scoreboardOps = [];
$bridge->broadcasts = [];
$scriptManager->evalScript("world.scoreboard.addObjective('money', 'dummy', 'Cash').then(() => world.sendMessage('obj-added'))");
$scriptManager->evalScript("world.scoreboard.addScore('money', world.getAllPlayers()[0], 5).then((score) => world.sendMessage('score:' + score))");
$scriptManager->evalScript("world.scoreboard.addScore('money', {id: 7, name: 'Steve'}, 5).then((score) => world.sendMessage('score2:' + score))");
pump($scriptManager, 58, 62);
$methods = array_column($bridge->scoreboardOps, "method");
$addIndex = array_search("addScore", $methods, true);
check(in_array("addObjective", $methods, true) && $addIndex !== false, "scoreboard calls dispatch to the bridge: " . implode(",", $methods));
$add = $addIndex !== false ? $bridge->scoreboardOps[$addIndex] : ["args" => []];
check(($add["args"][0] ?? null) === "money" && ($add["args"][1] ?? null) === ["id" => 7, "name" => "Steve"], "scoreboard participants arrive as entity refs {id, name}");
check(ServerScriptBridge::participantName(["id" => 7, "name" => "Steve"]) === "Steve" && ServerScriptBridge::participantName("Steve") === "Steve" && ServerScriptBridge::participantName(42) === "", "the real bridge resolves participants to their name (ServerScriptBridge::participantName)");
check(str_contains(broadcastText($bridge->broadcasts), "obj-added") && str_contains(broadcastText($bridge->broadcasts), "score:7"), "scoreboard promises resolve with the bridge result");

// 9h) world.getBlock bridge
$bridge->broadcasts = [];
$scriptManager->evalScript("world.getBlock(0, 64, 0).then((block) => world.sendMessage('block:' + block.type)).catch((error) => world.sendMessage('block-error:' + error))");
pump($scriptManager, 63, 66);
check(str_contains(broadcastText($bridge->broadcasts), "block:minecraft:stone"), "world.getBlock() returns type and location");

// 9i) playerBreakBlock / playerPlaceBlock after events (ScriptEventSink path)
$bridge->broadcasts = [];
$scriptManager->evalScript("world.afterEvents.playerBreakBlock.subscribe((event) => world.sendMessage('break:' + event.block.type + '@' + event.block.location.y + ':' + event.dimension.id))");
$scriptManager->evalScript("world.afterEvents.playerPlaceBlock.subscribe((event) => world.sendMessage('place:' + event.block.type))");
$scriptManager->emitPlayerBreakBlock(["id" => 7, "name" => "Steve", "x" => 1.0, "y" => 64.0, "z" => 2.0, "dimension" => "world"], "minecraft:stone", 1, 64, 2);
$scriptManager->emitPlayerPlaceBlock(["id" => 7, "name" => "Steve", "x" => 1.0, "y" => 64.0, "z" => 2.0, "dimension" => "world"], "minecraft:bricks", 1, 64, 3);
pump($scriptManager, 67, 70);
$allBroadcasts = broadcastText($bridge->broadcasts);
check(str_contains($allBroadcasts, "break:minecraft:stone@64:world"), "playerBreakBlock event carries block, location and dimension");
check(str_contains($allBroadcasts, "place:minecraft:bricks"), "playerPlaceBlock event fires from the emitter");

// 9j) worldLoad fired once, during the first pumps after load (checked at section 3)

// 9k) an apicall whose path has no PHP handler rejects the promise (bridgeCall is
// visible to eval; it is the same primitive every bridge member uses)
$bridge->broadcasts = [];
$scriptManager->evalScript("bridgeCall('world.notARealPath', []).then(() => world.sendMessage('no-reject')).catch((error) => world.sendMessage('rejected-ok:' + error))");
pump($scriptManager, 76, 79);
check(str_contains(broadcastText($bridge->broadcasts), "rejected-ok:"), "an unknown bridge path rejects the promise with the server error");

// 10) dynamic properties persist to script_data/dynamic_properties.json
$scriptManager->evalScript("world.setDynamicProperty('persist-test', 'works')");
$propsFile = $tempDir . "/script_data/dynamic_properties.json";
check(is_file($propsFile) && str_contains((string) file_get_contents($propsFile), "persist-test"), "dynamic properties persist to script_data/");

// 11) ScriptCommandSender strips formatting from command output
$sender = new ScriptCommandSender("test-pack", $logger, new PermissionManager());
$sender->sendMessage("§akeep this§r and this");
check($sender->collectOutput() === "keep this and this", "ScriptCommandSender strips TextFormat codes");

// 12) clean shutdown
$scriptManager->stop();
check(!$scriptManager->isRunning(), "stop() shuts the host down");

// status without a host still works
$status = $scriptManager->getStatus();
check($status["running"] === false, "getStatus() after stop reports not running");

// ---------------------------------------------------------------------------
// 13) per-version modules: the declared version decides which APIs exist
// ---------------------------------------------------------------------------
echo "Per-version modules:\n";
@mkdir($versionTemp . "/behavior_packs/OldPack/scripts", 0777, true);
file_put_contents($versionTemp . "/behavior_packs/OldPack/manifest.json", json_encode([
	"format_version" => 2,
	"header" => ["name" => "Old Pack", "uuid" => "b7e1a2c4-3333-4a2b-9c3d-0a1b2c3d4e5f", "version" => [1, 0, 0], "min_engine_version" => [1, 16, 0]],
	"modules" => [["type" => "script", "language" => "javascript", "uuid" => "c8f2b3d5-4444-4b3c-8d4e-1b2c3d4e5f60", "version" => [1, 0, 0], "entry" => "scripts/main.js"]],
	"dependencies" => [["module_name" => "@minecraft/server", "version" => "1.0.0"]]
]));
file_put_contents($versionTemp . "/behavior_packs/OldPack/scripts/main.js", "import { world } from '@minecraft/server';\nworld.sendMessage('old:' + typeof world.getEntity + ':' + typeof world.getEntities);\n");
file_put_contents($versionTemp . "/behavior_packs/NewPack/scripts/main.js", "import { world } from '@minecraft/server';\nworld.sendMessage('new:' + typeof world.getEntity + ':' + typeof world.scoreboard.addObjective);\n");

$versionPacks2 = new BehaviorPackManager($versionTemp . "/behavior_packs", $versionTemp . "/behavior_packs.json", $versionLanguage, $versionLogger);
$versionPacks2->load();

$autoBridge = new FakeBridge();
$auto = new ScriptManager($versionPacks2, $versionLogger, $autoBridge, $versionTemp . "/data2", true, "auto", "warn");
$loaded = $auto->load();
check($loaded === 2, "auto mode loads both packs (1.0.0 and 2.0.0-beta), got " . $loaded);
pump($auto, 1, 3);
$autoText = broadcastText($autoBridge->broadcasts);
check(str_contains($autoText, "old:undefined:undefined"), "the 1.0.0 module was built without the 1.16.0 APIs");
check(str_contains($autoText, "new:function:function"), "the 2.0.0-beta module gets the full implemented surface (with the load warning)");
$auto->stop();

// ---------------------------------------------------------------------------
echo "\nResult: {$tests} checks, {$failures} failure(s)\n";

//cleanup
foreach([$tempDir . "/behavior_packs/ExampleScriptAPIBP", $tempDir . "/archives"] as $dir){
	if(is_dir($dir)){
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file){
			$file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}
		@rmdir($dir);
	}
}
$cleanupFiles = [$tempDir . "/behavior_packs.json", $tempDir . "/archives.json", $tempDir . "/script_data/dynamic_properties.json"];
if(isset($archivePath)){
	$cleanupFiles[] = $archivePath;
}
foreach($cleanupFiles as $file){
	@unlink($file);
}
@rmdir($tempDir . "/script_data");
@rmdir($tempDir . "/behavior_packs");
@rmdir($tempDir);

exit($failures === 0 ? 0 : 1);
