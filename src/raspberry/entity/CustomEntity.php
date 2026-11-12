<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\math\Vector3;
use raspberry\player\Player;
use raspberry\world\World;

/** Server-side entity defined and spawned by a PHP plugin. */
final class CustomEntity extends Living{

    private string $typeId;
    private string $nameTag;
    private float $width = 0.6;
    private float $height = 1.8;
    private float $followRange = 12.0;
    private float $speed = 0.08;
    private bool $followsPlayers = false;
    private bool $wanders = false;
    private int $lastMoveTick = 0;

    /** @param array<string, mixed> $definition */
    public function __construct(World $world, Vector3 $position, string $typeId, array $definition){
        $this->typeId = strtolower($typeId);
        $this->nameTag = is_string($definition["name"] ?? null) ? $definition["name"] : $this->typeId;
        $health = $definition["max_health"] ?? $definition["health"] ?? null;
        if(is_numeric($health) && (float)$health > 0){
            $this->maxHealth = (float)$health;
            $this->health = (float)$health;
        }
        $movement = $definition["speed"] ?? null;
        if(is_numeric($movement) && (float)$movement >= 0){
            $this->speed = min(0.35, (float)$movement);
        }
        $followRange = $definition["follow_range"] ?? null;
        if(is_numeric($followRange)) $this->followRange = min(64.0, max(1.0, (float)$followRange));
        $this->followsPlayers = (bool)($definition["follow_players"] ?? false);
        $this->wanders = (bool)($definition["wander"] ?? false);
        $this->width = max(0.1, min(8.0, (float)($definition["width"] ?? 0.6)));
        $this->height = max(0.1, min(16.0, (float)($definition["height"] ?? 1.8)));
        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{ return $this->typeId; }
    public function getNameTag() : string{ return $this->nameTag; }
    public function setNameTag(string $nameTag) : void{ $this->nameTag = $nameTag; }
    public function getWidth() : float{ return $this->width; }
    public function getHeight() : float{ return $this->height; }

    protected function onAttributesChanged() : void{
        foreach($this->getViewers() as $viewer){
            $viewer->getNetworkSession()->sendCustomEntityAttributes($this);
        }
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);
        $this->updateViewers();
        if($currentTick - $this->lastMoveTick < ($this->wanders ? 40 : 4)){
            return;
        }
        $target = null;
        $distance = $this->followRange;
        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || !$player->isOnline()) continue;
            $candidate = $player->getPosition()->distance($this->position);
            if($candidate < $distance){ $distance = $candidate; $target = $player; }
        }
        if(!$target instanceof Player || $distance < 1.25){
            if(!$this->wanders || ($currentTick % 80) !== 0) return;
            $angle = deg2rad((float)(($currentTick * 37 + $this->id * 13) % 360));
            $this->move($this->position->add(cos($angle) * $this->speed * 40, 0, sin($angle) * $this->speed * 40), rad2deg($angle), 0.0);
            $this->lastMoveTick = $currentTick;
            return;
        }
        if(!$this->followsPlayers) return;
        $to = $target->getPosition();
        $dx = $to->x - $this->position->x;
        $dz = $to->z - $this->position->z;
        $length = sqrt($dx * $dx + $dz * $dz);
        if($length < 0.8) return;
        $step = min($this->speed * 4, max(0.0, $length - 0.8));
        $this->move(new Vector3($this->position->x + $dx / $length * $step, $this->position->y, $this->position->z + $dz / $length * $step), rad2deg(atan2(-$dx, $dz)), 0.0);
        $this->lastMoveTick = $currentTick;
    }

    protected function onDeath() : void{ $this->close(); }
}
