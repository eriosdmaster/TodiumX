<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

interface BiomeProvider {
    public function getBiomeAt(int $x, int $y, int $z, int $seed): int;
    public function getBiomeName(int $biomeId): string;
    public function getAllBiomes(): array;
}
