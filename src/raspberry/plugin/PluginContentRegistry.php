<?php

declare(strict_types=1);

namespace raspberry\plugin;

use InvalidArgumentException;

/** Runtime content registered by enabled PHP plugins. */
final class PluginContentRegistry{

    /** @var array<string, array<string, mixed>> */
    private array $items = [];
    /** @var array<string, string> custom identifier => vanilla fallback block */
    private array $blocks = [];
    /** @var array<string, array<string, mixed>> */
    private array $entities = [];

    /** @param array<string, mixed> $components */
    public function registerItem(string $id, array $components) : void{
        self::validateId($id);
        $this->items[strtolower($id)] = $components;
    }

    public function hasItem(string $id) : bool{ return isset($this->items[strtolower($id)]); }

    public function registerBlock(string $id, string $fallback) : void{
        self::validateId($id);
        $this->blocks[$id] = $fallback;
    }

    /** @param array<string, mixed> $definition */
    public function registerEntity(string $id, array $definition) : void{
        self::validateId($id);
        $this->entities[$id] = $definition;
    }

    /** @return array<string, mixed>|null */
    public function getEntity(string $id) : ?array{ return $this->entities[strtolower($id)] ?? null; }
    public function getBlockFallback(string $id) : ?string{ return $this->blocks[strtolower($id)] ?? null; }
    /** @return array<string, string> */
    public function getBlocks() : array{ return $this->blocks; }
    /** @return array<string, array<string, mixed>> */
    public function getItems() : array{ return $this->items; }

    private static function validateId(string $id) : void{
        if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_./-]+$/D', strtolower($id)) !== 1){
            throw new InvalidArgumentException("Custom identifier must use namespace:name format: {$id}");
        }
    }
}
