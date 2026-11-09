<?php

declare(strict_types=1);

namespace raspberry\event;

use raspberry\plugin\Plugin;

use Closure;

class RegisteredListener{

    public function __construct(
        private Plugin $plugin,
        private Closure $handler,
        private int $priority,
        private bool $handleCancelled
    ){}

    public function getPlugin() : Plugin{ return $this->plugin; }
    public function getPriority() : int{ return $this->priority; }

    public function callEvent(Event $event) : void{
        if($event instanceof Cancellable && $event->isCancelled() && !$this->handleCancelled){
            return;
        }
        ($this->handler)($event);
    }
}
