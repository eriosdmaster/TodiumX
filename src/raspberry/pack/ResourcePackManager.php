<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\lang\Language;
use raspberry\utils\Logger;

use Throwable;

use function array_diff;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function scandir;
use function sort;
use function strval;
use function strtolower;
use function trim;
use function uasort;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const SORT_STRING;

/**
 * Finds resource packs in resource_packs/, remembers which ones are enabled in resource_packs.json,
 * and exposes them for client negotiation/transfer and content parsing.
 */
class ResourcePackManager{

    private string $folder;
    private string $stateFile;
    private Language $language;
    private Logger $logger;

    /** @var array<string, ResourcePack> uuid => pack */
    private array $packs = [];

    /** @var list<string> uuids in enabled order */
    private array $enabled = [];

    /** @var list<string> uuids in active stack order (dependencies satisfied) */
    private array $active = [];

    /** @var list<string> uuids of every pack the server has already seen; a pack not listed here is new and gets enabled on its own */
    private array $known = [];

    public function __init(string $folder, string $stateFile, Language $language, Logger $logger) : void{
        $this->__construct($folder, $stateFile, $language, $logger);
    }

    public function __construct(string $folder, string $stateFile, Language $language, Logger $logger){
        $this->folder = $folder;
        $this->stateFile = $stateFile;
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
        $this->rebuild();
        $this->logger->info("Loaded resource packs: " . count($this->packs) . " found, " . count($this->active) . " active.");
    }

    public function reload() : void{
        $this->load();
    }

    /**
     * @return list<ResourcePack> sorted by folder name
     */
    public function getPacks() : array{
        $packs = array_values($this->packs);
        uasort($packs, static fn(ResourcePack $a, ResourcePack $b) : int => $a->getFolderName() <=> $b->getFolderName());
        return array_values($packs);
    }

    public function getPack(string $uuid) : ?ResourcePack{
        return $this->packs[strtolower(trim($uuid))] ?? null;
    }

    public function getPackByFolder(string $name) : ?ResourcePack{
        foreach($this->packs as $pack){
            if($pack->getFolderName() === $name){
                return $pack;
            }
        }
        return null;
    }

    public function isEnabled(ResourcePack $pack) : bool{
        return in_array($pack->getUuid(), $this->enabled, true);
    }

    public function isActive(ResourcePack $pack) : bool{
        return in_array($pack->getUuid(), $this->active, true);
    }

    /**
     * @return list<ResourcePack> active packs in stack order
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

    public function findMissingDependency(ResourcePack $pack) : ?string{
        foreach($pack->getManifest()->getDependencies() as $uuid){
            if(!isset($this->packs[$uuid])){
                return $uuid;
            }
        }
        return null;
    }

    /**
     * @return list<ResourcePack> packs that were added (dependencies first)
     * @throws PackException
     */
    public function enable(ResourcePack $pack) : array{
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
     * @return list<ResourcePack>
     */
    public function disable(ResourcePack $pack) : array{
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
     * @param list<ResourcePack> $added
     * @param list<string> $path
     * @throws PackException
     */
    private function collectForEnable(ResourcePack $pack, array &$added, array $path) : void{
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
                throw new PackException($this->language->translate("pack.missingDependency", ["name" => $pack->getName(), "dependency" => $dependencyUuid]));
            }
            $this->collectForEnable($dependency, $added, $path);
        }
        $added[] = $pack;
    }

    private function scan() : void{
        $this->packs = [];
        $entries = scandir($this->folder);
        if($entries === false){
            $this->logger->error($this->language->translate("pack.folderUnreadable", ["path" => $this->folder]));
            return;
        }
        foreach($entries as $entry){
            if($entry === "." || $entry === ".."){
                continue;
            }
            $path = $this->folder . "/" . $entry;
            try{
                $pack = ResourcePack::fromPath($path, $entry);
                $uuid = $pack->getUuid();
                if(isset($this->packs[$uuid])){
                    $this->logger->warning($this->language->translate("pack.duplicate", ["folder" => $entry, "other" => $this->packs[$uuid]->getFolderName(), "uuid" => $uuid]));
                    continue;
                }
                $this->packs[$uuid] = $pack;
            }catch(PackException $exception){
                $this->logger->warning($this->language->translate("pack.ignored", ["folder" => $entry, "reason" => $exception->getMessage()]));
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
                    $this->logger->warning($this->language->translate("pack.disabledDependency", ["name" => $pack->getName(), "dependency" => $dependency]));
                    break;
                }
            }
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
            $this->logger->warning($this->language->translate("pack.stateInvalid", ["file" => $this->stateFile, "reason" => $exception->getMessage()]));
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
                $this->logger->info("Resource pack \"{$pack->getName()}\" is new and was enabled automatically.");
            }catch(PackException $exception){
                $this->logger->warning("Resource pack \"{$pack->getName()}\" could not be enabled yet: " . $exception->getMessage());
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

    private function saveState() : void{
        $temporary = $this->stateFile . ".tmp";
        try{
            $json = json_encode(["enabled" => $this->enabled, "known" => array_values(array_unique([...$this->known, ...$this->enabled]))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if(file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $this->stateFile)){
                throw new PackException("the file cannot be written");
            }
        }catch(Throwable $exception){
            @unlink($temporary);
            $this->logger->error($this->language->translate("pack.saveFailed", ["file" => $this->stateFile, "reason" => $exception->getMessage()]));
        }
    }
}
