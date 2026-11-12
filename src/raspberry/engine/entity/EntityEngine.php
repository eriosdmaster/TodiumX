<?php

declare(strict_types=1);

namespace raspberry\engine\entity;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\entity\Entity;
use raspberry\entity\CustomEntity;
use raspberry\math\Vector3;
use raspberry\world\World;
use raspberry\item\Item;

class EntityEngine implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    // Entity registries
    private array $customEntities = [];
    private array $entityModels = [];
    private array $entityTextures = [];
    private array $entityAttributes = [];
    private array $entityAI = [];
    private array $entityBehaviors = [];
    private array $entityAnimations = [];
    private array $entityBosses = [];
    private array $entityMounts = [];
    private array $entityNPCs = [];
    private array $entityCollisions = [];
    private array $entityPersistence = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "EntityEngine";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("EntityEngine loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("EntityEngine enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("EntityEngine disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    // ==================== ENTITY REGISTRATION ====================
    
    public function registerCustomEntity(string $identifier, array $definition): bool {
        if (isset($this->customEntities[$identifier])) {
            $this->logger->warning("Custom entity '$identifier' already registered, overriding");
        }
        
        $this->customEntities[$identifier] = array_merge([
            'identifier' => $identifier,
            'name' => $identifier,
            'model' => null,
            'textures' => [],
            'attributes' => [
                'max_health' => 20,
                'speed' => 0.1,
                'follow_range' => 16,
                'knockback_resistance' => 0.0,
                'attack_damage' => 2,
                'armor' => 0,
                'armor_toughness' => 0,
            ],
            'ai' => [],
            'behaviors' => [],
            'animations' => [],
            'collision' => [
                'width' => 0.6,
                'height' => 1.8,
                'step_height' => 0.6,
            ],
            'spawn_conditions' => [],
            'loot_table' => null,
            'persistent' => false,
            'boss' => false,
            'mount' => false,
            'npc' => false,
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
    
    // ==================== MODELS & TEXTURES ====================
    
    public function registerEntityModel(string $identifier, string $modelPath): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityModels[$identifier] = $modelPath;
        $this->customEntities[$identifier]['model'] = $modelPath;
        
        $this->logger->info("Registered model for entity: $identifier");
        return true;
    }
    
    public function registerEntityTexture(string $identifier, string $texturePath, string $layer = 'default'): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityTextures[$identifier][$layer] = $texturePath;
        $this->customEntities[$identifier]['textures'][$layer] = $texturePath;
        
        return true;
    }
    
    public function getEntityModel(string $identifier): ?string {
        return $this->entityModels[$identifier] ?? null;
    }
    
    public function getEntityTextures(string $identifier): array {
        return $this->entityTextures[$identifier] ?? [];
    }
    
    // ==================== ATTRIBUTES ====================
    
    public function setEntityAttribute(string $identifier, string $attribute, mixed $value): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->customEntities[$identifier]['attributes'][$attribute] = $value;
        return true;
    }
    
    public function getEntityAttribute(string $identifier, string $attribute): mixed {
        return $this->customEntities[$identifier]['attributes'][$attribute] ?? null;
    }
    
    public function registerAttributeModifier(string $identifier, string $attribute, AttributeModifier $modifier): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        if (!isset($this->customEntities[$identifier]['attribute_modifiers'])) {
            $this->customEntities[$identifier]['attribute_modifiers'] = [];
        }
        
        $this->customEntities[$identifier]['attribute_modifiers'][$attribute][] = $modifier;
        return true;
    }
    
    // ==================== AI SYSTEM ====================
    
    public function registerAI(string $identifier, string $aiName, callable $aiHandler): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityAI[$identifier][$aiName] = $aiHandler;
        $this->customEntities[$identifier]['ai'][$aiName] = true;
        
        $this->logger->info("Registered AI '$aiName' for entity: $identifier");
        return true;
    }
    
    public function getAI(string $identifier, string $aiName): ?callable {
        return $this->entityAI[$identifier][$aiName] ?? null;
    }
    
    public function addAIGoal(string $identifier, string $goalName, int $priority, callable $goalHandler): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        if (!isset($this->customEntities[$identifier]['goals'])) {
            $this->customEntities[$identifier]['goals'] = [];
        }
        
        $this->customEntities[$identifier]['goals'][$goalName] = [
            'priority' => $priority,
            'handler' => $goalHandler,
        ];
        
        return true;
    }
    
    // ==================== BEHAVIOR SYSTEM ====================
    
    public function registerBehavior(string $identifier, string $behaviorName, callable $behaviorHandler): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityBehaviors[$identifier][$behaviorName] = $behaviorHandler;
        $this->customEntities[$identifier]['behaviors'][$behaviorName] = true;
        
        $this->logger->info("Registered behavior '$behaviorName' for entity: $identifier");
        return true;
    }
    
    public function getBehavior(string $identifier, string $behaviorName): ?callable {
        return $this->entityBehaviors[$identifier][$behaviorName] ?? null;
    }
    
    public function addBehavior(string $identifier, string $behaviorName, array $config = []): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        if (!isset($this->customEntities[$identifier]['behavior_config'])) {
            $this->customEntities[$identifier]['behavior_config'] = [];
        }
        
        $this->customEntities[$identifier]['behavior_config'][$behaviorName] = $config;
        return true;
    }
    
    // ==================== ANIMATIONS ====================
    
    public function registerAnimation(string $identifier, string $animationName, array $animationData): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityAnimations[$identifier][$animationName] = $animationData;
        $this->customEntities[$identifier]['animations'][$animationName] = $animationData;
        
        $this->logger->info("Registered animation '$animationName' for entity: $identifier");
        return true;
    }
    
    public function getAnimation(string $identifier, string $animationName): ?array {
        return $this->entityAnimations[$identifier][$animationName] ?? null;
    }
    
    public function playAnimation(Entity $entity, string $animationName): bool {
        $identifier = $entity->getIdentifier();
        $animation = $this->getAnimation($identifier, $animationName);
        
        if ($animation === null) {
            return false;
        }
        
        // This would send animation packet to clients
        $this->server->getLogger()->debug("Playing animation '$animationName' for entity $identifier");
        return true;
    }
    
    // ==================== BOSS SYSTEM ====================
    
    public function registerBoss(string $identifier, array $bossConfig): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityBosses[$identifier] = array_merge([
            'name' => $identifier,
            'health_bar_color' => 'red',
            'health_bar_style' => 'progress',
            'music' => null,
            'fog' => null,
            'min_players' => 1,
        ], $bossConfig);
        
        $this->customEntities[$identifier]['boss'] = true;
        
        $this->logger->info("Registered boss entity: $identifier");
        return true;
    }
    
    public function getBossConfig(string $identifier): ?array {
        return $this->entityBosses[$identifier] ?? null;
    }
    
    public function showBossBar(Entity $entity, array $players = []): void {
        $identifier = $entity->getIdentifier();
        $config = $this->getBossConfig($identifier);
        
        if ($config === null) {
            return;
        }
        
        // Send boss bar packet to players
        // This would use the Bedrock boss bar packet
    }
    
    public function updateBossBar(Entity $entity): void {
        $identifier = $entity->getIdentifier();
        $config = $this->getBossConfig($identifier);
        
        if ($config === null) {
            return;
        }
        
        // Update boss bar health
    }
    
    public function hideBossBar(Entity $entity): void {
        $identifier = $entity->getIdentifier();
        $config = $this->getBossConfig($identifier);
        
        if ($config === null) {
            return;
        }
        
        // Hide boss bar
    }
    
    // ==================== MOUNTS ====================
    
    public function registerMount(string $identifier, array $mountConfig): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityMounts[$identifier] = array_merge([
            'can_ride' => true,
            'max_riders' => 1,
            'rider_positions' => [[0, 0.5, 0]],
            'control_type' => 'player', // player, ai, none
            'speed_modifier' => 1.0,
            'jump_strength' => 0.5,
        ], $mountConfig);
        
        $this->customEntities[$identifier]['mount'] = true;
        
        $this->logger->info("Registered mount entity: $identifier");
        return true;
    }
    
    public function getMountConfig(string $identifier): ?array {
        return $this->entityMounts[$identifier] ?? null;
    }
    
    public function mountEntity(Entity $rider, Entity $mount): bool {
        $mountConfig = $this->getMountConfig($mount->getIdentifier());
        
        if ($mountConfig === null || !$mountConfig['can_ride']) {
            return false;
        }
        
        // Check rider limit
        // This would check current riders
        
        // Start riding
        $rider->startRiding($mount);
        return true;
    }
    
    // ==================== NPC SYSTEM ====================
    
    public function registerNPC(string $identifier, array $npcConfig): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityNPCs[$identifier] = array_merge([
            'name' => $identifier,
            'dialogue' => [],
            'trades' => [],
            'quests' => [],
            'interact_distance' => 3.0,
            'look_at_player' => true,
            'rotate_to_player' => true,
        ], $npcConfig);
        
        $this->customEntities[$identifier]['npc'] = true;
        
        $this->logger->info("Registered NPC entity: $identifier");
        return true;
    }
    
    public function getNPCConfig(string $identifier): ?array {
        return $this->entityNPCs[$identifier] ?? null;
    }
    
    public function addDialogue(string $identifier, string $dialogueId, array $dialogueData): bool {
        if (!isset($this->entityNPCs[$identifier])) {
            return false;
        }
        
        $this->entityNPCs[$identifier]['dialogue'][$dialogueId] = $dialogueData;
        return true;
    }
    
    public function addTrade(string $identifier, string $tradeId, array $tradeData): bool {
        if (!isset($this->entityNPCs[$identifier])) {
            return false;
        }
        
        $this->entityNPCs[$identifier]['trades'][$tradeId] = $tradeData;
        return true;
    }
    
    public function interactNPC(Entity $npc, Entity $player): void {
        $identifier = $npc->getIdentifier();
        $config = $this->getNPCConfig($identifier);
        
        if ($config === null) {
            return;
        }
        
        // Open NPC interaction UI
        // This would show dialogue, trades, quests
    }
    
    // ==================== COLLISION SYSTEM ====================
    
    public function registerCollision(string $identifier, array $collisionConfig): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityCollisions[$identifier] = array_merge([
            'width' => 0.6,
            'height' => 1.8,
            'step_height' => 0.6,
            'pushable' => true,
            'push_others' => true,
            'collide_with_entities' => true,
            'collide_with_blocks' => true,
            'custom_hitbox' => null,
        ], $collisionConfig);
        
        $this->customEntities[$identifier]['collision'] = $this->entityCollisions[$identifier];
        
        $this->logger->info("Registered collision for entity: $identifier");
        return true;
    }
    
    public function getCollisionConfig(string $identifier): ?array {
        return $this->entityCollisions[$identifier] ?? null;
    }
    
    public function checkCollision(Entity $entity, Vector3 $position): bool {
        // Custom collision checking
        return false;
    }
    
    // ==================== PERSISTENCE ====================
    
    public function enablePersistence(string $identifier, array $persistenceConfig = []): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->entityPersistence[$identifier] = array_merge([
            'save_inventory' => true,
            'save_attributes' => true,
            'save_position' => true,
            'save_ai_state' => true,
            'save_custom_data' => [],
        ], $persistenceConfig);
        
        $this->customEntities[$identifier]['persistent'] = true;
        
        $this->logger->info("Enabled persistence for entity: $identifier");
        return true;
    }
    
    public function saveEntity(Entity $entity): array {
        $identifier = $entity->getIdentifier();
        $config = $this->entityPersistence[$identifier] ?? [];
        
        $data = [
            'identifier' => $identifier,
            'position' => [
                'x' => $entity->getX(),
                'y' => $entity->getY(),
                'z' => $entity->getZ(),
            ],
        ];
        
        if ($config['save_inventory'] && $entity instanceof \raspberry\entity\LivingEntity) {
            $data['inventory'] = $entity->getInventory()?->getContents() ?? [];
        }
        
        if ($config['save_attributes']) {
            $data['attributes'] = [];
            foreach ($entity->getAttributes() as $attr => $value) {
                $data['attributes'][$attr] = $value;
            }
        }
        
        if (!empty($config['save_custom_data'])) {
            foreach ($config['save_custom_data'] as $key) {
                $data[$key] = $entity->getNamedTag()->getTag($key) ?? null;
            }
        }
        
        return $data;
    }
    
    public function loadEntity(string $identifier, array $data, World $world): ?Entity {
        // This would create and restore entity from saved data
        return null;
    }
    
    // ==================== SPAWNING ====================
    
    public function spawnEntity(string $identifier, Vector3 $position, World $world, array $extraData = []): ?Entity {
        $definition = $this->getCustomEntity($identifier);
        if ($definition === null) {
            return null;
        }
        
        // Create entity with custom properties
        // This would use the server's entity creation system
        return null;
    }
    
    public function addSpawnCondition(string $identifier, callable $condition): bool {
        if (!isset($this->customEntities[$identifier])) {
            return false;
        }
        
        $this->customEntities[$identifier]['spawn_conditions'][] = $condition;
        return true;
    }
    
    public function canSpawn(string $identifier, World $world, Vector3 $position): bool {
        $definition = $this->getCustomEntity($identifier);
        if ($definition === null) {
            return false;
        }
        
        foreach ($definition['spawn_conditions'] as $condition) {
            if (is_callable($condition) && !$condition($world, $position)) {
                return false;
            }
        }
        
        return true;
    }
}
