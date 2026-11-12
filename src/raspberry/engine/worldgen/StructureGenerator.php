<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

interface StructureGenerator {
    public function generate(int $chunkX, int $chunkZ, int $seed, array &$blocks): void;
    public function getStructures(): array;
    public function canGenerateAt(int $chunkX, int $chunkZ, int $seed): bool;
}
