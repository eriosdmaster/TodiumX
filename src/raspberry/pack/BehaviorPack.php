<?php

declare(strict_types=1);

namespace raspberry\pack;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

use function array_keys;
use function class_exists;
use function count;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function ltrim;
use function md5;
use function sort;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strtolower;
use function str_starts_with;
use function strcasecmp;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;

use const JSON_BIGINT_AS_STRING;

/**
 * A behavior pack folder or archive found in behavior_packs/.
 * Supports folder packs and .zip/.mcpack archives, and indexes the content
 * files the server can apply: entities, items, blocks, recipes, functions,
 * loot tables, trade tables, spawn rules, features and feature rules.
 */
final class BehaviorPack{

    /** content folders the server knows about, in the order the official content log lists them */
    public const CONTENT_CATEGORIES = [
        "entities", "items", "blocks", "recipes", "functions",
        "loot_tables", "trade_tables", "spawn_rules", "features", "feature_rules"
    ];

    private string $path;
    private string $folderName;
    private PackManifest $manifest;
    private ?string $archivePath = null;
    /** inside a .zip/.mcpack: the folder prefix every file sits under ("" for flat archives), null for folder packs */
    private ?string $archiveRoot = null;
    /** @var array<string, list<string>> category => relative file paths, in apply order */
    private array $contentIndex = [];

    private function __construct(string $path, string $folderName, PackManifest $manifest){
        $this->path = $path;
        $this->folderName = $folderName;
        $this->manifest = $manifest;
    }

    /**
     * @throws PackException
     */
    public static function fromPath(string $path, string $folderName) : self{
        if(is_dir($path)){
            $pack = new self($path, $folderName, PackManifest::fromFileTyped($path . "/manifest.json", PackManifest::TYPE_BEHAVIOR));
            $pack->indexFolder();
            return $pack;
        }

        if(is_file($path) && (str_ends_with(strtolower($path), ".zip") || str_ends_with(strtolower($path), ".mcpack"))){
            $pack = new self($path, $folderName, self::readManifestFromArchive($path));
            $pack->archivePath = $path;
            $pack->indexArchive();
            return $pack;
        }

        throw new PackException("behavior pack path is not a directory or supported archive");
    }

    /**
     * @throws PackException
     */
    private static function readManifestFromArchive(string $archivePath) : PackManifest{
        $names = self::listArchive($archivePath);
        $root = self::findArchiveRoot($names);
        if($root === null){
            throw new PackException("manifest.json is missing in archive");
        }
        $entry = $root . "manifest.json";
        foreach($names as $name){
            if(strcasecmp($name, $entry) === 0){
                $contents = self::readArchiveEntry($archivePath, $name);
                $temp = sys_get_temp_dir() . "/todiumx_bmanifest_" . md5($archivePath . $contents) . ".json";
                if(file_put_contents($temp, $contents) === false){
                    throw new PackException("failed to read manifest from archive");
                }
                try{
                    return PackManifest::fromFileTyped($temp, PackManifest::TYPE_BEHAVIOR);
                }finally{
                    @unlink($temp);
                }
            }
        }
        throw new PackException("manifest.json is missing in archive"); //unreachable, findArchiveRoot matched it
    }

    /**
     * @return list<string> entry names, backslashes normalized
     *
     * @throws PackException
     */
    private static function listArchive(string $archivePath) : array{
        if(!class_exists(ZipArchive::class)){
            throw new PackException("ZipArchive extension is required to load archive behavior packs");
        }
        $zip = new ZipArchive();
        if($zip->open($archivePath) !== true){
            throw new PackException("could not open behavior pack archive");
        }
        try{
            $names = [];
            for($index = 0; $index < $zip->numFiles; $index++){
                $name = $zip->getNameIndex($index);
                if(is_string($name) && $name !== "" && !str_ends_with($name, "/")){
                    $names[] = str_replace("\\", "/", ltrim($name, "/"));
                }
            }
            return $names;
        }finally{
            $zip->close();
        }
    }

    /**
     * Packs are zipped two ways: flat ("manifest.json" at the root) or as a folder
     * ("MyPack/manifest.json"). The root prefix is taken from the shallowest manifest entry
     * so content paths map to the same relative names in both layouts.
     *
     * @param list<string> $names
     */
    private static function findArchiveRoot(array $names) : ?string{
        $best = null;
        foreach($names as $name){
            if(strcasecmp(substr($name, -13), "manifest.json") !== 0){
                continue;
            }
            $root = substr($name, 0, strlen($name) - strlen("manifest.json"));
            if($best === null || substr_count($root, "/") < substr_count($best, "/")){
                $best = $root;
            }
        }
        return $best;
    }

    /**
     * @throws PackException
     */
    private static function readArchiveEntry(string $archivePath, string $entry) : string{
        if(!class_exists(ZipArchive::class)){
            throw new PackException("ZipArchive extension is required to load archive behavior packs");
        }
        $zip = new ZipArchive();
        if($zip->open($archivePath) !== true){
            throw new PackException("could not open behavior pack archive");
        }
        try{
            $contents = $zip->getFromName($entry);
            if($contents === false){
                throw new PackException("{$entry} is unreadable in archive");
            }
            return $contents;
        }finally{
            $zip->close();
        }
    }

