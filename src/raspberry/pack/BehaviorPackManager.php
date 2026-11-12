<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\lang\Language;
use raspberry\network\mcpe\convert\BlockDefinitions;
use raspberry\utils\Logger;
use raspberry\Server;

use Throwable;

use function array_diff;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function rename;
use function scandir;
use function str_replace;
use function str_ends_with;
use function strtolower;
use function substr;
use function trim;
use function uasort;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Finds behavior packs in behavior_packs/, remembers which ones are enabled in
 * behavior_packs.json, and applies their content to the server the way the official
 * Bedrock server does: packs are discovered from folder sources, manifests are parsed
 * and cached, the active stack is ordered by dependencies, and the content files of the
 * active packs (entities, items, blocks, recipes, functions, loot and trade tables,
 * spawn rules, features) are merged into the server's registries.
 *
 * Compatibility with the official server: each world's world_behavior_packs.json is
 * read (pack_id + version entries) and the packs listed there are force-enabled, so a
 * world folder written by the official bedrock_server activates its packs here too.
 */
class BehaviorPackManager{

    private string $folder;
    private string $stateFile;
    private ?string $worldsPath;
    private Language $language;
    private Logger $logger;

    /** @var array<string, BehaviorPack> uuid => pack */
    private array $packs = [];

    /** @var list<string> uuids in enabled order */
    private array $enabled = [];

    /** @var list<string> uuids in active stack order (dependencies satisfied) */
    private array $active = [];

    /** @var list<string> uuids of every pack the server has already seen; a pack not listed here is new and gets enabled on its own */
    private array $known = [];

    /** @var array<string, int> category => applied content file count */
    private array $appliedCounts = [];

    /** @var array<string, array<string, mixed>> relative path => parsed JSON */
    private array $lootTables = [];
    /** @var array<string, array<string, mixed>> */
    private array $tradeTables = [];
    /** @var array<string, array<string, mixed>> */
    private array $spawnRules = [];
    /** @var array<string, array<string, mixed>> */
    private array $features = [];
    /** @var array<string, array<string, mixed>> */
    private array $featureRules = [];

    public function __init(string $folder, string $stateFile, Language $language, Logger $logger, ?string $worldsPath = null) : void{
        $this->__construct($folder, $stateFile, $language, $logger, $worldsPath);
    }

    public function __construct(string $folder, string $stateFile, Language $language, Logger $logger, ?string $worldsPath = null){
        $this->folder = $folder;
        $this->stateFile = $stateFile;
        $this->worldsPath = $worldsPath;
        $this->language = $language;
        $this->logger = $logger;
    }

    public function load() : void{
        if(!is_dir($this->folder)){
            mkdir($this->folder, 0777, true);
        }
        $this->scan();
        [$this->enabled, $known] = $this->readState();
        $this->autoEnableNew($known);
        $this->readWorldStacks();
        $this->rebuild();
        $this->logger->info($this->language->translate("behavior.loaded", ["found" => count($this->packs), "active" => count($this->active)]));
    }

    public function reload() : void{
        $this->load();
    }

