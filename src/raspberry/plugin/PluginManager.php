<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\Server;
use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;
use raspberry\event\Event;
use raspberry\event\EventPriority;
use raspberry\event\HandlerList;
use raspberry\event\Listener;
use raspberry\event\RegisteredListener;
use raspberry\permission\PermissionLevel;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function is_subclass_of;
use function scandir;
use function sort;
use function spl_object_id;
use function str_ends_with;
use function strcasecmp;
use function strtolower;
use function usort;

class PluginManager{

    private Server $server;
    private PluginLoader $loader;
    /** @var array<string, Plugin> name => plugin */
    private array $plugins = [];
    /** @var array<string, bool> */
    private array $enabled = [];
    private string $pluginsPath;

    public function __construct(Server $server, string $pluginsPath){
        $this->server = $server;
        $this->pluginsPath = $pluginsPath;
        $this->loader = new PluginLoader($server);
        if(!is_dir($this->pluginsPath)){
            @mkdir($this->pluginsPath, 0777, true);
        }
    }

    public function getServer() : Server{ return $this->server; }
    public function getPluginLoader() : PluginLoader{ return $this->loader; }

    public function getPlugin(string $name) : ?Plugin{
        foreach($this->plugins as $n => $p){
            if(strcasecmp($n, $name) === 0) return $p;
        }
        return null;
    }

    /** @return array<string, Plugin> */
    public function getPlugins() : array{ return $this->plugins; }

    public function isPluginEnabled(string $name) : bool{
        $p = $this->getPlugin($name);
        return $p !== null && $p->isEnabled();
    }

    public function loadPlugins() : void{
        $entries = scandir($this->pluginsPath);
        if($entries === false){
            $this->server->getLogger()->warning("Could not read plugins directory: {$this->pluginsPath}");
            return;
        }
        $candidates = [];
        foreach($entries as $entry){
            if($entry === "." || $entry === "..") continue;
            $path = $this->pluginsPath . "/" . $entry;
            if(!is_dir($path)) continue;
            if(!is_file($path . "/plugin.json") && !is_file($path . "/plugin.yml")) continue;
            try{
                $desc = is_file($path . "/plugin.json") ? PluginDescription::fromFile($path . "/plugin.json") : null;
                if($desc === null) continue;
                $candidates[$desc->getName()] = ["path" => $path, "desc" => $desc];
            }catch(Throwable $e){
                $this->server->getLogger()->error("Failed to read plugin manifest in {$entry}: " . $e->getMessage());
            }
        }
        $order = $this->resolveLoadOrder($candidates);
        foreach($order as $name){
            $info = $candidates[$name] ?? null;
            if($info === null) continue;
            try{
                $plugin = $this->loader->loadPlugin($info["path"]);
                $this->plugins[$plugin->getName()] = $plugin;
                $this->registerManifestPermissions($plugin);
                $this->registerManifestCommands($plugin);
                $plugin->onLoad();
                $this->server->getLogger()->info("Loaded plugin " . $plugin->getDescription()->getFullName());
            }catch(Throwable $e){
                $this->server->getLogger()->error("Failed to load plugin {$name}: " . $e->getMessage());
                $this->server->getLogger()->error($e->getFile() . ":" . $e->getLine() . " " . $e->getTraceAsString());
            }
        }
    }

    public function enablePlugins() : void{
        foreach($this->plugins as $plugin){
            $this->enablePlugin($plugin);
        }
    }

    public function enablePlugin(Plugin $plugin) : bool{
        if($plugin->isEnabled()) return true;
        // ensure dependencies enabled
        foreach($plugin->getDescription()->getDepend() as $dep){
            $d = $this->getPlugin($dep);
            if($d === null || !$d->isEnabled()){
                $this->server->getLogger()->error("Cannot enable {$plugin->getName()}: missing dependency {$dep}");
                return false;
            }
        }
        try{
            $plugin->setEnabled(true);
            $plugin->onEnable();
            $this->callEvent(new \raspberry\event\plugin\PluginEnableEvent($plugin));
            $this->server->getLogger()->info("Enabled plugin " . $plugin->getDescription()->getFullName());
            return true;
        }catch(Throwable $e){
            $plugin->setEnabled(false);
            $this->server->getLogger()->error("Error enabling plugin {$plugin->getName()}: " . $e->getMessage());
            $this->server->getLogger()->error($e->getFile() . ":" . $e->getLine());
            return false;
        }
    }

    public function disablePlugins() : void{
        $plugins = array_values($this->plugins);
        // reverse order
        usort($plugins, fn($a, $b) => 0); // keep load order then reverse
        for($i = count($plugins) - 1; $i >= 0; $i--){
            $this->disablePlugin($plugins[$i]);
        }
    }

    public function disablePlugin(Plugin $plugin) : void{
        if(!$plugin->isEnabled()) return;
        try{
            $this->callEvent(new \raspberry\event\plugin\PluginDisableEvent($plugin));
            $plugin->onDisable();
        }catch(Throwable $e){
            $this->server->getLogger()->error("Error disabling plugin {$plugin->getName()}: " . $e->getMessage());
        }
        $plugin->setEnabled(false);
        HandlerList::unregisterAll($plugin);
    }

    public function callEvent(Event $event) : void{
        $list = HandlerList::getHandlerListFor($event::class);
        foreach($list->getListeners() as $listener){
            try{
                $listener->callEvent($event);
            }catch(Throwable $e){
                $this->server->getLogger()->error("Error in plugin event handler for " . $event::class . ": " . $e->getMessage());
                $this->server->getLogger()->logException($e);
            }
        }
    }

