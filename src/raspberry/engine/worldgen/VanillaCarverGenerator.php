<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

class VanillaCarverGenerator implements CarverGenerator {
    private array $carvers = [
        'cave' => ['probability' => 0.03],
        'ravine' => ['probability' => 0.005],
        'canyon' => ['probability' => 0.001],
    ];
    
    public function carve(int $chunkX, int $chunkZ, int $seed, array &$blocks): void {
        // Carving logic would go here
    }
    
    public function getCarvers(): array {
        return array_keys($this->carvers);
    }
}
