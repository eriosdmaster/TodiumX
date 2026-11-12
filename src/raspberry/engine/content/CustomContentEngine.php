<?php

declare(strict_types=1);

namespace raspberry\engine\content;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\item\Item;
use raspberry\block\Block;
use raspberry\block\BlockState;
use raspberry\entity\Entity;
use raspberry\crafting\Recipe;

class CustomContentEngine implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    // Custom content registries
    private array $customItems = [];
    private array $customBlocks = [];
    private array $customEntities = [];
    private array $customRecipes = [];
    private array $customEnchantments = [];
    private array $customEffects = [];
    private array $customComponents = [];
    
    // Component types
    private array $itemComponents = [];
    private array $blockComponents = [];
    private array $entityComponents = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "CustomContentEngine";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("CustomContentEngine loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("CustomContentEngine enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("CustomContentEngine disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    // ==================== CUSTOM ITEMS ====================
    
    public function registerCustomItem(string $identifier, array $definition): bool {
        if (isset($this->customItems[$identifier])) {
            $this->logger->warning("Custom item '$identifier' already registered, overriding");
        }
        
        // Validate definition
        if (!isset($definition['minecraft:display_name'])) {
            $this->logger->warning("Custom item '$identifier' missing display_name");
        }
        
        $this->customItems[$identifier] = array_merge([
            'identifier' => $identifier,
            'components' => [],
            'creative_category' => 'items',
        ], $definition);
        
        $this->logger->info("Registered custom item: $identifier");
        return true;
    }
    
    public function unregisterCustomItem(string $identifier): bool {
        if (!isset($this->customItems[$identifier])) {
            return false;
        }
        
        unset($this->customItems[$identifier]);
        $this->logger->info("Unregistered custom item: $identifier");
        return true;
    }
    
    public function getCustomItem(string $identifier): ?array {
        return $this->customItems[$identifier] ?? null;
    }
    
    public function getAllCustomItems(): array {
        return $this->customItems;
    }
    
    public function createCustomItem(string $identifier, int $count = 1, array $nbt = []): ?Item {
        $definition = $this->getCustomItem($identifier);
        if ($definition === null) {
            return null;
        }
        
        // Create item with custom components
        $item = new Item($identifier, $count);
        
        // Apply components
        foreach ($definition['components'] as $componentName => $componentData) {
            $item->addComponent($componentName, $componentData);
        }
        
        // Apply NBT
        if (!empty($nbt)) {
            $item->setNbt($nbt);
        }
        
        return $item;
    }
    
    // ==================== WEAPONS & ARMOR ====================
    
    public function registerCustomWeapon(string $identifier, array $definition): bool {
        $definition['type'] = 'weapon';
        $definition['category'] = 'equipment';
        return $this->registerCustomItem($identifier, $definition);
    }
    
    public function registerCustomArmor(string $identifier, array $definition): bool {
        $definition['type'] = 'armor';
        $definition['category'] = 'equipment';
        return $this->registerCustomItem($identifier, $definition);
    }
    
    public function registerCustomTool(string $identifier, array $definition): bool {
        $definition['type'] = 'tool';
        $definition['category'] = 'equipment';
        return $this->registerCustomItem($identifier, $definition);
    }
    
    // ==================== CUSTOM BLOCKS ====================
    
    public function registerCustomBlock(string $identifier, array $definition): bool {
        if (isset($this->customBlocks[$identifier])) {
            $this->logger->warning("Custom block '$identifier' already registered, overriding");
        }
        
        $this->customBlocks[$identifier] = array_merge([
            'identifier' => $identifier,
            'states' => [],
            'components' => [],
            'properties' => [],
            'loot_table' => null,
            'collision' => true,
            'light_emission' => 0,
            'hardness' => 1.0,
            'resistance' => 1.0,
            'harvest_tool' => 'pickaxe',
            'harvest_level' => 1,
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
    
    public function registerBlockState(string $blockIdentifier, string $stateName, array $properties): bool {
        if (!isset($this->customBlocks[$blockIdentifier])) {
            return false;
        }
        
        $this->customBlocks[$blockIdentifier]['states'][$stateName] = $properties;
        return true;
    }
    
    // ==================== CUSTOM ENTITIES ====================
    
    public function registerCustomEntity(string $identifier, array $definition): bool {
        if (isset($this->customEntities[$identifier])) {
            $this->logger->warning("Custom entity '$identifier' already registered, overriding");
        }
        
        $this->customEntities[$identifier] = array_merge([
            'identifier' => $identifier,
            'components' => [],
            'attributes' => [],
            'behaviors' => [],
            'animations' => [],
            'spawn_conditions' => [],
            'loot_table' => null,
        ], $definition);
        
        $this->logger->info("Registered custom entity: $identifier");
        return true;
    }
    
    public function unregisterCustomEntity(string $identifier): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        unset($this->customEntities[$identifier]);
        $this->logger->info("Unregistered custom entity: $identifier");
        return true;
    }
    
    public function getCustomEntity(string $identifier): ?array {
        return $this->customEntities[$identifier] ?? null;
    }
    
    public function getAllCustomEntities(): array {
        return $this->customEntities;
    }
    
    // ==================== RECIPES ====================
    
    public function registerCustomRecipe(string $identifier, array $definition): bool {
        if (isset($this->customRecipes[$identifier])) {
            $this->logger->warning("Custom recipe '$identifier' already registered, overriding");
        }
        
        $this->customRecipes[$identifier] = array_merge([
            'identifier' => $identifier,
            'type' => 'crafting_shaped',
            'pattern' => [],
            'key' => [],
            'result' => [],
            'tags' => [],
            'group' => '',
        ], $definition);
        
        $this->logger->info("Registered custom recipe: $identifier");
        return true;
    }
    
    public function unregisterCustomRecipe(string $identifier): bool {
        if (!isset($this->customRecipes[$identifier])) {
            return false;
        }
        
        unset($this->customRecipes[$identifier]);
        $this->logger->info("Unregistered custom recipe: $identifier");
        return true;
    }
    
    public function getCustomRecipe(string $identifier): ?array {
        return $this->customRecipes[$identifier] ?? null;
    }
    
    public function getAllCustomRecipes(): array {
        return $this->customRecipes;
    }
    
    public function registerShapedRecipe(string $identifier, array $pattern, array $key, array $result, array $tags = []): bool {
        return $this->registerCustomRecipe($identifier, [
            'type' => 'crafting_shaped',
            'pattern' => $pattern,
            'key' => $key,
            'result' => $result,
            'tags' => $tags,
        ]);
    }
    
    public function registerShapelessRecipe(string $identifier, array $ingredients, array $result, array $tags = []): bool {
        return $this->registerCustomRecipe($identifier, [
            'type' => 'crafting_shapeless',
            'ingredients' => $ingredients,
            'result' => $result,
            'tags' => $tags,
        ]);
    }
    
    public function registerSmeltingRecipe(string $identifier, string $input, array $result, float $experience = 0.1, int $cookingTime = 200): bool {
        return $this->registerCustomRecipe($identifier, [
            'type' => 'smelting',
            'input' => $input,
            'result' => $result,
            'experience' => $experience,
            'cooking_time' => $cookingTime,
        ]);
    }
    
    // ==================== ENCHANTMENTS ====================
    
    public function registerCustomEnchantment(string $identifier, array $definition): bool {
        if (isset($this->customEnchantments[$identifier])) {
            $this->logger->warning("Custom enchantment '$identifier' already registered, overriding");
        }
        
        $this->customEnchantments[$identifier] = array_merge([
            'identifier' => $identifier,
            'name' => $identifier,
            'max_level' => 1,
            'min_cost' => [1],
            'max_cost' => [1],
            'anvil_cost' => 1,
            'supported_items' => [],
            'primary_items' => [],
            'weight' => 1,
            'cursed' => false,
            'treasure' => false,
            'tradeable' => true,
            'discoverable' => true,
            'incompatible_with' => [],
            'effects' => [],
        ], $definition);
        
        $this->logger->info("Registered custom enchantment: $identifier");
        return true;
    }
    
    public function getCustomEnchantment(string $identifier): ?array {
        return $this->customEnchantments[$identifier] ?? null;
    }
    
    public function getAllCustomEnchantments(): array {
        return $this->customEnchantments;
    }
    
    // ==================== EFFECTS ====================
    
    public function registerCustomEffect(string $identifier, array $definition): bool {
        if (isset($this->customEffects[$identifier])) {
            $this->logger->warning("Custom effect '$identifier' already registered, overriding");
        }
        
        $this->customEffects[$identifier] = array_merge([
            'identifier' => $identifier,
            'name' => $identifier,
            'color' => 0xFFFFFF,
            'instant' => false,
            'beneficial' => true,
            'particles' => true,
            'display_icon' => null,
            'modifiers' => [],
            'on_apply' => null,
            'on_remove' => null,
            'on_tick' => null,
        ], $definition);
        
        $this->logger->info("Registered custom effect: $identifier");
        return true;
    }
    
    public function getCustomEffect(string $identifier): ?array {
        return $this->customEffects[$identifier] ?? null;
    }
    
    public function getAllCustomEffects(): array {
        return $this->customEffects;
    }
    
    // ==================== COMPONENTS ====================
    
    public function registerItemComponent(string $name, callable $handler): void {
        $this->itemComponents[$name] = $handler;
        $this->logger->info("Registered item component: $name");
    }
    
    public function registerBlockComponent(string $name, callable $handler): void {
        $this->blockComponents[$name] = $handler;
        $this->logger->info("Registered block component: $name");
    }
    
    public function registerEntityComponent(string $name, callable $handler): void {
        $this->entityComponents[$name] = $handler;
        $this->logger->info("Registered entity component: $name");
    }
    
    public function getItemComponent(string $name): ?callable {
        return $this->itemComponents[$name] ?? null;
    }
    
    public function getBlockComponent(string $name): ?callable {
        return $this->blockComponents[$name] ?? null;
    }
    
    public function getEntityComponent(string $name): ?callable {
        return $this->entityComponents[$name] ?? null;
    }
    
    // ==================== CREATIVE INVENTORY ====================
    
    public function addToCreativeInventory(string $identifier, string $category = 'items'): bool {
        if (isset($this->customItems[$identifier])) {
            $this->customItems[$identifier]['creative_category'] = $category;
            return true;
        }
        
        if (isset($this->customBlocks[$identifier])) {
            $this->customBlocks[$identifier]['creative_category'] = $category;
            return true;
        }
        
        return false;
    }
    
    // ==================== RESOURCE PACK INTEGRATION ====================
    
    public function registerTexture(string $identifier, string $texturePath): void {
        // This would integrate with the resource pack system
        $this->logger->info("Registered texture for $identifier: $texturePath");
    }
    
    public function registerModel(string $identifier, string $modelPath): void {
        // This would integrate with the resource pack system
        $this->logger->info("Registered model for $identifier: $modelPath");
    }
}
