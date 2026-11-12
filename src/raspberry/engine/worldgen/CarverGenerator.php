<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

interface CarverGenerator {
    public function carve(int $chunkX, int $chunkZ, int $seed, array &$blocks): void;
    public function getCarvers(): array;
}
