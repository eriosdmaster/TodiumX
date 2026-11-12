<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

use raspberry\world\generator\Generator;

class DimensionGenerator implements Generator {
    private DimensionDefinition $definition;
    private WorldGeneratorEngine $engine;
    private int $nativeHandle = 0;
    
    public function __construct(DimensionDefinition $definition) {
        $this->definition = $definition;
        $this->engine = $definition->engine ?? null; // Would be set by engine
    }
    
    public function generateChunk(int $chunkX, int $chunkZ): void {
        // Generate chunk using registered generators
        // This would integrate with the native library
    }
    
    public function getSpawnPosition(): array {
        return [0, 100, 0];
    }
}
