<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\scheduler\Task;
use raspberry\plugin\Plugin;
use Closure;

class ClosureTask extends Task {
    private Closure $closure;
    private Plugin $plugin;
    
    public function __construct(Closure $closure, Plugin $plugin) {
        $this->closure = $closure;
        $this->plugin = $plugin;
    }
    
    public function onRun(int $currentTick): void {
        try {
            ($this->closure)($this);
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Scheduled task error: " . $e->getMessage());
        }
    }
}
