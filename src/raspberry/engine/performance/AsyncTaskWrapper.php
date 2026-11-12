<?php

declare(strict_types=1);

namespace raspberry\engine\performance;

use raspberry\scheduler\Task;

class AsyncTaskWrapper extends Task {
    private string $taskId;
    private PerformanceEngine $engine;
    
    public function __construct(string $taskId, PerformanceEngine $engine) {
        $this->taskId = $taskId;
        $this->engine = $engine;
    }
    
    public function onRun(int $currentTick): void {
        $this->engine->processAsyncTasks();
    }
}
