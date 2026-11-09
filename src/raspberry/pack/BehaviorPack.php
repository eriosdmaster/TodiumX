<?php

declare(strict_types=1);

namespace raspberry\pack;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function array_values;
use function count;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * A behavior pack folder that was found in behavior_packs/ and has a valid manifest.
 */
final class BehaviorPack{

    public const MAX_FUNCTION_SIZE = 1048576;

    public const MAX_FUNCTION_LINES = 10000;

    private const FUNCTION_ID_PATTERN = '#^[a-z0-9_.-]+(/[a-z0-9_.-]+)*$#D';

    public function __construct(
        private string $path,
        private string $folderName,
        private PackManifest $manifest
    ){}

    public function getPath() : string{
        return $this->path;
    }

    /**
     * The name of the folder inside behavior_packs/.
     */
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

    /**
     * Reads every functions/**.mcfunction file. Files that cannot be used are left out and listed in $skipped.
     *
     * @param list<array{string, string}> $skipped [file, reason] pairs, filled by this method
     *
     * @return array<string, McFunction> id => function
     */
    public function loadFunctions(array &$skipped) : array{
        $skipped = [];
        $root = $this->path . "/functions";

        if(!is_dir($root)){
            return [];
        }

        $functions = [];

        try{
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        }catch(Throwable){
            return [];
        }

        foreach($files as $file){
            if(!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink() || !str_ends_with(strtolower($file->getFilename()), ".mcfunction")){
                continue;
            }

            $relative = str_replace("\\", "/", substr($file->getPathname(), strlen($root) + 1));
            $id = strtolower(substr($relative, 0, -strlen(".mcfunction")));

            if(preg_match(self::FUNCTION_ID_PATTERN, $id) !== 1){
                $skipped[] = [$relative, "the name can only use letters, numbers, _ - . and folders"];
                continue;
            }

            if(isset($functions[$id])){
                $skipped[] = [$relative, "another file already has the function name \"{$id}\""];
                continue;
            }

            if($file->getSize() > self::MAX_FUNCTION_SIZE){
                $skipped[] = [$relative, "the file is larger than " . self::MAX_FUNCTION_SIZE . " bytes"];
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if($contents === false || preg_match("//u", $contents) !== 1){
                $skipped[] = [$relative, "the file cannot be read or is not valid UTF-8"];
                continue;
            }

            $lines = self::parseLines($contents);

            if(count($lines) > self::MAX_FUNCTION_LINES){
                $skipped[] = [$relative, "it has more than " . self::MAX_FUNCTION_LINES . " commands"];
                continue;
            }

            $functions[$id] = new McFunction($id, $this, $lines);
        }

        return $functions;
    }

    /**
     * The functions listed in functions/tick.json, which run every tick.
     *
     * @return list<string> function ids
     */
    public function readTickFunctions() : array{
        $path = $this->path . "/functions/tick.json";

        if(!is_file($path)){
            return [];
        }

        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true, 8);
        $values = is_array($data) ? ($data["values"] ?? null) : null;

        if(!is_array($values)){
            return [];
        }

        $ids = [];

        foreach($values as $value){
            if(is_string($value)){
                $ids[] = strtolower(trim($value));
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    /**
     * Load JSON definition files from a subdirectory (entities/, items/, blocks/).
     * @return list<array<string, mixed>>
     */
    public function readDefinitionFiles(string $subdir) : array{
        $dir = $this->path . "/" . trim($subdir, "/");
        $results = [];
        if(!is_dir($dir)){
            return [];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if(!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== "json"){
                continue;
            }
            $contents = @file_get_contents($file->getPathname());
            $data = is_string($contents) ? json_decode($contents, true, 16) : null;
            if(is_array($data)){
                $data["__pack_path"] = str_replace("\\", "/", substr($file->getPathname(), strlen($this->path) + 1));
                $results[] = $data;
            }
        }
        return $results;
    }

    /**
     * @return array<string, string> relative path => contents (plus __entry__ key)
     */
    public function readScripts() : array{
        $entry = $this->manifest->getScriptEntry();
        $scripts = [];
        $dir = $this->path . "/scripts";
        if(!is_dir($dir)){
            if($entry !== null){
                $scripts["__entry__"] = $entry;
            }
            return $scripts;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if(!$file instanceof SplFileInfo || !$file->isFile()){
                continue;
            }
            $ext = strtolower($file->getExtension());
            if($ext !== "js" && $ext !== "ts" && $ext !== "mjs" && $ext !== "cjs"){
                continue;
            }
            $rel = str_replace("\\", "/", substr($file->getPathname(), strlen($this->path) + 1));
            $contents = @file_get_contents($file->getPathname());
            if(is_string($contents)){
                $scripts[$rel] = $contents;
            }
        }
        if($entry !== null){
            $scripts["__entry__"] = $entry;
        }
        return $scripts;
    }

    private static function parseLines(string $contents) : array{
        if(str_starts_with($contents, "\xEF\xBB\xBF")){
            $contents = substr($contents, 3);
        }

        $lines = [];

        foreach(preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line){
            $line = trim($line);

            if($line === "" || $line[0] === "#"){
                continue;
            }

            $line = ltrim($line, "/");

            if($line !== ""){
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
