<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\nbt\NbtSerializer;

use RuntimeException;
use UnexpectedValueException;

use function base64_decode;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class BlockDefinitions{

    /** @var list<array{string, string}>|null */
    private static ?array $definitions = null;

    /** @var list<array{string, string}> custom block definitions from packs */
    private static array $customDefinitions = [];

    private function __construct(){
    }

    /**
     * @return list<array{string, string}>
     */
    public static function get() : array{
        $base = self::$definitions ??= self::load(dirname(__DIR__, 5) . "/resources/vanilla/block_components.json");
        if(self::$customDefinitions === []){
            return $base;
        }
        // merge: custom overrides/additions appended
        $merged = $base;
        foreach(self::$customDefinitions as $entry){
            $merged[] = $entry;
        }
        return $merged;
    }

    /**
     * Register a custom block definition (network NBT components payload, already serialized).
     * @param string $identifier e.g. "mypack:custom_block"
     * @param string $networkNbt serialized network NBT compound for block components/states
     */
    public static function registerCustom(string $identifier, string $networkNbt) : void{
        self::$customDefinitions[] = [$identifier, $networkNbt];
    }

    /**
     * Register custom blocks from parsed pack definitions. Best-effort: creates minimal block entries.
     * Full custom block permutation/state support requires protocol-specific NBT; this provides registration.
     * @param array<string, array<string, mixed>> $customBlocks
     */
    public static function registerFromPackDefinitions(array $customBlocks) : void{
        foreach($customBlocks as $identifier => $info){
            $def = $info["definition"] ?? null;
            if(!is_array($def)){
                continue;
            }
            // Build a minimal network NBT compound for the block definition.
            // Real clients expect specific structures (components, permutations, traits, states).
            // We emit a basic valid compound so the block is at least known to the registry.
            $nbt = self::buildMinimalBlockNbt($identifier, $def);
            if($nbt !== null){
                self::registerCustom($identifier, $nbt);
            }
        }
    }

    private static function buildMinimalBlockNbt(string $identifier, array $def) : ?string{
        try{
            $writer = new \raspberry\network\mcpe\serializer\NetworkNbtWriter();
            $writer->startCompound();
            // Block name inside definition (some formats expect "name" tag)
            $writer->writeString("name", $identifier);
            // Minimal components compound
            $writer->startCompound("components");
            // minecraft:light_emission, etc. could be mapped; keep minimal
            $writer->endCompound();
            $writer->writeEmptyList("permutations", \raspberry\network\mcpe\serializer\NetworkNbtWriter::TAG_COMPOUND);
            $writer->endCompound();
            return $writer->getBuffer();
        }catch(\Throwable){
            return null;
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private static function load(string $path) : array{
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read block components: {$path}");
        }

        $data = json_decode($contents, true, 3, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["blocks"] ?? null)){
            throw new UnexpectedValueException("Block components have an invalid layout: {$path}");
        }

        $definitions = [];

        foreach($data["blocks"] as $name => $components){
            $nbt = is_string($name) && is_string($components) ? base64_decode($components, true) : false;

            if($nbt === false){
                throw new UnexpectedValueException("Invalid block components entry in {$path}");
            }

            $definitions[] = [$name, NbtSerializer::network()->write(NbtSerializer::littleEndian()->read($nbt))];
        }

        return $definitions;
    }
}