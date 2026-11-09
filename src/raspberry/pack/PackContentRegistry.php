<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\utils\Logger;

use function array_merge;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * Reads custom entities, items, and blocks from active behavior/resource packs.
 * This provides server-side awareness and data for protocol registration.
 */
class PackContentRegistry{

    /** @var array<string, array<string, mixed>> identifier => definition */
    private array $customItems = [];
    /** @var array<string, array<string, mixed>> identifier => definition */
    private array $customBlocks = [];
    /** @var array<string, array<string, mixed>> identifier => definition */
    private array $customEntities = [];
    /** @var array<string, array{pack: string, entry: string, files: array<string, string>}> */
    private array $scriptModules = [];

    private Logger $logger;

    public function __construct(Logger $logger){
        $this->logger = $logger;
    }

    /**
     * @param list<BehaviorPack|ResourcePack> $packs
     */
    public function loadFromPacks(array $packs) : void{
        $this->customItems = [];
        $this->customBlocks = [];
        $this->customEntities = [];
        $this->scriptModules = [];

        foreach($packs as $pack){
            $packName = $pack->getName();
            $manifest = $pack->getManifest();

            // Items
            foreach($this->readPackDefinitions($pack, "items") as $def){
                $id = $this->extractIdentifier($def, ["minecraft:item", "description", "identifier"], ["minecraft:item", "description", "identifier"]);
                $id = $id ?? $this->extractItemIdentifier($def);
                if(is_string($id) && $id !== ""){
                    $id = strtolower($id);
                    $this->customItems[$id] = ["definition" => $def, "pack" => $packName, "identifier" => $id];
                }
            }

            // Blocks
            foreach($this->readPackDefinitions($pack, "blocks") as $def){
                $id = $this->extractBlockIdentifier($def);
                if(is_string($id) && $id !== ""){
                    $id = strtolower($id);
                    $this->customBlocks[$id] = ["definition" => $def, "pack" => $packName, "identifier" => $id];
                }
            }

            // Entities (behavior packs define server-side entities)
            foreach($this->readPackDefinitions($pack, "entities") as $def){
                $id = $this->extractEntityIdentifier($def);
                if(is_string($id) && $id !== ""){
                    $id = strtolower($id);
                    $this->customEntities[$id] = ["definition" => $def, "pack" => $packName, "identifier" => $id];
                }
            }

            // Scripts
            if($manifest->hasScripts()){
                $entry = $manifest->getScriptEntry();
                $files = $this->readPackScripts($pack);
                $uuid = $manifest->getUuid();
                $this->scriptModules[$uuid] = [
                    "pack" => $packName,
                    "entry" => is_string($entry) ? $entry : "scripts/main.js",
                    "files" => $files,
                    "path" => $pack->getPath(),
                    "uuid" => $uuid,
                    "dependencies" => $manifest->getDependencies(),
                ];
                $this->logger->info("Found script module in pack '{$packName}' entry=" . ($entry ?? "scripts/main.js") . " files=" . count($files));
            }
        }

        $this->logger->info("Pack content loaded: " . count($this->customItems) . " custom items, " . count($this->customBlocks) . " custom blocks, " . count($this->customEntities) . " custom entities, " . count($this->scriptModules) . " script modules.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readPackDefinitions(BehaviorPack|ResourcePack $pack, string $subdir) : array{
        if(method_exists($pack, "readDefinitionFiles")){
            return $pack->readDefinitionFiles($subdir);
        }
        return [];
    }

    /**
     * @return array<string, string>
     */
    private function readPackScripts(BehaviorPack|ResourcePack $pack) : array{
        if(method_exists($pack, "readScripts")){
            $result = $pack->readScripts();
            return is_array($result) ? $result : [];
        }
        return [];
    }

    private function extractItemIdentifier(array $def) : ?string{
        $item = $def["minecraft:item"] ?? null;
        if(is_array($item)){
            $desc = $item["description"] ?? null;
            if(is_array($desc) && is_string($desc["identifier"] ?? null)){
                return $desc["identifier"];
            }
        }
        return null;
    }

    private function extractBlockIdentifier(array $def) : ?string{
        $block = $def["minecraft:block"] ?? null;
        if(is_array($block)){
            $desc = $block["description"] ?? null;
            if(is_array($desc) && is_string($desc["identifier"] ?? null)){
                return $desc["identifier"];
            }
        }
        return null;
    }

    private function extractEntityIdentifier(array $def) : ?string{
        $entity = $def["minecraft:entity"] ?? null;
        if(is_array($entity)){
            $desc = $entity["description"] ?? null;
            if(is_array($desc) && is_string($desc["identifier"] ?? null)){
                return $desc["identifier"];
            }
        }
        // client entity format
        $client = $def["minecraft:client_entity"] ?? null;
        if(is_array($client)){
            $desc = $client["description"] ?? null;
            if(is_array($desc) && is_string($desc["identifier"] ?? null)){
                return $desc["identifier"];
            }
        }
        return null;
    }

    private function extractIdentifier(array $def, array $a, array $b) : ?string{
        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function getCustomItems() : array{ return $this->customItems; }
    /** @return array<string, array<string, mixed>> */
    public function getCustomBlocks() : array{ return $this->customBlocks; }
    /** @return array<string, array<string, mixed>> */
    public function getCustomEntities() : array{ return $this->customEntities; }
    /** @return array<string, array{pack: string, entry: string, files: array<string, string>}> */
    public function getScriptModules() : array{ return $this->scriptModules; }

    public function hasCustomItems() : bool{ return count($this->customItems) > 0; }
    public function hasCustomBlocks() : bool{ return count($this->customBlocks) > 0; }
    public function hasCustomEntities() : bool{ return count($this->customEntities) > 0; }
}
