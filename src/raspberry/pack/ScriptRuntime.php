<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\Server;
use raspberry\native\Native;
use raspberry\utils\Logger;

use Throwable;

use function array_keys;
use function array_merge;
use function array_values;
use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function implode;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_numeric;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function max;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function realpath;
use function rtrim;
use function shell_exec;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function stream_set_blocking;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
     * Runs behavior pack script modules WITHOUT requiring Node.js.
     *
     * Engine priority:
     * 1) **Embedded QuickJS with bidirectional host bridge** (FFI, ABI 4+) — full @minecraft/server API via server calls.
     * 2) QuickJS CLI (`qjs`, `quickjs`, `qjs-ng`, `run-qjs`) if installed (e.g. package quickjs-ng) — shim only.
     * 3) Other JS runtimes (`bun`, `deno`) if installed — shim only.
     *
     * The embedded bridge enables real host calls: world/player/entity/dimension/commands/events.
     * Scripts can use `import * as mc from "@minecraft/server"` and call `mc.world.runCommand`, `mc.world.getPlayers()`, etc.
     */
    class ScriptRuntime{

    private const ENGINE_NATIVE = "native";
    private const ENGINE_QJS = "qjs";
    private const ENGINE_BUN = "bun";
    private const ENGINE_DENO = "deno";
    private const ENGINE_NONE = "none";

    /** @var array<string, array{engine: string, handle?: int, bridgeHandle?: int, process?: resource, pipes?: array, pack: string}> */
    private array $runtimes = [];

    private Server $server;
    private Logger $logger;
    private string $runtimeDir;
    private string $engine = self::ENGINE_NONE;
    private ?string $cliPath = null;
    private ?ScriptBridge $bridge = null;

    public function __construct(Server $server, string $runtimeDir){
        $this->server = $server;
        $this->logger = $server->getLogger();
        $this->runtimeDir = $runtimeDir;
        if(!is_dir($this->runtimeDir)){
            @mkdir($this->runtimeDir, 0777, true);
        }
        $this->bridge = new ScriptBridge($this->server, $this->logger);
        $this->detectEngine();
    }

    private function detectEngine() : void{
        // 1) Native embedded QuickJS with bridge (ABI 4+)
        if(Native::isAvailable()){
            $this->engine = self::ENGINE_NATIVE;
            $this->logger->info("Script runtime: using embedded native JS engine with bridge (no Node.js required).");
            return;
        }

        // 2) QuickJS CLI
        foreach(["qjs-ng", "qjs", "quickjs", "quickjs-ng", "run-qjs"] as $bin){
            $path = $this->findExecutable($bin);
            if($path !== null){
                $this->engine = self::ENGINE_QJS;
                $this->cliPath = $path;
                $this->logger->info("Script runtime: using QuickJS CLI at {$path} (no Node.js required).");
                return;
            }
        }

        // 3) Other non-Node runtimes
        foreach([self::ENGINE_BUN => ["bun"], self::ENGINE_DENO => ["deno"]] as $engine => $bins){
            foreach($bins as $bin){
                $path = $this->findExecutable($bin);
                if($path !== null){
                    $this->engine = $engine;
                    $this->cliPath = $path;
                    $this->logger->info("Script runtime: using {$bin} at {$path} (no Node.js required).");
                    return;
                }
            }
        }

        $this->engine = self::ENGINE_NONE;
        $this->logger->warning("Script runtime: no JS engine available (native library missing/incompatible and no qjs/bun/deno found). Script modules will be parsed but not executed. Rebuild native with ABI 4 (QuickJS) or install quickjs-ng; Node.js is not required.");
    }

    private function findExecutable(string $name) : ?string{
        $paths = [
            "/data/data/com.termux/files/usr/bin/" . $name,
            "/data/user/0/com.androidharness.app/files/linux/bin/" . $name,
            "/data/user/0/com.androidharness.app/files/linux/usr/bin/" . $name,
            "/usr/bin/" . $name,
            "/usr/local/bin/" . $name,
            "/opt/homebrew/bin/" . $name,
        ];
        foreach($paths as $p){
            if(is_file($p) && is_executable($p)){
                return $p;
            }
        }
        $result = @shell_exec("command -v " . escapeshellarg($name) . " 2>/dev/null");
        if(is_string($result)){
            $result = trim($result);
            if($result !== "" && is_file($result)){
                return $result;
            }
        }
        return null;
    }

    /**
     * @param array<string, array{pack: string, entry: string, files: array<string, string>, path: string, uuid: string}> $modules
     */
    public function startModules(array $modules) : void{
        $this->stopAll();
        foreach($modules as $uuid => $mod){
            try{
                $this->startModule($uuid, $mod);
            }catch(Throwable $e){
                $this->logger->error("Failed to start script module '{$mod['pack']}': " . $e->getMessage());
            }
        }
    }

    /**
     * @param array{pack: string, entry: string, files: array<string, string>, path: string, uuid: string} $mod
     */
    private function startModule(string $uuid, array $mod) : void{
        if($this->engine === self::ENGINE_NONE){
            $this->logger->warning("Script module '{$mod['pack']}' ({$uuid}) not executed: no non-Node JS engine available. Entry: {$mod['entry']}");
            return;
        }

        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $uuid);
        $dir = $this->runtimeDir . "/" . $safeId;
        if(!is_dir($dir)){
            @mkdir($dir, 0777, true);
        }

        // Materialize script files for reference/debugging
        foreach($mod["files"] as $rel => $contents){
            if($rel === "__entry__" || !is_string($contents)) continue;
            $target = $dir . "/" . str_replace(["../", "..\\"], "", $rel);
            $targetDir = dirname($target);
            if(!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
            @file_put_contents($target, $contents);
        }

        $entry = $mod["entry"] ?? "scripts/main.js";
        $entryContent = $this->resolveEntryContent($dir, $mod["files"], $entry);
        if($entryContent === null){
            $this->logger->warning("Script module '{$mod['pack']}': entry file not found ({$entry}).");
            return;
        }

        $shim = $this->getGlobalShim($mod["pack"]);
        $userCode = $this->transformImports($entryContent);
        $combined = $shim . "\n\n// ---- pack entry: {$entry} ----\n" . $userCode . "\n";

        if($this->engine === self::ENGINE_NATIVE){
            $bridgeHandle = Native::bridgeNew($mod["pack"], $shim);
            if($bridgeHandle <= 0){
                $this->logger->warning("Script module '{$mod['pack']}': native bridge engine unavailable (native library may be ABI < 4). Trying CLI fallback.");
                $this->startWithCli($uuid, $mod, $dir, $combined);
                return;
            }
            $ok = Native::bridgeEval($bridgeHandle, "// ---- pack entry: {$entry} ----\n" . $userCode);
            if(!$ok){
                $this->logger->warning("Script module '{$mod['pack']}': entry evaluation reported an error (see script logs).");
            }
            $this->runtimes[$uuid] = ["engine" => self::ENGINE_NATIVE, "bridgeHandle" => $bridgeHandle, "pack" => $mod["pack"]];
            $this->logger->info("Script module '{$mod['pack']}' started in embedded native JS engine with bridge (no Node.js).");
            return;
        }

        $this->startWithCli($uuid, $mod, $dir, $combined);
    }

    /**
     * @param array<string, string> $files
     */
    private function resolveEntryContent(string $dir, array $files, string $entry) : ?string{
        $norm = ltrim(str_replace("\\", "/", $entry), "./");
        if(isset($files[$norm]) && is_string($files[$norm])) return $files[$norm];
        if(isset($files["scripts/" . ltrim($norm, "/")]) && is_string($files["scripts/" . ltrim($norm, "/")])) return $files["scripts/" . ltrim($norm, "/")];
        foreach([$norm, "scripts/" . basename($norm), "scripts/main.js", "scripts/index.js", "main.js", "index.js"] as $try){
            $p = $dir . "/" . $try;
            if(is_file($p)){
                $c = @file_get_contents($p);
                if(is_string($c)) return $c;
            }
            if(isset($files[$try]) && is_string($files[$try])) return $files[$try];
        }
        return null;
    }

    private function transformImports(string $code) : string{
        // Convert common @minecraft/server ESM imports to globals provided by shim.
        // import * as mc from "@minecraft/server";
        $code = preg_replace('/import\s+\*\s+as\s+([A-Za-z0-9_$]+)\s+from\s+[\'"]@minecraft\/server[\'"]\s*;?/m', 'const $1 = globalThis.__mc_server;', $code) ?? $code;
        // import { world, system } from "@minecraft/server";
        $code = preg_replace_callback('/import\s*\{([^}]+)\}\s*from\s*[\'"]@minecraft\/server[\'"]\s*;?/m', function($m){
            $names = trim($m[1]);
            return "const { {$names} } = globalThis.__mc_server;";
        }, $code) ?? $code;
        // import ... from "@minecraft/server-ui" etc -> empty stubs
        $code = preg_replace('/import\s+[^;]*from\s*[\'"]@minecraft\/[^\'"]+[\'"]\s*;?/m', '/* [todiumx] @minecraft/* import stubbed */', $code) ?? $code;
        // export statements are not valid in classic eval; strip common forms
        $code = preg_replace('/^\s*export\s+default\s+/m', 'globalThis.__mc_export_default = ', $code) ?? $code;
        $code = preg_replace('/^\s*export\s+\{[^}]*\}\s*;?\s*$/m', '', $code) ?? $code;
        $code = preg_replace('/^\s*export\s+(?=(?:const|let|var|function|class|async)\b)/m', '', $code) ?? $code;
        return $code;
    }

    /**
     * @param array{pack: string, entry: string, files: array<string, string>, path: string, uuid: string} $mod
     */
    private function startWithCli(string $uuid, array $mod, string $dir, string $combined) : void{
        $engine = $this->engine === self::ENGINE_NATIVE ? $this->detectCliFallback() : $this->engine;
        $path = $this->engine === self::ENGINE_NATIVE ? $this->findCliFallbackPath() : $this->cliPath;
        if($engine === self::ENGINE_NONE || $path === null){
            $this->logger->warning("Script module '{$mod['pack']}': no non-Node JS engine available after fallback. Install quickjs-ng or rebuild native (ABI 4).");
            return;
        }
        $scriptFile = $dir . "/__todiumx_combined__.js";
        @file_put_contents($scriptFile, $combined . "\n// keep alive\nif (typeof setInterval === 'function') try{ setInterval(function(){}, 60000); }catch(e){}\n");

        $cmd = $this->buildCliCommand($engine, $path, $scriptFile);
        $descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $process = @proc_open($cmd, $descriptors, $pipes, $dir);
        if(!is_resource($process)){
            $this->logger->error("Script module '{$mod['pack']}': failed to start {$engine} process");
            return;
        }
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        @fclose($pipes[0]);
        $this->runtimes[$uuid] = ["engine" => $engine, "process" => $process, "pipes" => $pipes, "pack" => $mod["pack"]];
        $this->logger->info("Script module '{$mod['pack']}' started with {$engine} (no Node.js).");
    }

    private function detectCliFallback() : string{
        foreach([self::ENGINE_QJS => ["qjs-ng","qjs","quickjs","quickjs-ng"], self::ENGINE_BUN => ["bun"], self::ENGINE_DENO => ["deno"]] as $engine => $bins){
            foreach($bins as $bin){ if($this->findExecutable($bin) !== null) return $engine; }
        }
        return self::ENGINE_NONE;
    }

    private function findCliFallbackPath() : ?string{
        foreach(["qjs-ng","qjs","quickjs","quickjs-ng","bun","deno"] as $bin){
            $p = $this->findExecutable($bin);
            if($p !== null) return $p;
        }
        return null;
    }

    private function buildCliCommand(string $engine, string $path, string $scriptFile) : string{
        $p = escapeshellarg($path);
        $s = escapeshellarg($scriptFile);
        return match($engine){
            self::ENGINE_QJS => $p . " --std " . $s . " 2>&1",
            self::ENGINE_BUN => $p . " run " . $s . " 2>&1",
            self::ENGINE_DENO => $p . " run --allow-all " . $s . " 2>&1",
            default => $p . " " . $s . " 2>&1",
        };
    }

    public function tick() : void{
        foreach($this->runtimes as $uuid => &$info){
            if(($info["engine"] ?? "") === self::ENGINE_NATIVE){
                $bridgeHandle = (int) ($info["bridgeHandle"] ?? 0);
                if($bridgeHandle <= 0) continue;
                // Poll host calls from the script bridge
                for($i = 0; $i < 20; $i++){
                    $req = Native::bridgePollRequest($bridgeHandle);
                    if($req === null) break;
                    $method = $req["method"] ?? "";
                    $params = is_array($req["params"] ?? null) ? $req["params"] : [];
                    if($method !== ""){
                        $result = $this->bridge->handle($method, $params);
                        Native::bridgeSubmitResponse($bridgeHandle, $i, $result, null);
                    }
                }
                continue;
            }
            $process = $info["process"] ?? null;
            $pipes = $info["pipes"] ?? [];
            if(!is_resource($process)){
                unset($this->runtimes[$uuid]);
                continue;
            }
            $status = @proc_get_status($process);
            $out = "";
            foreach([1, 2] as $i){
                if(isset($pipes[$i]) && is_resource($pipes[$i])){
                    $chunk = @stream_get_contents($pipes[$i]);
                    if(is_string($chunk)) $out .= $chunk;
                }
            }
            if(trim($out) !== ""){
                foreach(explode("\n", $out) as $line){
                    $line = trim($line);
                    if($line !== "") $this->logger->info("[Script:{$info['pack']}] " . $line);
                }
            }
            if(is_array($status) && !($status["running"] ?? false)){
                $this->logger->info("Script module '{$info['pack']}' exited (code {$status['exitcode']})");
                $this->closeProcess($info);
                unset($this->runtimes[$uuid]);
            }
        }
    }

    /**
     * @param array{process?: resource, pipes?: array, bridgeHandle?: int, handle?: int, engine: string, pack: string} $info
     */
    private function closeProcess(array &$info) : void{
        if(($info["engine"] ?? "") === self::ENGINE_NATIVE){
            $bh = (int)($info["bridgeHandle"] ?? 0);
            if($bh > 0) Native::bridgeFree($bh);
            $h = (int)($info["handle"] ?? 0);
            if($h > 0) Native::scriptFree($h);
            $info["bridgeHandle"] = 0;
            $info["handle"] = 0;
            return;
        }
        foreach([1, 2] as $i){
            if(isset($info["pipes"][$i]) && is_resource($info["pipes"][$i])) @fclose($info["pipes"][$i]);
        }
        if(isset($info["process"]) && is_resource($info["process"])) @proc_close($info["process"]);
    }

    public function stopAll() : void{
        foreach($this->runtimes as $uuid => &$info){
            $this->logger->info("Stopping script module '{$info['pack']}' ({$info['engine']})");
            $this->closeProcess($info);
        }
        $this->runtimes = [];
    }

    public function __destruct(){
        $this->stopAll();
    }

    public function getEngine() : string{
        return $this->engine;
    }

    private function getGlobalShim(string $packName) : string{
        $pn = json_encode($packName, JSON_UNESCAPED_SLASHES);
        // Bridge-aware shim: uses __mc_bridge_call(method, params) for host calls
        return <<<JS
// @minecraft/server shim with bidirectional host bridge (no Node.js).
(function(){
  const packName = {$pn};
  function log(){ try{ var a=[]; for(var i=0;i<arguments.length;i++) a.push(arguments[i]); console.log(a.join(' ')); }catch(e){} }
  function EventSignal(name){ this.name=name; this._subs=[]; }
  EventSignal.prototype.subscribe=function(fn){ this._subs.push(fn); log('[shim] subscribed', this.name); return fn; };
  EventSignal.prototype.unsubscribe=function(fn){ this._subs=this._subs.filter(function(x){return x!==fn;}); };
  function makeEvents(){ return new Proxy({}, { get: function(t,p){ if(!t[p]) t[p]=new EventSignal(String(p)); return t[p]; } }); }

  // Bridge helper: calls PHP host via native bridge
  async function bridgeCall(method, params){
    if(typeof globalThis.__mc_bridge_call !== 'function'){
      return {__error: "bridge not available"};
    }
    return await globalThis.__mc_bridge_call(method, params);
  }

  function entityFacade(data){
    if(!data || data.__type !== 'Entity') return data;
    data.teleport = function(location){ return bridgeCall('entity.teleport', {id:data.id,location:location||{}}); };
    data.applyDamage = function(amount){ return bridgeCall('entity.applyDamage', {id:data.id,amount:amount}); };
    data.remove = function(){ return bridgeCall('entity.remove', {id:data.id}); };
    data.setNameTag = function(nameTag){ data.nameTag=nameTag; return bridgeCall('entity.setNameTag', {id:data.id,nameTag:nameTag}); };
    data.getComponent = function(componentId){ return bridgeCall('entity.getComponent', {id:data.id,componentId:componentId}).then(function(c){
      if(c && c.__type === 'EntityHealthComponent'){
        c.setCurrentValue = function(value){ return bridgeCall('entity.setHealth', {id:c.entityId,value:value}); };
        c.resetToMaxValue = function(){ return bridgeCall('entity.setHealth', {id:c.entityId,value:c.effectiveMax}); };
      }
      return c;
    }); };
    data.hasComponent = function(componentId){ return bridgeCall('entity.hasComponent', {id:data.id,componentId:componentId}); };
    return data;
  }

  var world = {
    sendMessage: function(msg){ return bridgeCall('world.sendMessage', {message: msg}); },
    getPlayers: function(){ return bridgeCall('world.getAllPlayers', {}); },
    getPlayer: function(opts){ return bridgeCall('world.getPlayer', opts||{}); },
    runCommand: function(cmd){ return bridgeCall('world.runCommand', {command: cmd}); },
    runCommandAsync: function(cmd){ return bridgeCall('world.runCommandAsync', {command: cmd}); },
    getDimension: function(id){
      const dimensionId = id || 'minecraft:overworld';
      return {
        id: dimensionId,
        spawnEntity: function(typeId, location){ return bridgeCall('dimension.spawnEntity', {dimensionId:dimensionId,typeId:typeId,location:location||{}}).then(entityFacade); },
        getEntities: function(){ return bridgeCall('dimension.getEntities', {dimensionId:dimensionId}).then(function(list){ return (list||[]).map(entityFacade); }); },
        getBlock: function(location){ return bridgeCall('dimension.getBlock', {dimensionId:dimensionId,location:location||{}}); },
        setBlock: function(location, typeId){ return bridgeCall('dimension.setBlock', {dimensionId:dimensionId,location:location||{},typeId:typeId}); },
        runCommand: function(command){ return bridgeCall('dimension.runCommand', {dimensionId:dimensionId,command:command}); },
        runCommandAsync: function(command){ return bridgeCall('dimension.runCommandAsync', {dimensionId:dimensionId,command:command}); }
      };
    },
    getEntity: function(id){ return bridgeCall('world.getEntity', {id: id}).then(entityFacade); },
    afterEvents: makeEvents(),
    beforeEvents: makeEvents(),
    setDynamicProperty: function(id,val){ return bridgeCall('world.setDynamicProperty', {id:id,value:val}); },
    getDynamicProperty: function(id){ return bridgeCall('world.getDynamicProperty', {id:id}); },
  };

  var system = {
    run: function(fn){ try{ return fn(); }catch(e){ console.error(e && e.stack ? e.stack : e); } },
    runInterval: function(fn,ticks){ if(typeof setInterval!=='function') return 0; return setInterval(function(){ try{ fn(); }catch(e){ console.error(e && e.stack ? e.stack : e); } }, Math.max(50,(ticks||1)*50)); },
    runTimeout: function(fn,ticks){ if(typeof setTimeout!=='function'){ try{ fn(); }catch(e){} return 0; } return setTimeout(function(){ try{ fn(); }catch(e){ console.error(e && e.stack ? e.stack : e); } }, Math.max(1,(ticks||0)*50)); },
    clearRun: function(id){ try{ if(typeof clearInterval==='function') clearInterval(id); if(typeof clearTimeout==='function') clearTimeout(id); }catch(e){} },
    get currentTick(){ return bridgeCall('system.getCurrentTick', {}); },
    afterEvents: makeEvents(),
    beforeEvents: makeEvents()
  };

  function BlockPermutation(){ } BlockPermutation.resolve=function(){ return new BlockPermutation(); };
  function ItemStack(typeId, amount){ this.typeId=typeId; this.amount=amount||1; this.nameTag=''; }
  var Direction={Down:'Down',Up:'Up',North:'North',South:'South',West:'West',East:'East'};
  var GameMode={survival:'survival',creative:'creative',adventure:'adventure',spectator:'spectator'};
  var mc = { world:world, system:system, BlockPermutation:BlockPermutation, ItemStack:ItemStack, Direction:Direction, GameMode:GameMode };
  globalThis.world = world;
  globalThis.system = system;
  globalThis.BlockPermutation = BlockPermutation;
  globalThis.ItemStack = ItemStack;
  globalThis.Direction = Direction;
  globalThis.GameMode = GameMode;
  globalThis.__mc_server = mc;
  globalThis.Minecraft = mc;
  try { console.log('[shim] @minecraft/server bridge shim loaded for ' + packName + ' (no Node.js)'); } catch(e){}
})();
JS;
    }
}