    /**
     * Applies the content of the active packs to the server: items and item tags, blocks,
     * entities, recipes and functions. Content is applied once per pack stack in stack
     * order, so a later pack overrides an earlier one the same way the official content
     * stack does. Must run after the crafting manager exists and before the creative
     * content and crafting data packets are built.
     */
    public function applyContent(Server $server) : void{
        $this->appliedCounts = [];
        $crafting = $server->getCraftingManager();

        //items first: their identifiers and tags are used by recipes and creative content
        foreach($this->getActivePacks() as $pack){
            foreach($pack->getContentFiles("items") as $file){
                $this->tryFile($pack, $file, function() use ($pack, $file, $server, $crafting) : void{
                    $data = $this->contentJson($pack, $file);
                    [$identifier, $definition, $tags] = PackContentParser::itemDefinition($data, $file);

                    if($server->getPluginContentRegistry()->hasItem($identifier)){
                        throw new PackException("item {$identifier} is already registered");
                    }
                    $server->registerPluginCustomItem($identifier, $definition, "Behavior Packs");
                    foreach($tags as $tag){
                        $crafting->addItemTag($tag, [$identifier]);
                    }
                });
            }
        }

        foreach($this->getActivePacks() as $pack){
            foreach($pack->getContentFiles("blocks") as $file){
                $this->tryFile($pack, $file, function() use ($pack, $file, $server) : void{
                    $data = $this->contentJson($pack, $file);
                    [$identifier, $definition] = PackContentParser::blockDefinition($data, $file);

                    if($server->getPluginCustomBlockFallback($identifier) !== null){
                        throw new PackException("block {$identifier} is already registered");
                    }
                    $server->registerPluginCustomBlock($identifier, "minecraft:stone");
                    BlockDefinitions::registerFromPackDefinitions([$identifier => ["definition" => $definition]]);
                });
            }
        }

        foreach($this->getActivePacks() as $pack){
            foreach($pack->getContentFiles("entities") as $file){
                $this->tryFile($pack, $file, function() use ($pack, $file, $server) : void{
                    $data = $this->contentJson($pack, $file);
                    [$identifier, $definition] = PackContentParser::entityDefinition($data, $file);

                    if($server->getPluginContentRegistry()->getEntity($identifier) !== null){
                        throw new PackException("entity {$identifier} is already registered");
                    }
                    $server->registerPluginCustomEntity($identifier, $definition);
                });
            }
        }

        foreach($this->getActivePacks() as $pack){
            foreach($pack->getContentFiles("recipes") as $file){
                $this->tryFile($pack, $file, function() use ($pack, $file, $crafting) : void{
                    $data = $this->contentJson($pack, $file);
                    foreach(PackContentParser::recipes($data, $file, $crafting) as $parts){
                        $registered = match($parts["kind"]){
                            "shaped" => $crafting->registerPackShaped($parts["id"], $parts["block"], $parts["priority"], $parts["width"], $parts["height"], $parts["input"], $parts["output"], $parts["unlock"]),
                            "shapeless" => $crafting->registerPackShapeless($parts["id"], $parts["block"], $parts["priority"], $parts["input"], $parts["output"], $parts["unlock"]),
                            "furnace" => $crafting->registerPackFurnace($parts["id"], $parts["blocks"], $parts["priority"], $parts["input"], $parts["output"]),
                            default => $crafting->registerPackSmithing($parts["id"], $parts["template"], $parts["base"], $parts["addition"], $parts["output"])
                        };
                        if(!$registered){
                            throw new PackException("recipe {$parts["id"]} is already registered");
                        }
                    }
                });
            }
        }

        $functions = $server->getFunctionRunner();
        foreach($this->getActivePacks() as $pack){
            foreach($pack->getContentFiles("functions") as $file){
                $this->tryFile($pack, $file, function() use ($pack, $file, $functions) : void{
                    $lines = PackContentParser::functionLines($pack->readContentFile($file));
                    $functions->addFunction(self::functionId($file), $lines);
                });
            }
        }

        //data tables: parsed and exposed, ready for engine/plugin use
        foreach(["loot_tables" => "lootTables", "trade_tables" => "tradeTables", "spawn_rules" => "spawnRules", "features" => "features", "feature_rules" => "featureRules"] as $category => $property){
            foreach($this->getActivePacks() as $pack){
                foreach($pack->getContentFiles($category) as $file){
                    $this->tryFile($pack, $file, function() use ($pack, $file, $property) : void{
                        $data = $this->contentJson($pack, $file);
                        $this->{$property}[$pack->getUuid() . "/" . $file] = $data;
                    });
                }
            }
        }
    }

    private function tryFile(BehaviorPack $pack, string $file, callable $apply) : void{
        try{
            $apply();
            $category = explode("/", $file, 2)[0];
            $this->appliedCounts[$category] = ($this->appliedCounts[$category] ?? 0) + 1;
        }catch(PackException $exception){
            $this->contentLog($pack, $file, $exception->getMessage());
        }catch(Throwable $exception){
            $this->contentLog($pack, $file, $exception::class . ": " . $exception->getMessage());
        }
    }

    private function contentJson(BehaviorPack $pack, string $file) : array{
        $error = null;
        $data = $pack->readContentJson($file, $error);
        if($data === null){
            throw new PackException((string) $error);
        }
        return $data;
    }

    /**
     * A function file's id: the path inside functions/, without the extension
     * ("buildings/house" for functions/buildings/house.mcfunction).
     */
    private static function functionId(string $file) : string{
        $id = substr($file, strlen("functions/"));
        if(str_ends_with(strtolower($id), ".mcfunction")){
            $id = substr($id, 0, -strlen(".mcfunction"));
        }
        return strtolower(str_replace("\\", "/", $id));
    }

