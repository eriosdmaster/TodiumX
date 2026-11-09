<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\Server;
use raspberry\utils\Logger;

abstract class PluginBase implements Plugin{

    private bool $enabled = false;
    private bool $initialized = false;
    private ?PluginLoader $loader = null;
    private ?Server $server = null;
    private ?PluginDescription $description = null;
    private ?string $dataFolder = null;
    private ?Logger $logger = null;

    public function onLoad() : void{}
    public function onEnable() : void{}
    public function onDisable() : void{}

    final public function isEnabled() : bool{
        return $this->enabled;
    }

    final public function setEnabled(bool $enabled) : void{
        $this->enabled = $enabled;
    }

    final public function initialize(PluginLoader $loader, Server $server, PluginDescription $description, string $dataFolder, Logger $logger) : void{
        if($this->initialized){
            return;
        }
        $this->initialized = true;
        $this->loader = $loader;
        $this->server = $server;
        $this->description = $description;
        $this->dataFolder = $dataFolder;
        $this->logger = $logger;
        if(!is_dir($this->dataFolder)){
            @mkdir($this->dataFolder, 0777, true);
        }
    }

    final public function getDescription() : PluginDescription{
        return $this->description ?? throw new PluginException("Plugin not initialized");
    }

    final public function getServer() : Server{
        return $this->server ?? throw new PluginException("Plugin not initialized");
    }

    final public function getDataFolder() : string{
        return $this->dataFolder ?? throw new PluginException("Plugin not initialized");
    }

    final public function getName() : string{
        return $this->getDescription()->getName();
    }

    final public function getLogger() : Logger{
        return $this->logger ?? $this->getServer()->getLogger();
    }

    final public function getPluginLoader() : PluginLoader{
        return $this->loader ?? throw new PluginException("Plugin not initialized");
    }

    protected function getConfigPath(string $file = "config.json") : string{
        return $this->getDataFolder() . "/" . $file;
    }
}
