<?php

declare(strict_types=1);

namespace raspberry\utils;

use function array_shift;
use function count;
use function hrtime;
use function microtime;

/**
 * Measures ticks per second, tick time and the time spent in named subsystems, and keeps a list
 * of the calls that went over a configurable limit (the bottlenecks). Pure PHP, it never touches
 * the native library, so it also works when that is not loaded.
 */
class Profiler{

    /** the TPS windows the report exposes, in seconds */
    private const WINDOWS = [1, 5, 15];

    /** how many slow calls to remember for the bottleneck list */
    private const BOTTLENECKS = 32;

    private static ?Profiler $instance = null;

    /** anything slower than this, in seconds, is listed as a bottleneck */
    private float $threshold;

    /** @var array<string, array{count: int, total: float, min: float, max: float, last: float, moving: float}> */
    private array $scopes = [];

    /** @var array<string, float> scope name => when it was started, in seconds */
    private array $open = [];

    /** @var list<float> when the recent ticks happened, used for the TPS windows */
    private array $ticks = [];

    /** @var list<array{scope: string, seconds: float, at: float}> */
    private array $bottlenecks = [];

    private int $tickCount = 0;

    private float $started;

    public function __construct(float $threshold = 0.05){
        $this->threshold = $threshold;
        $this->started = microtime(true);
    }

    public static function getInstance() : Profiler{
        return self::$instance ??= new Profiler();
    }

    /**
     * A call that takes longer than this is a bottleneck. Seconds.
     */
    public function setThreshold(float $seconds) : void{
        $this->threshold = $seconds;
    }

    public function getThreshold() : float{
        return $this->threshold;
    }

    /**
     * Starts timing a named scope. Scopes can nest, but not two of the same name at once.
     */
    public function begin(string $scope) : void{
        $this->open[$scope] = $this->now();
    }

    /**
     * Stops timing a scope and keeps the sample. Does nothing when begin() was not called.
     */
    public function end(string $scope) : void{
        $start = $this->open[$scope] ?? null;
        if($start === null){
            return;
        }

        unset($this->open[$scope]);
        $this->record($scope, $this->now() - $start);
    }

    /**
     * Times $callback, returns what it returns and records the scope even when it throws.
     */
    public function scope(string $scope, callable $callback) : mixed{
        $this->begin($scope);
        try{
            return $callback();
        }finally{
            $this->end($scope);
        }
    }

    /**
     * Records a scope that was timed somewhere else, in seconds.
     */
    public function record(string $scope, float $seconds) : void{
        $entry = $this->scopes[$scope] ?? null;
        if($entry === null){
            $entry = ["count" => 0, "total" => 0.0, "min" => $seconds, "max" => $seconds, "last" => $seconds, "moving" => $seconds];
        }else{
            $entry["min"] = $seconds < $entry["min"] ? $seconds : $entry["min"];
            $entry["max"] = $seconds > $entry["max"] ? $seconds : $entry["max"];
            $entry["last"] = $seconds;
            // exponential moving average, one slow tick moves it but does not dominate it
            $entry["moving"] = $entry["moving"] * 0.9 + $seconds * 0.1;
        }

        ++$entry["count"];
        $entry["total"] += $seconds;
        $this->scopes[$scope] = $entry;

        if($seconds >= $this->threshold){
            $this->bottlenecks[] = ["scope" => $scope, "seconds" => $seconds, "at" => microtime(true)];
            if(count($this->bottlenecks) > self::BOTTLENECKS){
                array_shift($this->bottlenecks);
            }
        }
    }

    /**
     * Starts a tick. The server calls this at the top of its loop.
     */
    public function beginTick() : void{
        $this->begin("tick");
    }

    /**
     * Ends a tick, records how long it took and remembers when it happened for the TPS windows.
     */
    public function endTick() : void{
        $start = $this->open["tick"] ?? null;
        if($start !== null){
            unset($this->open["tick"]);
            $this->record("tick", $this->now() - $start);
        }

        $this->ticks[] = microtime(true);
        $this->trim();
    }

    /**
     * Ticks per second over the 1s, 5s and 15s windows plus the average since startup.
     *
     * @return array<string, float>
     */
    public function getTps() : array{
        $now = microtime(true);
        $tps = [];
        foreach(self::WINDOWS as $window){
            $cutoff = $now - $window;
            $count = 0;
            foreach($this->ticks as $tick){
                if($tick >= $cutoff){
                    ++$count;
                }
            }
            $tps[$window . "s"] = $count / $window;
        }

        $uptime = $now - $this->started;
        $tps["average"] = $uptime > 0.0 ? $this->tickCount / $uptime : 0.0;

        return $tps;
    }

    /**
     * The single number most callers want: ticks per second over the last second.
     */
    public function getTpsNow() : float{
        return $this->getTps()["1s"];
    }

    /**
     * Tick time in seconds: last, min, max, average and the moving average.
     *
     * @return array<string, float>
     */
    public function getTickTime() : array{
        $scope = $this->scopes["tick"] ?? null;
        if($scope === null){
            return ["last" => 0.0, "min" => 0.0, "max" => 0.0, "average" => 0.0, "moving" => 0.0];
        }

        return [
            "last" => $scope["last"],
            "min" => $scope["min"],
            "max" => $scope["max"],
            "average" => $scope["total"] / $scope["count"],
            "moving" => $scope["moving"]
        ];
    }

    /**
     * Every timed scope, in seconds.
     *
     * @return array<string, array<string, float|int>>
     */
    public function getScopes() : array{
        $scopes = [];
        foreach($this->scopes as $name => $entry){
            $scopes[$name] = [
                "count" => $entry["count"],
                "total" => $entry["total"],
                "min" => $entry["min"],
                "max" => $entry["max"],
                "last" => $entry["last"],
                "average" => $entry["count"] > 0 ? $entry["total"] / $entry["count"] : 0.0,
                "moving" => $entry["moving"]
            ];
        }

        return $scopes;
    }

    /**
     * The slow calls seen since the last reset, oldest first.
     *
     * @return list<array{scope: string, seconds: float, at: float}>
     */
    public function getBottlenecks() : array{
        return $this->bottlenecks;
    }

    public function getTickCount() : int{
        return $this->tickCount;
    }

    /**
     * Everything the profiler knows, ready to hand out or encode as JSON.
     *
     * @return array<string, mixed>
     */
    public function getReport() : array{
        return [
            "ticks" => $this->tickCount,
            "uptime" => microtime(true) - $this->started,
            "tps" => $this->getTps(),
            "tick" => $this->getTickTime(),
            "scopes" => $this->getScopes(),
            "bottlenecks" => $this->bottlenecks,
            "threshold" => $this->threshold
        ];
    }

    /**
     * Forgets every sample, the counters and the bottleneck list.
     */
    public function reset() : void{
        $this->scopes = [];
        $this->open = [];
        $this->ticks = [];
        $this->bottlenecks = [];
        $this->tickCount = 0;
        $this->started = microtime(true);
    }

    private function now() : float{
        return hrtime(true) / 1e9;
    }

    private function trim() : void{
        $cutoff = microtime(true) - 15.0;
        while($this->ticks !== [] && $this->ticks[0] < $cutoff){
            array_shift($this->ticks);
        }
    }
}
