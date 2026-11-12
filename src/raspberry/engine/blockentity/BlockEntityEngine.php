<?php

declare(strict_types=1);

namespace raspberry\engine\blockentity;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\block\Block;
use raspberry\block\BlockState;
use raspberry\world\World;
use raspberry\math\Vector3;
use raspberry\item\Item;

class BlockEntityEngine implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    // Block registries
    private array $customBlocks = [];
    private array $blockStates = [];
    private array $blockComponents = [];
    private array $blockHitboxes = [];
    private array $blockModels = [];
    private array $blockTextures = [];
    private array $interactiveBlocks = [];
    private array $inventoryBlocks = [];
    private array $entityBlocks = [];
    private array $miningTools = [];
    private array $craftingRecipes = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "BlockEntityEngine";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("BlockEntityEngine loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("BlockEntityEngine enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("BlockEntityEngine disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    // ==================== BLOCK REGISTRATION ====================
    
    public function registerCustomBlock(string $identifier, array $definition): bool {
        if (isset($this->customBlocks[$identifier])) {
            $this->logger->warning("Custom block '$identifier' already registered, overriding");
        }
        
        $this->customBlocks[$identifier] = array_merge([
            'identifier' => $identifier,
            'fallback' => 'minecraft:stone',
            'states' => [],
            'components' => [],
            'hitbox' => null,
            'model' => null,
            'textures' => [],
            'interactive' => false,
            'inventory' => false,
            'entity' => null,
            'loot_table' => null,
            'hardness' => 1.0,
            'resistance' => 1.0,
            'harvest_tool' => 'pickaxe',
            'harvest_level' => 1,
            'light_emission' => 0,
            'collision' => true,
        ], $definition);
        
        $this->logger->info("Registered custom block: $identifier");
        return true;
    }
    
    public function unregisterCustomBlock(string $identifier): bool {
        if (!isset($this->customBlocks[$identifier])) {
            return false;
        }
        
        unset($this->customBlocks[$identifier]);
        $this->logger->info("Unregistered custom block: $identifier");
        return true;
    }
    
    public function getCustomBlock(string $identifier): ?array {
        return $this->customBlocks[$identifier] ?? null;
    }
    
    public function getAllCustomBlocks(): array {
        return $this->customBlocks;
    }
    
    // ==================== BLOCK STATES ====================
    
    public function registerBlockState(string $blockIdentifier, string $stateName, array $properties): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $stateKey = $blockIdentifier . '[' . $stateName . ']';
        $this->blockStates[$stateKey] = array_merge([
            'block' => $blockIdentifier,
            'name' => $stateName,
            'properties' => $properties,
        ], $properties);
        
        $this->customBlocks[$blockIdentifier]['states'][$stateName] = $properties;
        
        $this->logger->info("Registered block state: $stateKey");
        return true;
    }
    
    public function getBlockState(string $blockIdentifier, string $stateName): ?array {
        $stateKey = $blockIdentifier . '[' . $stateName . ']';
        return $this->blockStates[$stateKey] ?? null;
    }
    
    // ==================== BLOCK COMPONENTS ====================
    
    public function registerBlockComponent(string $name, callable $handler): void {
        $this->blockComponents[$name] = $handler;
        $this->logger->info("Registered block component: $name");
    }
    
    public function getBlockComponent(string $name): ?callable {
        return $this->blockComponents[$name] ?? null;
    }
    
    public function addComponentToBlock(string $blockIdentifier, string $componentName, array $data = []): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->customBlocks[$blockIdentifier]['components'][$componentName] = $data;
        return true;
    }
    
    // ==================== HITBOXES & COLLISIONS ====================
    
    public function registerBlockHitbox(string $blockIdentifier, array $hitbox): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->blockHitboxes[$blockIdentifier] = $hitbox;
        $this->customBlocks[$blockIdentifier]['hitbox'] = $hitbox;
        
        $this->logger->info("Registered hitbox for block: $blockIdentifier");
        return true;
    }
    
    public function getBlockHitbox(string $blockIdentifier): ?array {
        return $this->blockHitboxes[$blockIdentifier] ?? null;
    }
    
    public function createCubeHitbox(float $minX, float $minY, float $minZ, float $maxX, float $maxY, float $maxZ): array {
        return [
            'type' => 'cube',
            'min' => [$minX, $minY, $minZ],
            'max' => [$maxX, $maxY, $maxZ],
        ];
    }
    
    public function createMultiCubeHitbox(array $cubes): array {
        return [
            'type' => 'multi_cube',
            'cubes' => $cubes,
        ];
    }
    
    // ==================== TEXTURES & MODELS ====================
    
    public function registerBlockTexture(string $blockIdentifier, string $face, string $texture): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->blockTextures[$blockIdentifier][$face] = $texture;
        $this->customBlocks[$blockIdentifier]['textures'][$face] = $texture;
        
        return true;
    }
    
    public function registerBlockModel(string $blockIdentifier, string $modelPath): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->blockModels[$blockIdentifier] = $modelPath;
        $this->customBlocks[$blockIdentifier]['model'] = $modelPath;
        
        $this->logger->info("Registered model for block: $blockIdentifier");
        return true;
    }
    
    public function getBlockModel(string $blockIdentifier): ?string {
        return $this->blockModels[$blockIdentifier] ?? null;
    }
    
    // ==================== INTERACTIVE BLOCKS ====================
    
    public function registerInteractiveBlock(string $blockIdentifier, array $interaction): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->interactiveBlocks[$blockIdentifier] = array_merge([
            'on_interact' => null,
            'on_right_click' => null,
            'on_left_click' => null,
            'on_sneak_click' => null,
            'cooldown' => 0,
        ], $interaction);
        
        $this->customBlocks[$blockIdentifier]['interactive'] = true;
        
        $this->logger->info("Registered interactive block: $blockIdentifier");
        return true;
    }
    
    public function getInteractiveBlock(string $blockIdentifier): ?array {
        return $this->interactiveBlocks[$blockIdentifier] ?? null;
    }
    
    public function handleBlockInteraction(string $blockIdentifier, string $action, array $context): mixed {
        $interaction = $this->getInteractiveBlock($blockIdentifier);
        if ($interaction === null) {
            return null;
        }
        
        $handler = $interaction[$action] ?? null;
        if ($handler !== null && is_callable($handler)) {
            return $handler($context);
        }
        
        return null;
    }
    
    // ==================== INVENTORY BLOCKS ====================
    
    public function registerInventoryBlock(string $blockIdentifier, array $inventoryConfig): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->inventoryBlocks[$blockIdentifier] = array_merge([
            'size' => 27,
            'type' => 'container',
            'on_open' => null,
            'on_close' => null,
            'on_change' => null,
            'drop_contents' => true,
            'shared' => false,
        ], $inventoryConfig);
        
        $this->customBlocks[$blockIdentifier]['inventory'] = true;
        
        $this->logger->info("Registered inventory block: $blockIdentifier");
        return true;
    }
    
    public function getInventoryBlock(string $blockIdentifier): ?array {
        return $this->inventoryBlocks[$blockIdentifier] ?? null;
    }
    
    public function openInventory(Player $player, string $blockIdentifier, Vector3 $position): bool {
        $config = $this->getInventoryBlock($blockIdentifier);
        if ($config === null) {
            return false;
        }
        
        // This would open the inventory UI for the player
        $handler = $config['on_open'] ?? null;
        if ($handler !== null && is_callable($handler)) {
            $handler($player, $position);
        }
        
        return true;
    }
    
    // ==================== BLOCK ENTITIES ====================
    
    public function registerBlockEntity(string $blockIdentifier, string $entityIdentifier, array $entityDefinition): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->entityBlocks[$blockIdentifier] = array_merge([
            'entity' => $entityIdentifier,
            'sync_data' => [],
            'tick_rate' => 1,
        ], $entityDefinition);
        
        $this->customBlocks[$blockIdentifier]['entity'] = $entityIdentifier;
        
        $this->logger->info("Registered block entity for block: $blockIdentifier -> $entityIdentifier");
        return true;
    }
    
    public function getBlockEntity(string $blockIdentifier): ?array {
        return $this->entityBlocks[$blockIdentifier] ?? null;
    }
    
    // ==================== CRAFTING RECIPES ====================
    
    public function registerCraftingRecipe(string $identifier, array $recipe): bool {
        if (isset($this->craftingRecipes[$identifier])) {
            $this->logger->warning("Crafting recipe '$identifier' already registered, overriding");
        }
        
        $this->craftingRecipes[$identifier] = array_merge([
            'identifier' => $identifier,
            'type' => 'crafting_shaped',
            'pattern' => [],
            'key' => [],
            'result' => [],
            'group' => '',
            'tags' => [],
        ], $recipe);
        
        $this->logger->info("Registered crafting recipe: $identifier");
        return true;
    }
    
    public function getCraftingRecipe(string $identifier): ?array {
        return $this->craftingRecipes[$identifier] ?? null;
    }
    
    public function getAllCraftingRecipes(): array {
        return $this->craftingRecipes;
    }
    
    // ==================== MINING & TOOLS ====================
    
    public function registerMiningTool(string $toolIdentifier, array $toolData): bool {
        if (isset($this->miningTools[$toolIdentifier])) {
            $this->logger->warning("Mining tool '$toolIdentifier' already registered, overriding");
        }
        
        $this->miningTools[$toolIdentifier] = array_merge([
            'identifier' => $toolIdentifier,
            'speed' => 1.0,
            'damage' => 1,
            'harvest_level' => 1,
            'effective_blocks' => [],
            'enchantments' => [],
        ], $toolData);
        
        $this->logger->info("Registered mining tool: $toolIdentifier");
        return true;
    }
    
    public function getMiningTool(string $toolIdentifier): ?array {
        return $this->miningTools[$toolIdentifier] ?? null;
    }
    
    public function calculateMiningSpeed(string $toolIdentifier, string $blockIdentifier): float {
        $tool = $this->getMiningTool($toolIdentifier);
        $block = $this->getCustomBlock($blockIdentifier);
        
        if ($tool === null || $block === null) {
            return 1.0;
        }
        
        $speed = $tool['speed'];
        
        // Check if tool is effective against this block
        if (in_array($blockIdentifier, $tool['effective_blocks'])) {
            $speed *= 2.0;
        }
        
        // Check harvest level
        $toolLevel = $tool['harvest_level'];
        $blockLevel = $block['harvest_level'] ?? 1;
        
        if ($toolLevel >= $blockLevel) {
            return $speed;
        }
        
        return $speed * 0.1; // Very slow if wrong tool
    }
}
