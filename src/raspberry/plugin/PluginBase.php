<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\math\Vector3;
use raspberry\world\World;
use raspberry\block\BlockState;
use raspberry\entity\CustomEntity;
use raspberry\item\Item;
use raspberry\event\Listener;

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

    /** Register a server-side data-driven item; $components uses minecraft:* component keys. */
    final protected function registerCustomItem(string $identifier, array $components = []) : int{
        return $this->getServer()->registerPluginCustomItem($identifier, $components);
    }

    final protected function createCustomItem(string $identifier, int $count = 1) : Item{
        if(!isset($this->getServer()->getPluginContentRegistry()->getItems()[strtolower($identifier)])){
            throw new PluginException("Custom item is not registered: {$identifier}");
        }
        return new Item($identifier, $count);
    }

    /**
     * Register a custom block identifier backed by a vanilla block state. This is useful for plugin logic
     * while client-side custom block palette registration is not available in the current protocol layer.
     */
    final protected function registerCustomBlock(string $identifier, string $fallbackBlock = "minecraft:stone") : void{
        $this->getServer()->registerPluginCustomBlock($identifier, $fallbackBlock);
    }

    final protected function placeCustomBlock(World $world, int $x, int $y, int $z, string $identifier) : void{
        $this->getServer()->placePluginCustomBlock($world, $x, $y, $z, $identifier);
    }

    final protected function getCustomBlockIdAt(World $world, int $x, int $y, int $z) : ?string{
        return $this->getServer()->getPluginCustomBlockIdAt($world, $x, $y, $z);
    }

    final protected function getBlock(World $world, int $x, int $y, int $z) : BlockState{
        return $world->getBlock($x, $y, $z);
    }

    final protected function setBlock(World $world, int $x, int $y, int $z, BlockState $block) : void{
        $world->setBlock($x, $y, $z, $block);
    }

    final protected function registerCustomEntity(string $identifier, array $definition = []) : void{
        $this->getServer()->registerPluginCustomEntity($identifier, $definition);
    }

    final protected function spawnCustomEntity(string $identifier, float $x, float $y, float $z, ?World $world = null) : ?CustomEntity{
        return $this->getServer()->spawnCustomEntity($identifier, new Vector3($x, $y, $z), $world);
    }

    final protected function registerEvents(Listener $listener) : void{
        $this->getServer()->getPluginManager()->registerEvents($listener, $this);
    }
}