    /**
     * @return array<string, int> category => applied content file count
     */
    public function getAppliedCounts() : array{
        return $this->appliedCounts;
    }

    /**
     * @return array<string, array<string, mixed>> "uuid/path" => parsed JSON
     */
    public function getLootTables() : array{
        return $this->lootTables;
    }

    /**
     * @return array<string, array<string, mixed>> "uuid/path" => parsed JSON
     */
    public function getTradeTables() : array{
        return $this->tradeTables;
    }

    /**
     * @return array<string, array<string, mixed>> "uuid/path" => parsed JSON
     */
    public function getSpawnRules() : array{
        return $this->spawnRules;
    }

    /**
     * @return array<string, array<string, mixed>> "uuid/path" => parsed JSON
     */
    public function getFeatures() : array{
        return $this->features;
    }

    /**
     * @return array<string, array<string, mixed>> "uuid/path" => parsed JSON
     */
    public function getFeatureRules() : array{
        return $this->featureRules;
    }

    /**
     * @return list<string> function ids of the active packs
     */
    public function getFunctionIds() : array{
        $ids = [];
        foreach($this->getActivePacks() as $pack){
            foreach($pack->getContentFiles("functions") as $file){
                $ids[] = self::functionId($file);
            }
        }
        return array_values(array_unique($ids));
    }

    public function hasScriptPacks() : bool{
        foreach($this->getActivePacks() as $pack){
            if($pack->hasScripts()){
                return true;
            }
        }
        return false;
    }

    public function getFolder() : string{
        return $this->folder;
    }

    /**
     * Finds a pack by UUID, folder name or manifest name (case-insensitive).
     */
    public function find(string $needle) : ?BehaviorPack{
        $needle = strtolower(trim($needle));
        if(isset($this->packs[$needle])){
            return $this->packs[$needle];
        }
        foreach($this->packs as $pack){
            if(strtolower($pack->getFolderName()) === $needle || strtolower($pack->getName()) === $needle){
                return $pack;
            }
        }
        return null;
    }

    /**
     * @return list<BehaviorPack> sorted by folder name
     */
    public function getPacks() : array{
        $packs = array_values($this->packs);
        uasort($packs, static fn(BehaviorPack $a, BehaviorPack $b) : int => $a->getFolderName() <=> $b->getFolderName());
        return array_values($packs);
    }

    public function getPack(string $uuid) : ?BehaviorPack{
        return $this->packs[strtolower(trim($uuid))] ?? null;
    }

    public function isEnabled(BehaviorPack $pack) : bool{
        return in_array($pack->getUuid(), $this->enabled, true);
    }

    public function isActive(BehaviorPack $pack) : bool{
        return in_array($pack->getUuid(), $this->active, true);
    }

    /**
     * @return list<BehaviorPack> active packs in stack order
     */
    public function getActivePacks() : array{
        $result = [];
        foreach($this->active as $uuid){
            if(isset($this->packs[$uuid])){
                $result[] = $this->packs[$uuid];
            }
        }
        return $result;
    }

    public function getActiveCount() : int{
        return count($this->active);
    }

    public function findMissingDependency(BehaviorPack $pack) : ?string{
        foreach($pack->getManifest()->getDependencies() as $uuid){
            if(!isset($this->packs[$uuid])){
                return $uuid;
            }
        }
        return null;
    }

    /**
     * @return list<BehaviorPack> packs that were added (dependencies first)
     * @throws PackException
     */
    public function enable(BehaviorPack $pack) : array{
        $added = [];
        $this->collectForEnable($pack, $added, []);
        if($added === []){
            return [];
        }
        foreach($added as $addedPack){
            $this->enabled[] = $addedPack->getUuid();
        }
        $this->saveState();
        $this->rebuild();
        return $added;
    }

