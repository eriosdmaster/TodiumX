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
 * Finds the behavior packs in behavior_packs/, remembers which ones are enabled in behavior_packs.json,
 * and keeps the functions of the enabled packs ready to run.
 *
 * Only the server side of a pack is used for now: its functions. Packs are not sent to players yet.
 */
class BehaviorPackManager{

    private string $folder;

    private string $stateFile;

    private Language $language;

    private Logger $logger;

    /** @var array<string, BehaviorPack> uuid => pack, every valid pack that was found */
    private array $packs = [];

    /** @var list<string> uuids in the order they are listed in behavior_packs.json */
    private array $enabled = [];

    /** @var list<string> uuids of the enabled packs whose dependencies are enabled too, in stack order */
    private array $active = [];

    /** @var array<string, McFunction> */
    private array $functions = [];

    /** @var list<string> */
    private array $tickFunctions = [];

    public function __construct(string $folder, string $stateFile, Language $language, Logger $logger){
        $this->folder = $folder;
        $this->stateFile = $stateFile;
        $this->language = $language;
        $this->logger = $logger;
    }

    public function getFolder() : string{
        return $this->folder;
    }

    /**
     * Scans the folder again and reads behavior_packs.json again. Safe to call while the server runs.
     */
    public function load() : void{
        $this->packs = [];

        if(!is_dir($this->folder)){
            @mkdir($this->folder, 0777, true);
        }

        foreach(scandir($this->folder) ?: [] as $entry){
            if($entry === "." || $entry === ".." || !is_dir($this->folder . "/" . $entry)){
                continue;
            }

            $path = $this->folder . "/" . $entry;

            try{
                $manifest = PackManifest::fromFileTyped($path . "/manifest.json", PackManifest::TYPE_BEHAVIOR);
            }catch(PackException $exception){
                if(is_file($path . "/manifest.json")){
                    $this->logger->warning($this->language->translate("pack.invalid", ["folder" => $entry, "reason" => $exception->getMessage()]));
                }

                continue;
            }

            if(isset($this->packs[$manifest->getUuid()])){
                $this->logger->warning($this->language->translate("pack.duplicate", [
                    "folder" => $entry,
                    "other" => $this->packs[$manifest->getUuid()]->getFolderName()
                ]));
                continue;
            }

            $this->packs[$manifest->getUuid()] = new BehaviorPack($path, $entry, $manifest);
        }

        $this->enabled = $this->readState();
        $this->rebuild();

        $this->logger->info($this->language->translate("pack.found", [
            "count" => count($this->packs),
            "enabled" => count($this->active)
        ]));
    }

    /**
     * @return array<string, BehaviorPack> uuid => pack, sorted by name
     */
    public function getPacks() : array{
        $packs = $this->packs;
        uasort($packs, fn(BehaviorPack $a, BehaviorPack $b) => strtolower($a->getName()) <=> strtolower($b->getName()));

        return $packs;
    }

    public function getPack(string $uuid) : ?BehaviorPack{
        return $this->packs[strtolower($uuid)] ?? null;
    }

    /**
     * Finds a pack by UUID or by name, ignoring case. A name used by two packs matches none of them.
     */
    public function find(string $query) : ?BehaviorPack{
        $query = strtolower(trim($query));

        if(isset($this->packs[$query])){
            return $this->packs[$query];
        }

        $found = null;

        foreach($this->packs as $pack){
            if(strtolower($pack->getName()) === $query || strtolower($pack->getFolderName()) === $query){
                if($found !== null && $found !== $pack){
                    return null;
                }

                $found = $pack;
            }
        }

        return $found;
    }

    /**
     * True when the pack is listed as enabled, even if a dependency is missing and it is not running.
     */
    public function isEnabled(BehaviorPack $pack) : bool{
        return in_array($pack->getUuid(), $this->enabled, true);
    }

    /**
     * True when the pack is enabled and everything it needs is enabled too.
     */
    public function isActive(BehaviorPack $pack) : bool{
        return in_array($pack->getUuid(), $this->active, true);
    }

    /**
     * @return list<BehaviorPack> the packs that are running, lowest in the stack first
     */
    public function getActivePacks() : array{
        $packs = [];

        foreach($this->active as $uuid){
            $packs[] = $this->packs[$uuid];
        }

        return $packs;
    }

    /**
     * The UUID of a dependency of this pack that is not in behavior_packs/, or null when all are there.
     */
    public function findMissingDependency(BehaviorPack $pack) : ?string{
        foreach($pack->getManifest()->getDependencies() as $uuid){
            if(!isset($this->packs[$uuid])){
                return $uuid;
            }
        }

        return null;
    }

