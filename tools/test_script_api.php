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
use raspberry\pack\script\ScriptCommandSender;
use raspberry\pack\script\ScriptManager;
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
$bridge->players = [["id" => 7, "name" => "Steve", "x" => 1.5, "y" => 70.0, "z" => -3.0, "dimension" => "world"]];
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
