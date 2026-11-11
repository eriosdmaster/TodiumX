<?php

declare(strict_types=1);

namespace raspberry\pack;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

use function array_values;
use function file_get_contents;
use function filesize;
use function hash;
use function crc32;
use function fclose;
use function fopen;
use function fwrite;
use function function_exists;
use function gzdeflate;
use function md5;
use function pack;
use function sys_get_temp_dir;
use function bin2hex;
use function class_exists;
use function hash_file;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function ksort;
use function ltrim;
use function max;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unlink;

/**
 * A resource pack folder or archive found in resource_packs/.
 * Supports folder packs and .zip/.mcpack archives.
 */
final class ResourcePack{

    private string $path;
    private string $folderName;
    private PackManifest $manifest;
    private ?string $archivePath = null;
    private ?string $sha256 = null;
    private ?int $packSize = null;
    private ?string $contentId = null;

    private function __construct(string $path, string $folderName, PackManifest $manifest){
        $this->path = $path;
        $this->folderName = $folderName;
        $this->manifest = $manifest;
        $this->contentId = $manifest->getUuid() . "_" . $manifest->getVersion();
    }

    /**
     * @throws PackException
     */
    public static function fromPath(string $path, string $folderName) : self{
        if(is_dir($path)){
            $manifest = PackManifest::fromFileTyped($path . "/manifest.json", PackManifest::TYPE_RESOURCE);
            return new self($path, $folderName, $manifest);
        }

        if(is_file($path) && (str_ends_with(strtolower($path), ".zip") || str_ends_with(strtolower($path), ".mcpack"))){
            $tempDir = $path . ".extract";
            $manifest = self::readManifestFromArchive($path);
            $pack = new self($path, $folderName, $manifest);
            $pack->archivePath = $path;
            return $pack;
        }

        throw new PackException("resource pack path is not a directory or supported archive");
    }

    /**
     * @throws PackException
     */
    private static function readManifestFromArchive(string $archivePath) : PackManifest{
        if(!class_exists(ZipArchive::class)){
            throw new PackException("ZipArchive extension is required to load archive resource packs");
        }
        $zip = new ZipArchive();
        if($zip->open($archivePath) !== true){
            throw new PackException("could not open resource pack archive");
        }
        $index = $zip->locateName("manifest.json", ZipArchive::FL_NODIR | ZipArchive::FL_NOCASE);
        if($index === false){
            $index = $zip->locateName("manifest.json", ZipArchive::FL_NOCASE);
        }
        if($index === false){
            $zip->close();
            throw new PackException("manifest.json is missing in archive");
        }
        $contents = $zip->getFromIndex($index);
        $zip->close();
        if(!is_string($contents)){
            throw new PackException("manifest.json is unreadable in archive");
        }
        $temp = $archivePath . ".manifest.json.tmp";
        if(file_put_contents($temp, $contents) === false){
            throw new PackException("failed to read manifest from archive");
        }
        try{
            return PackManifest::fromFileTyped($temp, PackManifest::TYPE_RESOURCE);
        }finally{
            @unlink($temp);
        }
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

    public function getContentId() : string{
        return $this->contentId ?? "";
    }

    public function isArchive() : bool{
        return $this->archivePath !== null;
    }

    /**
     * Returns the raw pack bytes for transfer (zip). Folder packs are zipped on demand into a temp file.
     * @throws PackException
     */
    public function getPackBytesPath() : string{
        if($this->archivePath !== null && is_file($this->archivePath)){
            return $this->archivePath;
        }
        return $this->buildArchive();
    }

    /**
     * Zips the folder for transfer. Uses ZipArchive when the PHP build has it, and a built-in writer when it does not
     * (many hosts ship PHP without the zip extension). The zip goes in the pack folder, or in the temp folder when
     * the pack folder cannot be written.
     *
     * @throws PackException
     */
    private function buildArchive() : string{
        $files = [];
        $root = $this->path;
        $length = strlen($root);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if(!$file instanceof SplFileInfo || !$file->isFile()){
                continue;
            }
            $full = $file->getPathname();
            $relative = ltrim(str_replace("\\", "/", substr($full, $length)), "/");
            if(str_starts_with($relative, ".transfer")){
                continue;
            }
            $files[$relative] = $full;
        }
        ksort($files);
        if($files === []){
            throw new PackException("the resource pack folder is empty");
        }

        $targets = [$this->path . "/.transfer.zip", sys_get_temp_dir() . "/todiumx_" . md5($this->path) . ".zip"];
        foreach($targets as $target){
            if(class_exists(ZipArchive::class)){
                $zip = new ZipArchive();
                if($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true){
                    foreach($files as $relative => $full){
                        $zip->addFile($full, $relative);
                    }
                    if($zip->close() && is_file($target) && filesize($target) > 0){
                        return $this->archiveBuilt($target);
                    }
                }
            }
            if(self::writeZip($target, $files)){
                return $this->archiveBuilt($target);
            }
        }
        throw new PackException("could not create the transfer archive for resource pack \"{$this->folderName}\"");
    }