    public function registerEvents(Listener $listener, Plugin $plugin) : void{
        if(!$plugin->isEnabled()){
            // allow during onEnable
        }
        $ref = new ReflectionClass($listener);
        foreach($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            if($method->getDeclaringClass()->getName() === ReflectionClass::class) continue;
            if($method->getNumberOfParameters() !== 1) continue;
            $param = $method->getParameters()[0];
            $type = $param->getType();
            if(!$type instanceof ReflectionNamedType || $type->isBuiltin()) continue;
            $eventClass = $type->getName();
            if(!is_subclass_of($eventClass, Event::class)) continue;
            $priority = EventPriority::NORMAL;
            $handleCancelled = false;
            $attrs = $method->getAttributes();
            foreach($attrs as $attr){
                $an = $attr->getName();
                if($an === \raspberry\event\EventHandler::class || str_ends_with($an, "EventHandler")){
                    $args = $attr->getArguments();
                    if(isset($args["priority"]) && is_int($args["priority"])) $priority = $args["priority"];
                    if(isset($args[0]) && is_int($args[0])) $priority = $args[0];
                    if(isset($args["handleCancelled"])) $handleCancelled = (bool)$args["handleCancelled"];
                    if(isset($args["ignoreCancelled"])) $handleCancelled = !$args["ignoreCancelled"];
                }
            }
            $handlerList = HandlerList::getHandlerListFor($eventClass);
            $handlerList->register(new RegisteredListener(
                $plugin,
                \Closure::fromCallable([$listener, $method->getName()]),
                $priority,
                $handleCancelled
            ));
            $this->server->getLogger()->debug("Registered event handler {$plugin->getName()}::{$method->getName()} for {$eventClass}");
        }
    }

    private function registerManifestPermissions(Plugin $plugin) : void{
        $pm = $this->server->getPermissionManager();
        foreach($plugin->getDescription()->getPermissions() as $name => $info){
            if(!is_string($name)) continue;
            $default = PermissionLevel::PUBLIC;
            $desc = "";
            if(is_array($info)){
                $desc = is_string($info["description"] ?? null) ? $info["description"] : "";
                $d = $info["default"] ?? "true";
                if($d === "op" || $d === "operator") $default = PermissionLevel::OPERATOR;
                elseif($d === "console") $default = PermissionLevel::CONSOLE;
                elseif($d === false || $d === "false" || $d === "notop") $default = PermissionLevel::OPERATOR; // conservative
                else $default = PermissionLevel::PUBLIC;
            }
            try{
                $pm->register($name, $desc, $default);
            }catch(Throwable $e){
                $this->server->getLogger()->debug("Permission {$name} from {$plugin->getName()}: " . $e->getMessage());
            }
        }
    }

    private function registerManifestCommands(Plugin $plugin) : void{
        foreach($plugin->getDescription()->getCommands() as $name => $info){
            if(!is_string($name) || !is_array($info)) continue;
            $desc = is_string($info["description"] ?? null) ? $info["description"] : "";
            $usage = is_string($info["usage"] ?? null) ? $info["usage"] : null;
            $perm = is_string($info["permission"] ?? null) ? $info["permission"] : null;
            $aliases = [];
            if(is_array($info["aliases"] ?? null)){
                foreach($info["aliases"] as $a){ if(is_string($a)) $aliases[] = $a; }
            }
            $cmd = new PluginManifestCommand($name, $desc, $plugin, $usage, $perm, $aliases);
            try{
                $this->server->getCommandMap()->registerCommand($name, $cmd);
            }catch(Throwable $e){
                $this->server->getLogger()->warning("Failed to register command {$name} for {$plugin->getName()}: " . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, array{path: string, desc: PluginDescription}> $candidates
     * @return list<string>
     */
    private function resolveLoadOrder(array $candidates) : array{
        $names = array_keys($candidates);
        sort($names);
        $order = [];
        $visiting = [];
        $visited = [];
        $visit = function(string $name) use (&$visit, &$order, &$visiting, &$visited, $candidates) : void{
            if(isset($visited[$name])) return;
            if(isset($visiting[$name])){
                // cycle: allow
                return;
            }
            if(!isset($candidates[$name])) return;
            $visiting[$name] = true;
            $desc = $candidates[$name]["desc"];
            foreach($desc->getDepend() as $dep){
                $visit($dep);
            }
            foreach($desc->getSoftDepend() as $dep){
                $visit($dep);
            }
            foreach($desc->getLoadBefore() as $before){
                // handled implicitly; not critical
            }
            unset($visiting[$name]);
            $visited[$name] = true;
            $order[] = $name;
        };
        foreach($names as $n){ $visit($n); }
        return $order;
    }
}

/**
 * Command registered from plugin.json. Execution is delegated to the plugin via PluginCommandExecutor if implemented.
 */
class PluginManifestCommand extends Command{
    public function __construct(
        string $name,
        string $description,
        private Plugin $plugin,
        ?string $usage = null,
        ?string $permission = null,
        array $aliases = []
    ){
        parent::__construct($name, $description, [[]], $aliases);
        if($usage !== null) $this->setUsage($usage);
        if($permission !== null) $this->setPermission($permission);
    }

    public function execute(CommandSender $sender, array $args) : bool{
        $plugin = $this->plugin;
        if($plugin instanceof PluginCommandExecutor){
            try{
                return $plugin->onCommand($sender, $this, $this->getName(), $args);
            }catch(Throwable $e){
                $sender->sendMessage("§cAn error occurred while executing this command.");
                $plugin->getLogger()->error("Command {$this->getName()} error: " . $e->getMessage());
                return true;
            }
        }
        $sender->sendMessage("§eCommand '{$this->getName()}' is registered by {$plugin->getName()} but has no executor.");
        return true;
    }

    public function getPlugin() : Plugin{ return $this->plugin; }
}
