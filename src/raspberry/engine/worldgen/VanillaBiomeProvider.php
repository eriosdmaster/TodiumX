<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

class VanillaBiomeProvider implements BiomeProvider {
    private array $biomes = [
        0 => 'ocean',
        1 => 'plains',
        2 => 'desert',
        3 => 'mountains',
        4 => 'forest',
        5 => 'taiga',
        6 => 'swamp',
        7 => 'river',
        8 => 'nether_wastes',
        9 => 'the_end',
        // ... more biomes
    ];
    
    public function getBiomeAt(int $x, int $y, int $z, int $seed): int {
        // Simplified biome generation
        // In reality, this would use noise functions
        return 1; // plains
    }
    
    public function getBiomeName(int $biomeId): string {
        return $this->biomes[$biomeId] ?? 'unknown';
    }
    
    public function getAllBiomes(): array {
        return $this->biomes;
    }
}
