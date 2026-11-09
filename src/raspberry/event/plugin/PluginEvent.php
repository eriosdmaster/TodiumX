<?php

declare(strict_types=1);

namespace raspberry\event\plugin;

use raspberry\event\Event;
use raspberry\plugin\Plugin;

abstract class PluginEvent extends Event{
    public function __construct(private Plugin $plugin){}
    public function getPlugin() : Plugin{ return $this->plugin; }
}
