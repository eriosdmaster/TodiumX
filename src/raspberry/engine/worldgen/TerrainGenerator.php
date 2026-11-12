<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

interface TerrainGenerator {
    public function generateTerrain(int $chunkX, int $chunkZ, int $seed, array &$blocks, array &$biomes): void;
    public function getHeightAt(int $x, int $z, int $seed): int;
}
