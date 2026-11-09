<?php

declare(strict_types=1);

namespace raspberry\pack;

/**
 * A .mcfunction file: one command per line.
 */
final class McFunction{

    /**
     * @param list<string> $lines commands without the leading slash; blank lines and comments are already gone
     */
    public function __construct(
        private string $id,
        private BehaviorPack $pack,
        private array $lines
    ){}

    /**
     * The path inside the pack's functions folder without ".mcfunction", e.g. "arena/start".
     */
    public function getId() : string{
        return $this->id;
    }

    public function getPack() : BehaviorPack{
        return $this->pack;
    }

    /**
     * @return list<string>
     */
    public function getLines() : array{
        return $this->lines;
    }
}
