<?php

declare(strict_types=1);

namespace raspberry\event;

abstract class Event{

    private ?string $eventName = null;
    private bool $cancelled = false;

    final public function getEventName() : string{
        return $this->eventName ?? static::class;
    }

    public function isCancelled() : bool{
        if(!($this instanceof Cancellable)){
            throw new \BadMethodCallException(static::class . " is not Cancellable");
        }
        return $this->cancelled;
    }

    public function setCancelled(bool $value = true) : void{
        if(!($this instanceof Cancellable)){
            throw new \BadMethodCallException(static::class . " is not Cancellable");
        }
        $this->cancelled = $value;
    }

    public function cancel() : void{
        $this->setCancelled(true);
    }

    public function uncancel() : void{
        $this->setCancelled(false);
    }
}
