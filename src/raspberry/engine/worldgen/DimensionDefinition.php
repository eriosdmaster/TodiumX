<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

class DimensionDefinition {
    public function __construct(
        public readonly string $name,
        public readonly string $biomeProvider = 'vanilla',
        public readonly string $structureGenerator = 'vanilla',
        public readonly string $carverGenerator = 'vanilla',
        public readonly string $terrainGenerator = 'vanilla',
        public readonly array $customBiomes = [],
        public readonly array $customStructures = [],
        public readonly array $generatorOptions = [],
        public readonly int $minY = -64,
        public readonly int $maxY = 320,
        public readonly bool $hasCeiling = false,
        public readonly bool $hasSkyLight = true,
        public readonly float $ambientLight = 0.0,
        public readonly string $worldType = 'normal',
    ) {}
}
