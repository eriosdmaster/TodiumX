<?php

declare(strict_types=1);

namespace raspberry\plugin;

use function array_values;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function trim;

class PluginDescription{

    private string $name;
    private string $version;
    private string $main;
    private string $api;
    private string $description;
    /** @var list<string> */
    private array $authors;
    /** @var list<string> */
    private array $depend;
    /** @var list<string> */
    private array $softDepend;
    /** @var list<string> */
    private array $loadBefore;
    /** @var array<string, array<string, mixed>> */
    private array $commands;
    /** @var array<string, array<string, mixed>> */
    private array $permissions;

    /**
     * @param array<string, mixed> $data
     * @throws PluginException
     */
    public function __construct(array $data){
        $name = $data["name"] ?? null;
        $version = $data["version"] ?? "1.0.0";
        $main = $data["main"] ?? null;
        if(!is_string($name) || trim($name) === ""){
            throw new PluginException("plugin.json: 'name' is required");
        }
        if(!is_string($main) || trim($main) === ""){
            throw new PluginException("plugin.json: 'main' class is required");
        }
        $this->name = trim($name);
        $this->version = is_string($version) ? $version : "1.0.0";
        $this->main = trim($main);
        $this->api = is_string($data["api"] ?? null) ? $data["api"] : (is_string($data["api-version"] ?? null) ? $data["api-version"] : "*");
        $this->description = is_string($data["description"] ?? null) ? $data["description"] : "";
        $authors = [];
        if(is_string($data["author"] ?? null)){
            $authors[] = $data["author"];
        }
        if(is_array($data["authors"] ?? null)){
            foreach($data["authors"] as $a){ if(is_string($a)) $authors[] = $a; }
        }
        $this->authors = array_values($authors);
        $this->depend = $this->stringList($data["depend"] ?? $data["depends"] ?? []);
        $this->softDepend = $this->stringList($data["softdepend"] ?? $data["soft-depend"] ?? []);
        $this->loadBefore = $this->stringList($data["loadbefore"] ?? $data["load-before"] ?? []);
        $this->commands = is_array($data["commands"] ?? null) ? $data["commands"] : [];
        $this->permissions = is_array($data["permissions"] ?? null) ? $data["permissions"] : [];
    }

    /**
     * @throws PluginException
     */
    public static function fromFile(string $path) : self{
        if(!is_file($path)){
            throw new PluginException("plugin manifest not found: {$path}");
        }
        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true);
        if(!is_array($data)){
            throw new PluginException("plugin manifest is not valid JSON: {$path}");
        }
        return new self($data);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value) : array{
        if(!is_array($value)) return [];
        $out = [];
        foreach($value as $v){ if(is_string($v) && trim($v) !== "") $out[] = trim($v); }
        return array_values($out);
    }

    public function getName() : string{ return $this->name; }
    public function getVersion() : string{ return $this->version; }
    public function getMain() : string{ return $this->main; }
    public function getApi() : string{ return $this->api; }
    public function getDescription() : string{ return $this->description; }
    /** @return list<string> */
    public function getAuthors() : array{ return $this->authors; }
    /** @return list<string> */
    public function getDepend() : array{ return $this->depend; }
    /** @return list<string> */
    public function getSoftDepend() : array{ return $this->softDepend; }
    /** @return list<string> */
    public function getLoadBefore() : array{ return $this->loadBefore; }
    /** @return array<string, array<string, mixed>> */
    public function getCommands() : array{ return $this->commands; }
    /** @return array<string, array<string, mixed>> */
    public function getPermissions() : array{ return $this->permissions; }
    public function getFullName() : string{ return $this->name . " v" . $this->version; }
}
