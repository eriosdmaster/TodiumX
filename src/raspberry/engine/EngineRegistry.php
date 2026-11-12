<?php

declare(strict_types=1);

namespace raspberry\engine;

use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;

class EngineRegistry {
    /** @var array<string, Engine> */
    private array $engines = [];
    
    /** @var array<string, bool> */
    private array $enabled = [];
    
    private Server $server;
    private Logger $logger;
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function registerEngine(Engine $engine): void {
        $name = $engine->getName();
        if (isset($this->engines[$name])) {
            $this->logger->warning("Engine '$name' is already registered, replacing");
        }
        
        $this->engines[$name] = $engine;
        $this->enabled[$name] = false;
        $this->logger->info("Registered engine: $name v" . $engine->getVersion());
    }
    
    public function getEngine(string $name): ?Engine {
        return $this->engines[$name] ?? null;
    }
    
    public function hasEngine(string $name): bool {
        return isset($this->engines[$name]);
    }
    
    public function enableEngine(string $name): bool {
        if (!isset($this->engines[$name])) {
            return false;
        }
        
        if ($this->enabled[$name]) {
            return true;
        }
        
        try {
            $this->engines[$name]->onEnable();
            $this->enabled[$name] = true;
            $this->logger->info("Enabled engine: $name");
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to enable engine '$name': " . $e->getMessage());
            return false;
        }
    }
    
    public function disableEngine(string $name): bool {
        if (!isset($this->engines[$name])) {
            return false;
        }
        
        if (!$this->enabled[$name]) {
            return true;
        }
        
        try {
            $this->engines[$name]->onDisable();
            $this->enabled[$name] = false;
            $this->logger->info("Disabled engine: $name");
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to disable engine '$name': " . $e->getMessage());
            return false;
        }
    }
    
    public function isEngineEnabled(string $name): bool {
        return $this->enabled[$name] ?? false;
    }
    
    public function loadAllEngines(): void {
        foreach ($this->engines as $engine) {
            try {
                $engine->onLoad();
            } catch (\Throwable $e) {
                $this->logger->error("Failed to load engine '" . $engine->getName() . "': " . $e->getMessage());
            }
        }
    }
    
    public function enableAllEngines(): void {
        foreach ($this->engines as $name => $engine) {
            $this->enableEngine($name);
        }
    }
    
    public function disableAllEngines(): void {
        // Disable in reverse order
        $names = array_keys($this->engines);
        $names = array_reverse($names);
        
        foreach ($names as $name) {
            $this->disableEngine($name);
        }
    }
    
    public function onPluginLoad(Plugin $plugin): void {
        foreach ($this->engines as $engine) {
            if ($this->enabled[$engine->getName()]) {
                try {
                    $engine->onPluginLoad($plugin);
                } catch (\Throwable $e) {
                    $this->logger->error("Engine '" . $engine->getName() . "' error on plugin load: " . $e->getMessage());
                }
            }
        }
    }
    
    public function onPluginEnable(Plugin $plugin): void {
        foreach ($this->engines as $engine) {
            if ($this->enabled[$engine->getName()]) {
                try {
                    $engine->onPluginEnable($plugin);
                } catch (\Throwable $e) {
                    $this->logger->error("Engine '" . $engine->getName() . "' error on plugin enable: " . $e->getMessage());
                }
            }
        }
    }
    
    public function onPluginDisable(Plugin $plugin): void {
        foreach ($this->engines as $engine) {
            if ($this->enabled[$engine->getName()]) {
                try {
                    $engine->onPluginDisable($plugin);
                } catch (\Throwable $e) {
                    $this->logger->error("Engine '" . $engine->getName() . "' error on plugin disable: " . $e->getMessage());
                }
            }
        }
    }
    
    /** @return array<string, Engine> */
    public function getAllEngines(): array {
        return $this->engines;
    }
    
    /** @return list<string> */
    public function getEnabledEngineNames(): array {
        return array_keys(array_filter($this->enabled));
    }
}
