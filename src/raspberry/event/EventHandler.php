<?php

declare(strict_types=1);

namespace raspberry\event;

use Attribute;

/**
 * Marks a listener method as an event handler.
 * Usage: #[EventHandler(priority: EventPriority::NORMAL, handleCancelled: false)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class EventHandler{
    public function __init(int $priority = EventPriority::NORMAL, bool $handleCancelled = false){
        $this->__construct($priority, $handleCancelled);
    }
    public function __construct(
        public int $priority = EventPriority::NORMAL,
        public bool $handleCancelled = false
    ){}
}
