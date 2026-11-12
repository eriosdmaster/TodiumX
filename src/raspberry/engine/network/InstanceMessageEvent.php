<?php

declare(strict_types=1);

namespace raspberry\engine\network;

use raspberry\event\Event;

class InstanceMessageEvent extends Event {
    public function __construct(
        public readonly string $fromInstance,
        public readonly string $channel,
        public readonly mixed $data
    ) {}
}