    /**
     * @return list<BehaviorPack>
     */
    public function disable(BehaviorPack $pack) : array{
        if(!$this->isEnabled($pack)){
            return [];
        }
        $remove = [$pack->getUuid()];
        do{
            $changed = false;
            foreach($this->enabled as $uuid){
                if(in_array($uuid, $remove, true) || !isset($this->packs[$uuid])){
                    continue;
                }
                foreach($this->packs[$uuid]->getManifest()->getDependencies() as $dependency){
                    if(in_array($dependency, $remove, true)){
                        $remove[] = $uuid;
                        $changed = true;
                        break;
                    }
                }
            }
        }while($changed);

        $removed = [];
        foreach($remove as $uuid){
            if(isset($this->packs[$uuid])){
                $removed[] = $this->packs[$uuid];
            }
        }
        $this->enabled = array_values(array_diff($this->enabled, $remove));
        $this->saveState();
        $this->rebuild();
        return $removed;
    }

    /**
     * @param list<BehaviorPack> $added
     * @param list<string> $path
     * @throws PackException
     */
    private function collectForEnable(BehaviorPack $pack, array &$added, array $path) : void{
        $uuid = $pack->getUuid();
        if(in_array($uuid, $this->enabled, true) || in_array($uuid, $path, true)){
            return;
        }
        foreach($added as $other){
            if($other === $pack){
                return;
            }
        }
        $path[] = $uuid;
        foreach($pack->getManifest()->getDependencies() as $dependencyUuid){
            $dependency = $this->packs[$dependencyUuid] ?? null;
            if($dependency === null){
                throw new PackException($this->language->translate("behavior.missingDependency", ["name" => $pack->getName(), "dependency" => $dependencyUuid]));
            }
            $this->collectForEnable($dependency, $added, $path);
        }
        $added[] = $pack;
    }

    private function scan() : void{
        $this->packs = [];
        $entries = scandir($this->folder);
        if($entries === false){
            $this->logger->error($this->language->translate("behavior.folderUnreadable", ["path" => $this->folder]));
            return;
        }
        foreach($entries as $entry){
            if($entry === "." || $entry === ".."){
                continue;
            }
            $path = $this->folder . "/" . $entry;
            try{
                $pack = BehaviorPack::fromPath($path, $entry);
                $uuid = $pack->getUuid();
                if(isset($this->packs[$uuid])){
                    $this->logger->warning($this->language->translate("behavior.duplicate", ["folder" => $entry, "other" => $this->packs[$uuid]->getFolderName(), "uuid" => $uuid]));
                    continue;
                }
                $this->packs[$uuid] = $pack;
            }catch(PackException $exception){
                $this->logger->warning($this->language->translate("behavior.ignored", ["folder" => $entry, "reason" => $exception->getMessage()]));
            }
        }
    }

    private function rebuild() : void{
        $this->active = [];
        $pending = array_values(array_unique($this->enabled));
        do{
            $progress = false;
            foreach($pending as $index => $uuid){
                $pack = $this->packs[$uuid] ?? null;
                if($pack === null){
                    unset($pending[$index]);
                    $progress = true;
                    continue;
                }
                $ready = true;
                foreach($pack->getManifest()->getDependencies() as $dependency){
                    if(!in_array($dependency, $this->active, true)){
                        $ready = false;
                        break;
                    }
                }
                if($ready){
                    $this->active[] = $uuid;
                    unset($pending[$index]);
                    $progress = true;
                }
            }
        }while($progress && $pending !== []);

        foreach($pending as $uuid){
            $pack = $this->packs[$uuid] ?? null;
            if($pack === null){
                continue;
            }
            foreach($pack->getManifest()->getDependencies() as $dependency){
                if(!in_array($dependency, $this->active, true)){
                    $this->logger->warning($this->language->translate("behavior.disabledDependency", ["name" => $pack->getName(), "dependency" => $dependency]));
                    break;
                }
            }
        }
    }

