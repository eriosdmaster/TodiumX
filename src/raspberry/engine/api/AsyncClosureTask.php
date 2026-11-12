<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\scheduler\AsyncTask;
use raspberry\plugin\Plugin;
use Closure;

class AsyncClosureTask extends AsyncTask {
    private Closure $closure;
    private Plugin $plugin;
    
    public function __construct(Closure $closure, Plugin $plugin) {
        $this->closure = $closure;
        $this->plugin = $plugin;
    }
    
    public function onRun(): void {
        try {
            ($this->closure)();
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Async scheduled task error: " . $e->getMessage());
        }
    }
}
