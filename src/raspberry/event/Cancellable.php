<?php

declare(strict_types=1);

namespace raspberry\event;

interface Cancellable{
    public function isCancelled() : bool;
    public function setCancelled(bool $value = true) : void;
    public function cancel() : void;
    public function uncancel() : void;
}