    /**
     * The official server stores its pack stack per world in world_behavior_packs.json.
     * TodiumX reads it and force-enables the packs a world lists, so worlds created with
     * the official bedrock_server keep their packs. The file is never written back.
     */
    private function readWorldStacks() : void{
        if($this->worldsPath === null || !is_dir($this->worldsPath)){
            return;
        }
        $worldFiles = glob($this->worldsPath . "/*/world_behavior_packs.json") ?: [];
        foreach($worldFiles as $worldFile){
            $world = basename(dirname($worldFile));
            try{
                $contents = file_get_contents($worldFile);
                $data = $contents === false ? null : json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                if(!is_array($data)){
                    throw new PackException("expected a list of {\"pack_id\": ..., \"version\": [...]} entries");
                }
            }catch(Throwable $exception){
                $this->logger->warning($this->language->translate("behavior.worldStackInvalid", ["world" => $world, "reason" => $exception->getMessage()]));
                continue;
            }

            foreach($data as $entry){
                $uuid = is_array($entry) && is_string($entry["pack_id"] ?? null) ? strtolower(trim($entry["pack_id"])) : "";
                if(preg_match(PackManifest::UUID_PATTERN, $uuid) !== 1){
                    continue;
                }
                $pack = $this->packs[$uuid] ?? null;
                if($pack === null){
                    $this->logger->warning($this->language->translate("behavior.worldMissingPack", ["world" => $world, "uuid" => $uuid]));
                    continue;
                }
                if(!in_array($uuid, $this->enabled, true)){
                    $this->enabled[] = $uuid;
                    $this->logger->info($this->language->translate("behavior.worldEnabled", ["world" => $world, "name" => $pack->getName()]));
                }
            }
        }
        $this->enabled = array_values(array_unique($this->enabled));
    }

    /**
     * A pack that was never seen before is enabled on its own, so dropping a pack in the folder and restarting is enough.
     * A pack the owner disabled stays in "known" and is not enabled again.
     *
     * @param list<string> $known
     */
    private function autoEnableNew(array $known) : void{
        $handled = [];
        foreach($this->packs as $uuid => $pack){
            if(in_array($uuid, $known, true)){
                continue;
            }
            try{
                $added = [];
                $this->collectForEnable($pack, $added, []);
                foreach($added as $addedPack){
                    $this->enabled[] = $addedPack->getUuid();
                }
                $handled[] = $uuid;
                $this->logger->info($this->language->translate("behavior.new", ["name" => $pack->getName()]));
            }catch(PackException $exception){
                $this->logger->warning($this->language->translate("behavior.newFailed", ["name" => $pack->getName(), "reason" => $exception->getMessage()]));
            }
        }
        $this->enabled = array_values(array_unique($this->enabled));
        $newKnown = array_values(array_unique([...$known, ...$handled, ...$this->enabled]));
        $changed = $handled !== [] || $newKnown !== $known;
        $this->known = $newKnown;
        if($changed || !is_file($this->stateFile)){
            $this->saveState();
        }
    }

    /**
     * @return array{list<string>, list<string>} the enabled uuids and the known uuids
     */
    private function readState() : array{
        if(!is_file($this->stateFile)){
            return [[], []];
        }
        try{
            $contents = file_get_contents($this->stateFile);
            $data = $contents === false ? null : json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
            $list = is_array($data) ? ($data["enabled"] ?? null) : null;
            if(!is_array($list)){
                throw new PackException("it must contain {\"enabled\": [...]}");
            }
        }catch(Throwable $exception){
            $this->logger->warning($this->language->translate("behavior.stateInvalid", ["file" => $this->stateFile, "reason" => $exception->getMessage()]));
            return [[], []];
        }
        $enabled = self::uuidList($list);
        //an older file has no "known" list: then only the enabled packs count as seen
        $known = isset($data["known"]) && is_array($data["known"]) ? self::uuidList($data["known"]) : $enabled;

        return [$enabled, array_values(array_unique([...$known, ...$enabled]))];
    }

    /**
     * @return list<string>
     */
    private static function uuidList(array $list) : array{
        $result = [];
        foreach($list as $uuid){
            if(is_string($uuid) && !in_array(strtolower($uuid), $result, true)){
                $result[] = strtolower($uuid);
            }
        }
        return $result;
    }

    private function saveState() : void{
        $temporary = $this->stateFile . ".tmp";
        try{
            $json = json_encode(["enabled" => $this->enabled, "known" => array_values(array_unique([...$this->known, ...$this->enabled]))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if(file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $this->stateFile)){
                throw new PackException("the file cannot be written");
            }
        }catch(Throwable $exception){
            @unlink($temporary);
            $this->logger->error($this->language->translate("behavior.saveFailed", ["file" => $this->stateFile, "reason" => $exception->getMessage()]));
        }
    }

    /**
     * The content log: one warning per unusable content file, with the pack and file it
     * came from, like the official content-log endpoints.
     */
    private function contentLog(BehaviorPack $pack, string $file, string $reason) : void{
        $this->logger->warning($this->language->translate("behavior.contentError", ["pack" => $pack->getName(), "file" => $file, "reason" => $reason]));
    }
}
