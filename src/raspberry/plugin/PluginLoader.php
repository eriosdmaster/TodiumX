<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\Server;

use function array_slice;
use function class_exists;
use function count;
use function end;
use function explode;
use function file_exists;
use function implode;
use function is_dir;
use function is_file;
use function is_subclass_of;
use function rtrim;
use function spl_autoload_register;
use function str_replace;
use function strlen;
use function substr;
use function trim;

class PluginLoader{

    /** @var array<string, string> plugin name => base path */
    private array $pluginPaths = [];

    private bool $autoloaderRegistered = false;

    public function __construct(private Server $server){}

    /**
     * @throws PluginException
     */
    public function loadPlugin(string $path) : Plugin{
        $path = rtrim($path, "/\\");
        $manifest = $path . "/plugin.json";
        if(!is_file($manifest)){
            // also accept plugin.yml? only if yaml parser available and file exists - keep simple, json first
            if(is_file($path . "/plugin.yml")){
                throw new PluginException("plugin.yml found but only plugin.json is supported by this loader: {$path}");
            }
            throw new PluginException("plugin.json not found in {$path}");
        }
        $description = PluginDescription::fromFile($manifest);
        $this->pluginPaths[$description->getName()] = $path;
        $this->registerAutoloader();

        $main = $description->getMain();
        $this->tryIncludeMain($path, $main);

        if(!class_exists($main, true)){
            throw new PluginException("Main class '{$main}' not found for plugin {$description->getName()} (checked src/ autoloading)");
        }
        if(!is_subclass_of($main, Plugin::class)){
            throw new PluginException("Main class '{$main}' must implement " . Plugin::class);
        }
        if(!is_subclass_of($main, PluginBase::class)){
            // allow any Plugin impl, but PluginBase is recommended
        }

        $plugin = new $main();
        if(!$plugin instanceof Plugin){
            throw new PluginException("Main class '{$main}' did not produce a Plugin instance");
        }
        $dataFolder = $this->server->getDataPath() . "/plugins/" . $description->getName();
        $plugin->initialize($this, $this->server, $description, $dataFolder, $this->server->getLogger());
        return $plugin;
    }

    private function tryIncludeMain(string $path, string $main) : void{
        // PSR-4 style under src/: Namespace\Class -> src/Namespace/Class.php
        $relative = str_replace("\\", "/", $main) . ".php";
        $candidates = [
            $path . "/src/" . $relative,
            $path . "/" . $relative,
        ];
        // also try without top-level namespace segment(s) variations
        $parts = explode("/", $relative);
        if(count($parts) > 1){
            $candidates[] = $path . "/src/" . implode("/", array_slice($parts, 1));
            $candidates[] = $path . "/src/" . end($parts);
        }
        foreach($candidates as $file){
            if(is_file($file)){
                require_once $file;
                if(class_exists($main, false)){
                    return;
                }
            }
        }
    }

    private function registerAutoloader() : void{
        if($this->autoloaderRegistered){
            return;
        }
        $this->autoloaderRegistered = true;
        spl_autoload_register(function(string $class) : void{
            $relative = str_replace("\\", "/", $class) . ".php";
            foreach($this->pluginPaths as $base){
                foreach([$base . "/src/" . $relative, $base . "/" . $relative] as $file){
                    if(is_file($file)){
                        require_once $file;
                        return;
                    }
                }
            }
        });
    }

    public function getPluginPath(string $name) : ?string{
        return $this->pluginPaths[$name] ?? null;
    }
}
