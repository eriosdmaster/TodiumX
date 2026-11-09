<?php

declare(strict_types=1);

namespace raspberry\event;

final class EventPriority{
    public const LOWEST = 0;
    public const LOW = 1;
    public const NORMAL = 2;
    public const HIGH = 3;
    public const HIGHEST = 4;
    public const MONITOR = 5;

    public const ALL = [
        self::LOWEST, self::LOW, self::NORMAL, self::HIGH, self::HIGHEST, self::MONITOR
    ];

    private function __construct(){}
}
