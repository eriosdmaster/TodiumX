<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\Server;
use raspberry\utils\Logger;

interface Plugin{

    public function onLoad() : void;
    public function onEnable() : void;
    public function onDisable() : void;

    public function isEnabled() : bool;
    public function getDescription() : PluginDescription;
    public function getServer() : Server;
    public function getDataFolder() : string;
    public function getName() : string;
    public function getLogger() : Logger;
    public function getPluginLoader() : PluginLoader;

    /**
     * Called by PluginManager.
     * @internal
     */
    public function setEnabled(bool $enabled) : void;
    /**
     * @internal
     */
    public function initialize(PluginLoader $loader, Server $server, PluginDescription $description, string $dataFolder, Logger $logger) : void;
}