    /**
     * Enables a pack and the packs it needs.
     *
     * @return list<BehaviorPack> the packs that were not enabled before, dependencies first; empty when it was already enabled
     *
     * @throws PackException when a dependency was not found
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
     * Disables a pack and every enabled pack that needs it.
     *
     * @return list<BehaviorPack> the packs that were disabled; empty when it was not enabled
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
            $removed[] = $this->packs[$uuid];
        }

        $this->enabled = array_values(array_diff($this->enabled, $remove));

        $this->saveState();
        $this->rebuild();

        return $removed;
    }

    public function getFunction(string $id) : ?McFunction{
        $id = strtolower(trim($id));

        return $this->functions[$id] ?? null;
    }

    /**
     * @return list<string> sorted
     */
    public function getFunctionIds() : array{
        $ids = array_map(strval(...), array_keys($this->functions)); //PHP turns an id like "123" into an int key
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @return list<string> ids of the functions that run every tick
     */
    public function getTickFunctionIds() : array{
        return $this->tickFunctions;
    }

    /**
     * @param list<BehaviorPack> $added
     * @param list<string>       $path  packs being visited, to stop on circular dependencies
     *
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

        foreach($pack->getManifest()->getDependencies() as $dependency){
            $required = $this->packs[$dependency] ?? throw new PackException("dependency {$dependency} was not found");
            $this->collectForEnable($required, $added, [...$path, $uuid]);
        }

        $added[] = $pack;
    }

    /**
     * Works out which enabled packs can run and reloads their functions.
     */
    private function rebuild() : void{
        $this->active = [];
        $pending = [];

        foreach(array_unique($this->enabled) as $uuid){
            if(isset($this->packs[$uuid])){
                $pending[] = $uuid;
            }else{
                $this->logger->warning($this->language->translate("pack.missing", ["uuid" => $uuid]));
            }
        }

        do{
            $progress = false;

            foreach($pending as $index => $uuid){
                $ready = true;

                foreach($this->packs[$uuid]->getManifest()->getDependencies() as $dependency){
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
            $pack = $this->packs[$uuid];

            foreach($pack->getManifest()->getDependencies() as $dependency){
                if(!in_array($dependency, $this->active, true)){
                    $this->logger->warning($this->language->translate("pack.disabledDependency", ["name" => $pack->getName(), "dependency" => $dependency]));
                    break;
                }
            }
        }

        $this->functions = [];
        $this->tickFunctions = [];

        foreach($this->active as $uuid){
            $pack = $this->packs[$uuid];

            if($pack->getManifest()->hasScripts()){
                $entry = $pack->getManifest()->getScriptEntry() ?? "scripts/main.js";
                $this->logger->info("Behavior pack \"{$pack->getName()}\" includes script module (entry: {$entry}); loading via script runtime.");
            }

            $skipped = [];

            foreach($pack->loadFunctions($skipped) as $id => $function){
                if(isset($this->functions[$id])){
                    $this->logger->warning($this->language->translate("pack.functionOverride", [
                        "function" => $id,
                        "pack" => $pack->getName(),
                        "other" => $this->functions[$id]->getPack()->getName()
                    ]));
                }

                $this->functions[$id] = $function;
            }

            foreach($skipped as [$file, $reason]){
                $this->logger->warning($this->language->translate("pack.functionSkipped", ["pack" => $pack->getName(), "file" => $file, "reason" => $reason]));
            }

            foreach($pack->readTickFunctions() as $id){
                if(!isset($this->functions[$id])){
                    $this->logger->warning($this->language->translate("pack.tickMissing", ["pack" => $pack->getName(), "function" => $id]));
                }elseif(!in_array($id, $this->tickFunctions, true)){
                    $this->tickFunctions[] = $id;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readState() : array{
        if(!is_file($this->stateFile)){
            return [];
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
            return [];
        }

        $enabled = [];

        foreach($list as $uuid){
            if(is_string($uuid) && !in_array(strtolower($uuid), $enabled, true)){
                $enabled[] = strtolower($uuid);
            }
        }

        return $enabled;
    }

    private function saveState() : void{
        $temporary = $this->stateFile . ".tmp";

        try{
            $json = json_encode(["enabled" => $this->enabled], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            if(file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $this->stateFile)){
                throw new PackException("the file cannot be written");
            }
        }catch(Throwable $exception){
            @unlink($temporary);
            $this->logger->error($this->language->translate("pack.saveFailed", ["file" => $this->stateFile, "reason" => $exception->getMessage()]));
        }
    }
}
