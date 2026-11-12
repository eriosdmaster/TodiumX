<?php

declare(strict_types=1);

namespace raspberry\engine\performance;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\native\Native;
use Closure;

class PerformanceEngine implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    // Profiler data
    private array $profilerData = [];
    private array $scopeTimers = [];
    
    // TPS monitoring
    private float $currentTPS = 20.0;
    private array $tickTimes = [];
    private int $tickCount = 0;
    private int $lastTPSUpdate = 0;
    
    // Chunk optimization
    private array $chunkCache = [];
    private int $maxChunkCacheSize = 1000;
    
    // Async processing
    private array $asyncTasks = [];
    private int $maxAsyncWorkers = 4;
    
    // Bottleneck detection
    private array $bottleneckThresholds = [
        'tick' => 50.0, // ms
        'chunk_generation' => 100.0, // ms
        'entity_processing' => 30.0, // ms
        'network' => 20.0, // ms
    ];
    
    private array $bottleneckHistory = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "PerformanceEngine";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("PerformanceEngine loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("PerformanceEngine enabled");
        
        // Register profiler scopes if native library supports it
        if (Native::isLoaded()) {
            $this->logger->info("Native performance profiling available");
        }
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("PerformanceEngine disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {
        // Plugin loaded, can register performance hooks
    }
    
    public function onPluginEnable(Plugin $plugin): void {
        // Plugin enabled, can start monitoring
    }
    
    public function onPluginDisable(Plugin $plugin): void {
        // Plugin disabled, clean up any tracking
    }
    
    // ==================== PROFILER ====================
    
    public function profilerBegin(string $scope): void {
        if (!Native::isLoaded()) {
            $this->scopeTimers[$scope] = microtime(true);
            return;
        }
        
        Native::profilerBegin($scope);
    }
    
    public function profilerEnd(string $scope): void {
        if (!Native::isLoaded()) {
            if (isset($this->scopeTimers[$scope])) {
                $duration = (microtime(true) - $this->scopeTimers[$scope]) * 1000; // ms
                unset($this->scopeTimers[$scope]);
                
                if (!isset($this->profilerData[$scope])) {
                    $this->profilerData[$scope] = [
                        'count' => 0,
                        'total_time' => 0.0,
                        'min_time' => PHP_FLOAT_MAX,
                        'max_time' => 0.0,
                        'avg_time' => 0.0,
                    ];
                }
                
                $data = &$this->profilerData[$scope];
                $data['count']++;
                $data['total_time'] += $duration;
                $data['min_time'] = min($data['min_time'], $duration);
                $data['max_time'] = max($data['max_time'], $duration);
                $data['avg_time'] = $data['total_time'] / $data['count'];
            }
            return;
        }
        
        Native::profilerEnd($scope);
    }
    
    public function profilerScope(string $scope, Closure $callback): mixed {
        $this->profilerBegin($scope);
        try {
            return $callback();
        } finally {
            $this->profilerEnd($scope);
        }
    }
    
    public function profilerReset(): void {
        if (Native::isLoaded()) {
            Native::profilerReset();
        }
        $this->profilerData = [];
        $this->scopeTimers = [];
    }
    
    public function getProfilerReport(): ?array {
        if (Native::isLoaded()) {
            $report = Native::profilerReport();
            if ($report !== null) {
                return json_decode($report, true);
            }
        }
        
        return $this->profilerData;
    }
    
    // ==================== TPS MONITORING ====================
    
    public function recordTickTime(float $tickTimeMs): void {
        $this->tickTimes[] = $tickTimeMs;
        $this->tickCount++;
        
        // Keep only last 100 ticks for averaging
        if (count($this->tickTimes) > 100) {
            array_shift($this->tickTimes);
        }
        
        // Update TPS every 20 ticks (1 second at 20 TPS)
        if ($this->tickCount % 20 === 0) {
            $this->updateTPS();
        }
    }
    
    private function updateTPS(): void {
        if (empty($this->tickTimes)) {
            return;
        }
        
        $avgTickTime = array_sum($this->tickTimes) / count($this->tickTimes);
        $this->currentTPS = $avgTickTime > 0 ? min(20.0, 1000.0 / $avgTickTime) : 20.0;
        $this->lastTPSUpdate = time();
        
        // Check for bottlenecks
        $this->checkBottlenecks();
    }
    
    public function getTPS(): float {
        return $this->currentTPS;
    }
    
    public function getAverageTickTime(): float {
        if (empty($this->tickTimes)) {
            return 0.0;
        }
        return array_sum($this->tickTimes) / count($this->tickTimes);
    }
    
    // ==================== CHUNK OPTIMIZATION ====================
    
    public function cacheChunk(string $key, mixed $data): void {
        if (count($this->chunkCache) >= $this->maxChunkCacheSize) {
            // Remove oldest entry (simple LRU)
            $oldestKey = array_key_first($this->chunkCache);
            unset($this->chunkCache[$oldestKey]);
        }
        
        $this->chunkCache[$key] = [
            'data' => $data,
            'time' => time(),
            'access_count' => 0,
        ];
    }
    
    public function getCachedChunk(string $key): mixed {
        if (!isset($this->chunkCache[$key])) {
            return null;
        }
        
        $this->chunkCache[$key]['access_count']++;
        $this->chunkCache[$key]['time'] = time();
        
        return $this->chunkCache[$key]['data'];
    }
    
    public function invalidateChunkCache(string $key): void {
        unset($this->chunkCache[$key]);
    }
    
    public function clearChunkCache(): void {
        $this->chunkCache = [];
    }
    
    public function getChunkCacheStats(): array {
        return [
            'size' => count($this->chunkCache),
            'max_size' => $this->maxChunkCacheSize,
            'usage_percent' => count($this->chunkCache) / $this->maxChunkCacheSize * 100,
        ];
    }
    
    public function setMaxChunkCacheSize(int $size): void {
        $this->maxChunkCacheSize = max(100, $size);
    }
    
    // ==================== ASYNC PROCESSING ====================
    
    public function submitAsyncTask(Closure $task, ?Closure $callback = null): string {
        $taskId = uniqid('async_', true);
        
        $this->asyncTasks[$taskId] = [
            'task' => $task,
            'callback' => $callback,
            'status' => 'pending',
            'start_time' => microtime(true),
        ];
        
        // In a real implementation, this would be queued to a worker pool
        // For now, we'll process it immediately in the next tick
        $this->server->getScheduler()->scheduleDelayedTask(new AsyncTaskWrapper($taskId, $this), 1);
        
        return $taskId;
    }
    
    public function processAsyncTasks(): void {
        // Process pending async tasks (called from scheduler)
        foreach ($this->asyncTasks as $taskId => &$taskData) {
            if ($taskData['status'] !== 'pending') {
                continue;
            }
            
            $taskData['status'] = 'running';
            
            try {
                $result = $taskData['task']();
                $taskData['status'] = 'completed';
                $taskData['result'] = $result;
                $taskData['end_time'] = microtime(true);
                
                if ($taskData['callback'] !== null) {
                    $taskData['callback']($result);
                }
            } catch (\Throwable $e) {
                $taskData['status'] = 'failed';
                $taskData['error'] = $e->getMessage();
                $taskData['end_time'] = microtime(true);
                
                $this->logger->error("Async task $taskId failed: " . $e->getMessage());
            }
        }
    }
    
    public function getAsyncTaskStatus(string $taskId): ?array {
        return $this->asyncTasks[$taskId] ?? null;
    }
    
    public function cancelAsyncTask(string $taskId): bool {
        if (!isset($this->asyncTasks[$taskId])) {
            return false;
        }
        
        if ($this->asyncTasks[$taskId]['status'] === 'pending') {
            unset($this->asyncTasks[$taskId]);
            return true;
        }
        
        return false;
    }
    
    // ==================== BOTTLENECK DETECTION ====================
    
    private function checkBottlenecks(): void {
        $avgTickTime = $this->getAverageTickTime();
        
        if ($avgTickTime > $this->bottleneckThresholds['tick']) {
            $this->recordBottleneck('tick', $avgTickTime, 'Average tick time exceeded threshold');
        }
        
        // Check other metrics if available
        if (Native::isLoaded()) {
            $poolStats = Native::poolStats();
            if ($poolStats !== null && isset($poolStats['queued']) && $poolStats['queued'] > 100) {
                $this->recordBottleneck('worker_queue', $poolStats['queued'], 'Worker queue backlog');
            }
        }
    }
    
    private function recordBottleneck(string $type, float $value, string $message): void {
        $bottleneck = [
            'type' => $type,
            'value' => $value,
            'threshold' => $this->bottleneckThresholds[$type] ?? 0,
            'message' => $message,
            'time' => time(),
            'tps' => $this->currentTPS,
        ];
        
        $this->bottleneckHistory[] = $bottleneck;
        
        // Keep only last 100 bottlenecks
        if (count($this->bottleneckHistory) > 100) {
            array_shift($this->bottleneckHistory);
        }
        
        $this->logger->warning("Performance bottleneck detected: $message (value: $value, TPS: {$this->currentTPS})");
    }
    
    public function getBottleneckHistory(): array {
        return $this->bottleneckHistory;
    }
    
    public function setBottleneckThreshold(string $type, float $threshold): void {
        $this->bottleneckThresholds[$type] = $threshold;
    }
    
    // ==================== NATIVE INTEGRATION ====================
    
    public function getNativePoolStats(): ?array {
        if (Native::isLoaded()) {
            return Native::poolStats();
        }
        return null;
    }
    
    // ==================== REPORTING ====================
    
    public function getPerformanceReport(): array {
        return [
            'tps' => $this->currentTPS,
            'avg_tick_time' => $this->getAverageTickTime(),
            'profiler' => $this->getProfilerReport(),
            'chunk_cache' => $this->getChunkCacheStats(),
            'async_tasks' => [
                'pending' => count(array_filter($this->asyncTasks, fn($t) => $t['status'] === 'pending')),
                'running' => count(array_filter($this->asyncTasks, fn($t) => $t['status'] === 'running')),
                'completed' => count(array_filter($this->asyncTasks, fn($t) => $t['status'] === 'completed')),
                'failed' => count(array_filter($this->asyncTasks, fn($t) => $t['status'] === 'failed')),
            ],
            'bottlenecks' => $this->getBottleneckHistory(),
            'native_pool' => $this->getNativePoolStats(),
        ];
    }
}
