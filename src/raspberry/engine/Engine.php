<?php

declare(strict_types=1);

namespace raspberry\engine;

use raspberry\plugin\Plugin;

interface Engine {
    public function getName(): string;
    public function getVersion(): string;
    public function onLoad(): void;
    public function onEnable(): void;
    public function onDisable(): void;
    public function isEnabled(): bool;
    
    /**
     * Called when a plugin is loaded that might use this engine
     */
    public function onPluginLoad(Plugin $plugin): void;
    
    /**
     * Called when a plugin is enabled that might use this engine
     */
    public function onPluginEnable(Plugin $plugin): void;
    
    /**
     * Called when a plugin is disabled that might use this engine
     */
    public function onPluginDisable(Plugin $plugin): void;
}
