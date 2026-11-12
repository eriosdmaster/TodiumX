<?php

declare(strict_types=1);

namespace raspberry\pack;

use function count;
use function file_get_contents;
use function filesize;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const JSON_BIGINT_AS_STRING;

/**
 * The parts of a pack's manifest.json the server needs.
 */
final class PackManifest{

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private const MAX_SIZE = 262144;

    public const TYPE_RESOURCE = "resource";

    /** module types that make a pack a resource pack */
    private const RESOURCE_MODULES = ["resources", "client_data"];

    /**
     * @param list<string> $moduleTypes
     * @param list<string> $dependencies UUIDs of the packs this one needs; script modules like @minecraft/server are not packs
     * @param array<string, string> $scriptEntry entry points by module type (e.g. "script" => "scripts/main.js")
     */
    private function __construct(
        private string $name,
        private string $description,
        private string $uuid,
        private string $version,
        private array $moduleTypes,
        private array $dependencies,
        private string $packType,
        private array $scriptEntry
    ){}

    /**
     * @throws PackException when the file is missing or is not a usable pack manifest
     */
    public static function fromFile(string $path) : self{
        return self::fromFileTyped($path, null);
    }

    /**
     * @throws PackException when the file is missing or is not a usable pack manifest
     */
    public static function fromFileTyped(string $path, ?string $expectedType) : self{
        if(!is_file($path)){
            throw new PackException("manifest.json is missing");
        }

        $size = filesize($path);

        if($size === false || $size > self::MAX_SIZE){
            throw new PackException("manifest.json is unreadable or larger than " . self::MAX_SIZE . " bytes");
        }

        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true, 8, JSON_BIGINT_AS_STRING);

        if(!is_array($data)){
            throw new PackException("manifest.json is not valid JSON");
        }

        $format = $data["format_version"] ?? 0;

        if(!is_int($format) || $format < 1 || $format > 3){
            throw new PackException("format_version must be 1, 2 or 3");
        }

        $header = $data["header"] ?? null;

        if(!is_array($header)){
            throw new PackException("manifest.json has no header");
        }

        $uuid = self::readUuid($header["uuid"] ?? null, "header.uuid");
        $version = self::readVersion($header["version"] ?? null, "header.version");

        $modules = $data["modules"] ?? null;

        if(!is_array($modules)){
            throw new PackException("manifest.json has no modules");
        }

        $moduleTypes = [];
        $scriptEntry = [];

        foreach($modules as $module){
            $type = is_array($module) ? ($module["type"] ?? null) : null;

            if(is_string($type)){
                $moduleTypes[] = $type;
                $entry = is_array($module) ? ($module["entry"] ?? null) : null;
                if(is_string($entry) && $entry !== ""){
                    $scriptEntry[$type] = $entry;
                }
            }
        }

        $packType = self::detectType($moduleTypes);
        if($expectedType !== null && $packType !== $expectedType){
            throw new PackException("expected a {$expectedType} pack, found {$packType} modules");
        }
        if($packType === null){
            throw new PackException("not a resource pack (needs a \"resources\" or \"client_data\" module)");
        }

        $dependencies = [];
        $rawDependencies = $data["dependencies"] ?? [];

        if(is_array($rawDependencies)){
            foreach($rawDependencies as $dependency){
                $dependencyUuid = is_array($dependency) ? ($dependency["uuid"] ?? null) : null;

                //script modules list "@minecraft/server" as a string version: not a pack UUID
                if(is_string($dependencyUuid) && preg_match(self::UUID_PATTERN, strtolower(trim($dependencyUuid))) === 1){
                    $dependencies[] = strtolower(trim($dependencyUuid));
                }
            }
        }

        $name = $header["name"] ?? "Unnamed Pack";
        $name = is_string($name) ? $name : "Unnamed Pack";
        $description = $header["description"] ?? "";

        return new self(trim($name), is_string($description) ? $description : "", $uuid, $version, $moduleTypes, $dependencies, $packType, $scriptEntry);
    }

    public function getName() : string{
        return $this->name;
    }

    public function getDescription() : string{
        return $this->description;
    }

    public function getUuid() : string{
        return $this->uuid;
    }

    public function getVersion() : string{
        return $this->version;
    }

    public function getPackType() : string{
        return $this->packType;
    }

    public function isResourcePack() : bool{
        return $this->packType === self::TYPE_RESOURCE;
    }

    /**
     * @return list<string>
     */
    public function getModuleTypes() : array{
        return $this->moduleTypes;
    }

    public function hasScripts() : bool{
        return in_array("script", $this->moduleTypes, true);
    }

    public function getScriptEntry() : ?string{
        return $this->scriptEntry["script"] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getScriptEntries() : array{
        return $this->scriptEntry;
    }

    /**
     * @return list<string>
     */
    public function getDependencies() : array{
        return $this->dependencies;
    }

    /**
     * @param list<string> $moduleTypes
     */
    private static function detectType(array $moduleTypes) : ?string{
        if(in_array("resources", $moduleTypes, true)){
            return self::TYPE_RESOURCE;
        }
        if(in_array("client_data", $moduleTypes, true)){
            return self::TYPE_RESOURCE;
        }
        return null;
    }

    private static function readUuid(mixed $value, string $field) : string{
        $uuid = is_string($value) ? strtolower(trim($value)) : "";

        if(preg_match(self::UUID_PATTERN, $uuid) !== 1){
            throw new PackException("{$field} is not a valid UUID");
        }

        return $uuid;
    }

    /**
     * Accepts [1, 0, 0] and "1.0.0".
     */
    private static function readVersion(mixed $value, string $field) : string{
        if(is_string($value) && preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/D', $value) === 1){
            return $value;
        }

        if(is_array($value) && count($value) === 3){
            foreach($value as $part){
                if(!is_int($part) || $part < 0){
                    throw new PackException("{$field} must be three whole numbers, like [1, 0, 0]");
                }
            }

            return implode(".", $value);
        }

        throw new PackException("{$field} must be three whole numbers, like [1, 0, 0]");
    }
}
