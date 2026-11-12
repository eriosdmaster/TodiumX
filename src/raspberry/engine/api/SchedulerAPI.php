<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\scheduler\Task;
use Closure;

class SchedulerAPI implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    private array $scheduledTasks = [];
    private int $taskIdCounter = 0;
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "SchedulerAPI";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("SchedulerAPI loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("SchedulerAPI enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->cancelAllTasks();
        $this->logger->info("SchedulerAPI disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {
        $this->cancelPluginTasks($plugin);
    }
    
    // ==================== TASK SCHEDULING ====================
    
    public function scheduleRepeatingTask(Plugin $plugin, Closure $task, int $delayTicks, int $periodTicks): int {
        $taskId = ++$this->taskIdCounter;
        
        $wrappedTask = new ClosureTask($task, $plugin);
        $this->server->getScheduler()->scheduleRepeatingTask($wrappedTask, $delayTicks, $periodTicks);
        
        $this->scheduledTasks[$taskId] = [
            'plugin' => $plugin,
            'task' => $wrappedTask,
            'type' => 'repeating',
        ];
        
        return $taskId;
    }
    
    public function scheduleDelayedTask(Plugin $plugin, Closure $task, int $delayTicks): int {
        $taskId = ++$this->taskIdCounter;
        
        $wrappedTask = new ClosureTask($task, $plugin);
        $this->server->getScheduler()->scheduleDelayedTask($wrappedTask, $delayTicks);
        
        $this->scheduledTasks[$taskId] = [
            'plugin' => $plugin,
            'task' => $wrappedTask,
            'type' => 'delayed',
        ];
        
        return $taskId;
    }
    
    public function scheduleAsyncRepeatingTask(Plugin $plugin, Closure $task, int $delayTicks, int $periodTicks): int {
        $taskId = ++$this->taskIdCounter;
        
        $wrappedTask = new AsyncClosureTask($task, $plugin);
        $this->server->getAsyncScheduler()->scheduleRepeatingTask($wrappedTask, $delayTicks, $periodTicks);
        
        $this->scheduledTasks[$taskId] = [
            'plugin' => $plugin,
            'task' => $wrappedTask,
            'type' => 'async_repeating',
        ];
        
        return $taskId;
    }
    
    public function scheduleAsyncDelayedTask(Plugin $plugin, Closure $task, int $delayTicks): int {
        $taskId = ++$this->taskIdCounter;
        
        $wrappedTask = new AsyncClosureTask($task, $plugin);
        $this->server->getAsyncScheduler()->scheduleDelayedTask($wrappedTask, $delayTicks);
        
        $this->scheduledTasks[$taskId] = [
            'plugin' => $plugin,
            'task' => $wrappedTask,
            'type' => 'async_delayed',
        ];
        
        return $taskId;
    }
    
    public function cancelTask(int $taskId): bool {
        if (!isset($this->scheduledTasks[$taskId])) {
            return false;
        }
        
        $taskData = $this->scheduledTasks[$taskId];
        $taskData['task']->cancel();
        unset($this->scheduledTasks[$taskId]);
        
        return true;
    }
    
    public function cancelPluginTasks(Plugin $plugin): void {
        foreach ($this->scheduledTasks as $taskId => $taskData) {
            if ($taskData['plugin'] === $plugin) {
                $taskData['task']->cancel();
                unset($this->scheduledTasks[$taskId]);
            }
        }
    }
    
    public function cancelAllTasks(): void {
        foreach ($this->scheduledTasks as $taskData) {
            $taskData['task']->cancel();
        }
        $this->scheduledTasks = [];
    }
    
    public function getPendingTasks(Plugin $plugin): array {
        $tasks = [];
        foreach ($this->scheduledTasks as $taskId => $taskData) {
            if ($taskData['plugin'] === $plugin) {
                $tasks[$taskId] = $taskData;
            }
        }
        return $tasks;
    }
    
    public function getAllPendingTasks(): array {
        return $this->scheduledTasks;
    }
    
    public function isTaskPending(int $taskId): bool {
        return isset($this->scheduledTasks[$taskId]);
    }
}
