<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\player\Player;
use raspberry\network\mcpe\protocol\SpawnParticleEffectPacket;

class ParticlesAPI implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    private array $particleTypes = [];
    private array $customParticles = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "ParticlesAPI";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("ParticlesAPI loaded");
        $this->registerVanillaParticles();
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("ParticlesAPI enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("ParticlesAPI disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    private function registerVanillaParticles(): void {
        $vanillaParticles = [
            'explosion', 'large_explosion', 'huge_explosion', 'firework', 'firework_spark',
            'smoke', 'large_smoke', 'campfire_smoke', 'campfire_cosy_smoke', 'flame',
            'lava', 'drip_lava', 'water_splash', 'water_bubble', 'water_drip',
            'rain', 'snow', 'snowball', 'portal', 'end_portal', 'nether_portal',
            'enchant', 'enchant_table', 'spell', 'instant_spell', 'mob_spell',
            'mob_spell_ambient', 'witch_spell', 'totem', 'evocation_fang', 'raider_spell',
            'heart', 'angry_villager', 'happy_villager', 'item_slime', 'item_snowball',
            'item_cobweb', 'breaking', 'falling_dust', 'splash', 'bubble_pop',
            'current_down', 'bubble_column_up', 'sculk_charge', 'sculk_soul',
            'ash', 'crimson_spore', 'warped_spore', 'dripping_obsidian_tear',
            'falling_obsidian_tear', 'landing_obsidian_tear', 'reverse_portal',
            'white_ash', 'small_flame', 'flash', 'composter', 'soul_fire_flame',
            'glow', 'wax_on', 'wax_off', 'electric_spark', 'scrape', 'brush',
            'dripstone_water', 'dripstone_lava', 'falling_spore_blossom',
            'spore_blossom_air', 'ash_plume', 'falling_ash_plume', 'landing_ash_plume',
            'vibration', 'glow_squid_ink', 'glow_ink', 'wither_heart',
            'block_marker', 'item_marker', 'arrow_marker', 'electric_spark',
        ];
        
        foreach ($vanillaParticles as $particle) {
            $this->particleTypes[$particle] = true;
        }
    }
    
    public function registerParticle(string $name, array $data = []): void {
        $this->customParticles[$name] = array_merge([
            'name' => $name,
            'type' => 'custom',
            'data' => $data,
        ], $data);
        
        $this->logger->info("Registered custom particle: $name");
    }
    
    public function isVanillaParticle(string $name): bool {
        return isset($this->particleTypes[$name]);
    }
    
    public function isCustomParticle(string $name): bool {
        return isset($this->customParticles[$name]);
    }
    
    public function spawnParticle(string $name, float $x, float $y, float $z, array $data = [], ?Player $targetPlayer = null): void {
        $packet = new SpawnParticleEffectPacket();
        $packet->identifier = $name;
        $packet->position = [$x, $y, $z];
        $packet->data = $data;
        
        if ($targetPlayer !== null) {
            $targetPlayer->sendDataPacket($packet);
        } else {
            $this->server->broadcastPacket($packet);
        }
    }
    
    public function spawnParticleAtEntity(string $name, Entity $entity, array $data = [], ?Player $targetPlayer = null): void {
        $this->spawnParticle($name, $entity->getX(), $entity->getY(), $entity->getZ(), $data, $targetPlayer);
    }
    
    public function spawnParticleInRadius(string $name, float $x, float $y, float $z, float $radius, int $count, array $data = []): void {
        for ($i = 0; $i < $count; $i++) {
            $angle = mt_rand(0, 360) * M_PI / 180;
            $distance = mt_rand(0, (int)($radius * 100)) / 100;
            
            $px = $x + cos($angle) * $distance;
            $pz = $z + sin($angle) * $distance;
            $py = $y + (mt_rand(-50, 50) / 100);
            
            $this->spawnParticle($name, $px, $py, $pz, $data);
        }
    }
    
    public function spawnParticleLine(string $name, float $x1, float $y1, float $z1, float $x2, float $y2, float $z2, int $points, array $data = []): void {
        for ($i = 0; $i <= $points; $i++) {
            $t = $i / $points;
            $px = $x1 + ($x2 - $x1) * $t;
            $py = $y1 + ($y2 - $y1) * $t;
            $pz = $z1 + ($z2 - $z1) * $t;
            
            $this->spawnParticle($name, $px, $py, $pz, $data);
        }
    }
    
    public function spawnParticleCircle(string $name, float $x, float $y, float $z, float $radius, int $points, array $data = []): void {
        for ($i = 0; $i < $points; $i++) {
            $angle = ($i / $points) * 2 * M_PI;
            $px = $x + cos($angle) * $radius;
            $pz = $z + sin($angle) * $radius;
            
            $this->spawnParticle($name, $px, $y, $pz, $data);
        }
    }
    
    public function spawnParticleSphere(string $name, float $x, float $y, float $z, float $radius, int $points, array $data = []): void {
        for ($i = 0; $i < $points; $i++) {
            $phi = acos(1 - 2 * ($i + 0.5) / $points);
            $theta = M_PI * (1 + 5 ** 0.5) * $i;
            
            $px = $x + $radius * sin($phi) * cos($theta);
            $py = $y + $radius * cos($phi);
            $pz = $z + $radius * sin($phi) * sin($theta);
            
            $this->spawnParticle($name, $px, $py, $pz, $data);
        }
    }
    
    public function getRegisteredParticles(): array {
        return array_merge(array_keys($this->particleTypes), array_keys($this->customParticles));
    }
}
