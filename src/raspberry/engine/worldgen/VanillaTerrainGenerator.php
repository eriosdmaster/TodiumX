<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

class VanillaTerrainGenerator implements TerrainGenerator {
    public function generateTerrain(int $chunkX, int $chunkZ, int $seed, array &$blocks, array &$biomes): void {
        // Terrain generation logic would go here
    }
    
    public function getHeightAt(int $x, int $z, int $seed): int {
        // Simplified height calculation
        return 64;
    }
}