    private function indexFolder() : void{
        $root = $this->path;
        $length = strlen($root);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach($iterator as $file){
            if(!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()){
                continue;
            }
            $relative = ltrim(str_replace("\\", "/", substr($file->getPathname(), $length)), "/");
            $files[$relative] = true;
        }
        $this->contentIndex = self::buildIndex(array_keys($files));
    }

    private function indexArchive() : void{
        $names = self::listArchive($this->archivePath ?? "");
        $root = self::findArchiveRoot($names);
        $this->archiveRoot = $root ?? "";
        if($root === null){
            $this->contentIndex = self::buildIndex([]);
            return;
        }
        $length = strlen($root);
        $files = [];
        foreach($names as $name){
            if(str_starts_with(strtolower($name), strtolower($root))){
                $files[] = substr($name, $length);
            }
        }
        $this->contentIndex = self::buildIndex($files);
    }

    /**
     * @param list<string> $relativePaths
     *
     * @return array<string, list<string>> category => relative paths
     */
    private static function buildIndex(array $relativePaths) : array{
        $index = [];
        foreach(self::CONTENT_CATEGORIES as $category){
            $prefix = strtolower($category) . "/";
            $files = [];
            foreach($relativePaths as $relative){
                if(str_starts_with(strtolower($relative), $prefix)){
                    $files[] = $relative;
                }
            }
            sort($files);
            $index[$category] = $files;
        }
        return $index;
    }

    public function getPath() : string{
        return $this->path;
    }

    public function getFolderName() : string{
        return $this->folderName;
    }

    public function getManifest() : PackManifest{
        return $this->manifest;
    }

    public function getName() : string{
        return $this->manifest->getName();
    }

    public function getUuid() : string{
        return $this->manifest->getUuid();
    }

    public function getVersion() : string{
        return $this->manifest->getVersion();
    }

    public function isArchive() : bool{
        return $this->archivePath !== null;
    }

    public function hasScripts() : bool{
        return $this->manifest->hasScripts();
    }

    /**
     * @return list<string> relative file paths in this category, sorted
     */
    public function getContentFiles(string $category) : array{
        return $this->contentIndex[strtolower($category)] ?? [];
    }

    /**
     * @return array<string, list<string>> category => relative file paths
     */
    public function getContentIndex() : array{
        return $this->contentIndex;
    }

    public function hasContent(string $category) : bool{
        return $this->contentIndex[strtolower($category)] !== [];
    }

    /**
     * Total number of indexed content files across every category.
     */
    public function getContentCount() : int{
        $total = 0;
        foreach($this->contentIndex as $files){
            $total += count($files);
        }
        return $total;
    }

    /**
     * Reads a content file by its relative path inside the pack, for folder and archive packs.
     *
     * @throws PackException
     */
    public function readContentFile(string $relative) : string{
        if($this->archivePath !== null){
            $entry = ($this->archiveRoot ?? "") . $relative;
            foreach(self::listArchive($this->archivePath) as $name){
                if(strcasecmp($name, $entry) === 0){
                    return self::readArchiveEntry($this->archivePath, $name);
                }
            }
            throw new PackException("file {$relative} is missing in archive");
        }
        $full = $this->path . "/" . $relative;
        if(!is_file($full)){
            throw new PackException("file {$relative} is missing in pack folder");
        }
        $contents = file_get_contents($full);
        if($contents === false){
            throw new PackException("file {$relative} cannot be read");
        }
        return $contents;
    }

    /**
     * Lists the pack files under a relative prefix ("scripts/" for the Script API),
     * for folder and archive packs alike. Paths are relative to the pack root with
     * forward slashes, sorted.
     *
     * @return list<string>
     */
    public function listPackFiles(string $prefix) : array{
        $prefix = strtolower($prefix);
        $files = [];

        if($this->archivePath !== null){
            $root = $this->archiveRoot ?? "";
            foreach(self::listArchive($this->archivePath) as $name){
                $relative = $root === "" ? $name : (str_starts_with(strtolower($name), strtolower($root)) ? substr($name, strlen($root)) : null);
                if($relative === null || $relative === ""){
                    continue;
                }
                if($prefix === "" || str_starts_with(strtolower($relative), $prefix)){
                    $files[] = $relative;
                }
            }
        }else{
            if(!is_dir($this->path)){
                return [];
            }
            $prefixFull = $this->path . ($prefix === "" ? "" : "/" . rtrim($prefix, "/"));
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($prefixFull, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME)
            );
            foreach($iterator as $pathname){
                if(!is_string($pathname) || is_dir($pathname)){
                    continue;
                }
                $relative = str_replace("\\", "/", substr($pathname, strlen($this->path) + 1));
                if($prefix === "" || str_starts_with(strtolower($relative), $prefix)){
                    $files[] = $relative;
                }
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Parses a JSON content file, returning null (and telling the caller why) when it is not usable.
     */
    public function readContentJson(string $relative, ?string &$error = null) : ?array{
        try{
            $contents = $this->readContentFile($relative);
        }catch(PackException $exception){
            $error = $exception->getMessage();
            return null;
        }

        $data = json_decode($contents, true, 64, JSON_BIGINT_AS_STRING);

        if(!is_array($data)){
            $error = "not valid JSON";
            return null;
        }
        return $data;
    }
}