    private function archiveBuilt(string $target) : string{
        $this->archivePath = $target;
        $this->sha256 = null;
        $this->packSize = null;
        return $target;
    }

    /**
     * Minimal zip writer (no extension needed).
     *
     * @param array<string, string> $files name inside the zip => file on disk
     */
    private static function writeZip(string $target, array $files) : bool{
        $handle = @fopen($target, "wb");
        if($handle === false){
            return false;
        }
        $central = "";
        $offset = 0;
        $count = 0;
        foreach($files as $name => $full){
            $data = @file_get_contents($full);
            if($data === false){
                fclose($handle);
                return false;
            }
            $crc = crc32($data);
            $size = strlen($data);
            $method = 0;
            if(function_exists("gzdeflate") && $size > 0){
                $deflated = gzdeflate($data, 6);
                if($deflated !== false && strlen($deflated) < $size){
                    $data = $deflated;
                    $method = 8;
                }
            }
            $compressed = strlen($data);
            $nameLength = strlen($name);
            $header = pack("VvvvvvVVVvv", 0x04034b50, 20, 0x0800, $method, 0, 0x21, $crc, $compressed, $size, $nameLength, 0) . $name;
            if(fwrite($handle, $header) === false || fwrite($handle, $data) === false){
                fclose($handle);
                return false;
            }
            $central .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0x0800, $method, 0, 0x21, $crc, $compressed, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($header) + $compressed;
            $count++;
        }
        $end = pack("VvvvvVVv", 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
        $ok = fwrite($handle, $central . $end) !== false;
        fclose($handle);
        return $ok;
    }

    public function getPackSize() : int{
        if($this->packSize !== null){
            return $this->packSize;
        }
        try{
            $path = $this->getPackBytesPath();
        }catch(PackException){
            return 0;
        }
        $size = filesize($path);
        $this->packSize = $size === false ? 0 : (int) $size;
        return $this->packSize;
    }

    /**
     * SHA-256 of the transfer archive. The client compares it with the downloaded bytes, so the packet needs the raw
     * 32 bytes ($raw = true); the hex form is only for display.
     */
    public function getSha256(bool $raw = true) : string{
        if($this->sha256 === null){
            try{
                $path = $this->getPackBytesPath();
            }catch(PackException){
                return "";
            }
            $hash = hash_file("sha256", $path, true);
            if($hash === false){
                return "";
            }
            $this->sha256 = $hash;
        }
        return $raw ? $this->sha256 : bin2hex($this->sha256);
    }

    /**
     * Read a chunk of the pack archive for transfer.
     */
    public function getPackChunk(int $offset, int $length) : string{
        try{
            $path = $this->getPackBytesPath();
        }catch(PackException){
            return "";
        }
        $handle = @fopen($path, "rb");
        if($handle === false){
            return "";
        }
        try{
            if(@fseek($handle, $offset) !== 0){
                return "";
            }
            $data = @fread($handle, max(0, $length));
            return is_string($data) ? $data : "";
        }finally{
            @fclose($handle);
        }
    }

    /**
     * Load JSON definition files from a subdirectory (entities/, items/, blocks/, textures/ etc.).
     * @return list<array<string, mixed>>
     */
    public function readDefinitionFiles(string $subdir) : array{
        $dir = $this->path . "/" . trim($subdir, "/");
        $results = [];
        if($this->archivePath !== null && !is_dir($this->path)){
            // archive-only pack: read from zip
            if(!class_exists(ZipArchive::class)){
                return [];
            }
            $zip = new ZipArchive();
            if($zip->open($this->archivePath) !== true){
                return [];
            }
            $prefix = trim($subdir, "/") . "/";
            for($i = 0; $i < $zip->numFiles; $i++){
                $name = $zip->getNameIndex($i);
                if(!is_string($name)){
                    continue;
                }
                $norm = str_replace("\\", "/", $name);
                if(str_starts_with(strtolower($norm), strtolower($prefix)) && str_ends_with(strtolower($norm), ".json")){
                    $contents = $zip->getFromIndex($i);
                    $data = is_string($contents) ? json_decode($contents, true, 16) : null;
                    if(is_array($data)){
                        $data["__pack_path"] = $norm;
                        $results[] = $data;
                    }
                }
            }
            $zip->close();
            return $results;
        }

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

    public function readScripts() : array{
        $entry = $this->manifest->getScriptEntry();
        $scripts = [];
        $dir = $this->path . "/scripts";
        if(!is_dir($dir)){
            return [];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if(!$file instanceof SplFileInfo || !$file->isFile()){
                continue;
            }
            $ext = strtolower($file->getExtension());
            if($ext !== "js" && $ext !== "ts"){
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
}
