<?php

declare(strict_types=1);

namespace raspberry\engine\worldgen;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\world\World;
use raspberry\native\Native;

class WorldGeneratorEngine implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    // Generator registries
    private array $biomeProviders = [];
    private array $structureGenerators = [];
    private array $carverGenerators = [];
    private array $dimensionGenerators = [];
    private array $terrainGenerators = [];
    
    // Default generators
    private string $defaultBiomeProvider = 'vanilla';
    private string $defaultStructureGenerator = 'vanilla';
    private string $defaultCarverGenerator = 'vanilla';
    private string $defaultTerrainGenerator = 'vanilla';
    
    // Custom dimensions
    private array $customDimensions = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "WorldGeneratorEngine";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("WorldGeneratorEngine loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("WorldGeneratorEngine enabled");
        
        // Register vanilla generators
        $this->registerVanillaGenerators();
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("WorldGeneratorEngine disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    private function registerVanillaGenerators(): void {
        // These would be the built-in generators
        $this->biomeProviders['vanilla'] = new VanillaBiomeProvider();
        $this->structureGenerators['vanilla'] = new VanillaStructureGenerator();
        $this->carverGenerators['vanilla'] = new VanillaCarverGenerator();
        $this->terrainGenerators['vanilla'] = new VanillaTerrainGenerator();
    }
    
    // ==================== BIOME PROVIDERS ====================
    
    public function registerBiomeProvider(string $name, BiomeProvider $provider): void {
        $this->biomeProviders[$name] = $provider;
        $this->logger->info("Registered biome provider: $name");
    }
    
    public function unregisterBiomeProvider(string $name): bool {
        if (!isset($this->biomeProviders[$name])) {
            return false;
        }
        
        if ($this->defaultBiomeProvider === $name) {
            $this->logger->warning("Cannot unregister default biome provider");
            return false;
        }
        
        unset($this->biomeProviders[$name]);
        $this->logger->info("Unregistered biome provider: $name");
        return true;
    }
    
    public function getBiomeProvider(string $name): ?BiomeProvider {
        return $this->biomeProviders[$name] ?? null;
    }
    
    public function setDefaultBiomeProvider(string $name): bool {
        if (!isset($this->biomeProviders[$name])) {
            return false;
        }
        
        $this->defaultBiomeProvider = $name;
        $this->logger->info("Set default biome provider to: $name");
        return true;
    }
    
    public function getDefaultBiomeProvider(): BiomeProvider {
        return $this->biomeProviders[$this->defaultBiomeProvider] ?? new VanillaBiomeProvider();
    }
    
    // ==================== STRUCTURE GENERATORS ====================
    
    public function registerStructureGenerator(string $name, StructureGenerator $generator): void {
        $this->structureGenerators[$name] = $generator;
        $this->logger->info("Registered structure generator: $name");
    }
    
    public function unregisterStructureGenerator(string $name): bool {
        if (!isset($this->structureGenerators[$name])) {
            return false;
        }
        
        if ($this->defaultStructureGenerator === $name) {
            $this->logger->warning("Cannot unregister default structure generator");
            return false;
        }
        
        unset($this->structureGenerators[$name]);
        $this->logger->info("Unregistered structure generator: $name");
        return true;
    }
    
    public function getStructureGenerator(string $name): ?StructureGenerator {
        return $this->structureGenerators[$name] ?? null;
    }
    
    public function setDefaultStructureGenerator(string $name): bool {
        if (!isset($this->structureGenerators[$name])) {
            return false;
        }
        
        $this->defaultStructureGenerator = $name;
        $this->logger->info("Set default structure generator to: $name");
        return true;
    }
    
    public function getDefaultStructureGenerator(): StructureGenerator {
        return $this->structureGenerators[$this->defaultStructureGenerator] ?? new VanillaStructureGenerator();
    }
    
    // ==================== CARVER GENERATORS ====================
    
    public function registerCarverGenerator(string $name, CarverGenerator $generator): void {
        $this->carverGenerators[$name] = $generator;
        $this->logger->info("Registered carver generator: $name");
    }
    
    public function unregisterCarverGenerator(string $name): bool {
        if (!isset($this->carverGenerators[$name])) {
            return false;
        }
        
        if ($this->defaultCarverGenerator === $name) {
            $this->logger->warning("Cannot unregister default carver generator");
            return false;
        }
        
        unset($this->carverGenerators[$name]);
        $this->logger->info("Unregistered carver generator: $name");
        return true;
    }
    
    public function getCarverGenerator(string $name): ?CarverGenerator {
        return $this->carverGenerators[$name] ?? null;
    }
    
    public function setDefaultCarverGenerator(string $name): bool {
        if (!isset($this->carverGenerators[$name])) {
            return false;
        }
        
        $this->defaultCarverGenerator = $name;
        $this->logger->info("Set default carver generator to: $name");
        return true;
    }
    
    public function getDefaultCarverGenerator(): CarverGenerator {
        return $this->carverGenerators[$this->defaultCarverGenerator] ?? new VanillaCarverGenerator();
    }
    
    // ==================== TERRAIN GENERATORS ====================
    
    public function registerTerrainGenerator(string $name, TerrainGenerator $generator): void {
        $this->terrainGenerators[$name] = $generator;
        $this->logger->info("Registered terrain generator: $name");
    }
    
    public function unregisterTerrainGenerator(string $name): bool {
        if (!isset($this->terrainGenerators[$name])) {
            return false;
        }
        
        if ($this->defaultTerrainGenerator === $name) {
            $this->logger->warning("Cannot unregister default terrain generator");
            return false;
        }
        
        unset($this->terrainGenerators[$name]);
        $this->logger->info("Unregistered terrain generator: $name");
        return true;
    }
    
    public function getTerrainGenerator(string $name): ?TerrainGenerator {
        return $this->terrainGenerators[$name] ?? null;
    }
    
    public function setDefaultTerrainGenerator(string $name): bool {
        if (!isset($this->terrainGenerators[$name])) {
            return false;
        }
        
        $this->defaultTerrainGenerator = $name;
        $this->logger->info("Set default terrain generator to: $name");
        return true;
    }
    
    public function getDefaultTerrainGenerator(): TerrainGenerator {
        return $this->terrainGenerators[$this->defaultTerrainGenerator] ?? new VanillaTerrainGenerator();
    }
    
    // ==================== DIMENSIONS ====================
    
    public function registerDimension(string $name, DimensionDefinition $definition): void {
        $this->customDimensions[$name] = $definition;
        $this->logger->info("Registered custom dimension: $name");
    }
    
    public function unregisterDimension(string $name): bool {
        if (!isset($this->customDimensions[$name])) {
            return false;
        }
        
        unset($this->customDimensions[$name]);
        $this->logger->info("Unregistered custom dimension: $name");
        return true;
    }
    
    public function getDimension(string $name): ?DimensionDefinition {
        return $this->customDimensions[$name] ?? null;
    }
    
    public function getAllDimensions(): array {
        return $this->customDimensions;
    }
    
    public function createWorldFromDimension(string $worldName, string $dimensionName, array $options = []): ?World {
        $definition = $this->getDimension($dimensionName);
        if ($definition === null) {
            $this->logger->warning("Dimension '$dimensionName' not found");
            return null;
        }
        
        // Create world with custom generator
        $generator = new DimensionGenerator($definition);
        
        // In a real implementation, this would create a new World instance
        // For now, we'll return a placeholder
        $this->logger->info("Creating world '$worldName' from dimension '$dimensionName'");
        
        return null; // Would return actual World instance
    }
    
    // ==================== NATIVE INTEGRATION ====================
    
    public function createNativeGenerator(array $config): int {
        if (!Native::isLoaded()) {
            return 0;
        }
        
        // Convert config to native generator states
        $states = $this->configToNativeStates($config);
        
        if (empty($states)) {
            return 0;
        }
        
        $seed = $config['seed'] ?? mt_rand();
        return Native::createGenerator($seed, $states, count($states));
    }
    
    public function generateChunkNative(int $generatorHandle, int $chunkX, int $chunkZ): int {
        if (!Native::isLoaded()) {
            return 0;
        }
        
        return Native::generateChunk($generatorHandle, $chunkX, $chunkZ);
    }
    
    public function findSpawnNative(int $generatorHandle): ?array {
        if (!Native::isLoaded()) {
            return null;
        }
        
        $result = Native::findSpawn($generatorHandle);
        if ($result === 0) {
            return null;
        }
        
        // Decode X, Y, Z from result
        $x = $result & 0xFFFFFFFF;
        $y = ($result >> 32) & 0xFFFFFFFF;
        $z = ($result >> 64) & 0xFFFFFFFF;
        
        // Handle signed integers
        if ($x & 0x80000000) $x -= 0x100000000;
        if ($y & 0x80000000) $y -= 0x100000000;
        if ($z & 0x80000000) $z -= 0x100000000;
        
        return [$x, $y, $z];
    }
    
    private function configToNativeStates(array $config): array {
        // Convert block state strings to native block IDs
        // This would use the blocks.rs mapping
        $blockMapping = [
            'minecraft:air' => 0,
            'minecraft:stone' => 1,
            'minecraft:grass_block' => 2,
            'minecraft:dirt' => 3,
            // ... more mappings
        ];
        
        $states = [];
        foreach ($config['blocks'] ?? [] as $blockName) {
            if (isset($blockMapping[$blockName])) {
                $states[] = $blockMapping[$blockName];
            }
        }
        
        return $states;
    }
    
    // ==================== PRE-NORMAL GENERATOR ====================
    
    public function createPreNormalGenerator(int $seed, array $states): int {
        if (!Native::isLoaded()) {
            return 0;
        }
        
        return Native::createPreNormalGenerator($seed, $states, count($states));
    }
    
    public function generatePreNormalChunk(int $generatorHandle, int $chunkX, int $chunkZ): int {
        if (!Native::isLoaded()) {
            return 0;
        }
        
        return Native::generatePreNormalChunk($generatorHandle, $chunkX, $chunkZ);
    }
    
    public function findPreNormalSpawn(int $generatorHandle): ?array {
        if (!Native::isLoaded()) {
            return null;
        }
        
        $result = Native::findPreNormalSpawn($generatorHandle);
        if ($result === 0) {
            return null;
        }
        
        // Decode X, Y, Z
        $x = $result & 0xFFFFFFFF;
        $y = ($result >> 32) & 0xFFFFFFFF;
        $z = ($result >> 64) & 0xFFFFFFFF;
        
        if ($x & 0x80000000) $x -= 0x100000000;
        if ($y & 0x80000000) $y -= 0x100000000;
        if ($z & 0x80000000) $z -= 0x100000000;
        
        return [$x, $y, $z];
    }
    
    public function freeGenerator(int $handle): void {
        if (Native::isLoaded()) {
            Native::freeGenerator($handle);
        }
    }
}
