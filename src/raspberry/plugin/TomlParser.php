<?php

declare(strict_types=1);

namespace raspberry\plugin;

/** Small strict TOML reader for plugin manifests (strings, numbers, booleans, arrays and tables). */
final class TomlParser{

    /** @return array<string, mixed> */
    public static function parse(string $text) : array{
        $data = [];
        $tablePath = [];
        $lineNumber = 0;
        foreach(preg_split('/\r\n|\n|\r/', $text) ?: [] as $line){
            ++$lineNumber;
            $line = trim(self::stripComment($line));
            if($line === "") continue;
            if($line[0] === "["){
                $arrayTable = str_starts_with($line, "[[");
                $closing = $arrayTable ? "]]" : "]";
                if(!str_ends_with($line, $closing)) self::fail($lineNumber, "malformed table header");
                $inside = trim(substr($line, $arrayTable ? 2 : 1, $arrayTable ? -2 : -1));
                $path = self::parseKeyPath($inside, $lineNumber);
                if($path === []) self::fail($lineNumber, "empty table name");
                if($arrayTable){
                    $parent =& self::tableAt($data, array_slice($path, 0, -1), $lineNumber);
                    $key = $path[array_key_last($path)];
                    if(!isset($parent[$key])) $parent[$key] = [];
                    if(!is_array($parent[$key]) || (array_is_list($parent[$key]) === false && $parent[$key] !== [])) self::fail($lineNumber, "array table conflicts with an existing value");
                    $parent[$key][] = [];
                    $tablePath = $path;
                    $tablePath[] = array_key_last($parent[$key]);
                }else{
                    self::tableAt($data, $path, $lineNumber);
                    $tablePath = $path;
                }
                continue;
            }
            $equals = self::findEquals($line);
            if($equals === null) self::fail($lineNumber, "expected key = value");
            $keyPath = self::parseKeyPath(trim(substr($line, 0, $equals)), $lineNumber);
            if(count($keyPath) !== 1) self::fail($lineNumber, "dotted assignment keys are not supported; use a table header");
            $key = $keyPath[0];
            $table =& self::tableAt($data, $tablePath, $lineNumber);
            if(array_key_exists($key, $table)) self::fail($lineNumber, "duplicate key '{$key}'");
            $table[$key] = self::parseValue(trim(substr($line, $equals + 1)), $lineNumber);
        }
        return $data;
    }

    private static function stripComment(string $line) : string{
        $quote = "";
        $escaped = false;
        for($i = 0, $n = strlen($line); $i < $n; ++$i){
            $c = $line[$i];
            if($quote === '"' && $c === "\\" && !$escaped){ $escaped = true; continue; }
            if(($c === '"' || $c === "'") && !$escaped){
                if($quote === "") $quote = $c;
                elseif($quote === $c) $quote = "";
            }
            if($c === "#" && $quote === "") return substr($line, 0, $i);
            $escaped = false;
        }
        return $line;
    }

    private static function findEquals(string $line) : ?int{
        $quote = ""; $depth = 0; $escaped = false;
        for($i = 0, $n = strlen($line); $i < $n; ++$i){
            $c = $line[$i];
            if($quote === '"' && $c === "\\" && !$escaped){ $escaped = true; continue; }
            if(($c === '"' || $c === "'") && !$escaped){ if($quote === "") $quote = $c; elseif($quote === $c) $quote = ""; }
            if($quote === ""){
                if($c === "[" || $c === "{") ++$depth;
                elseif($c === "]" || $c === "}") --$depth;
                elseif($c === "=" && $depth === 0) return $i;
            }
            $escaped = false;
        }
        return null;
    }

    /** @return list<string> */
    private static function parseKeyPath(string $text, int $line) : array{
        $parts = self::splitTopLevel($text, ".", $line);
        $out = [];
        foreach($parts as $part){
            $part = trim($part);
            if(strlen($part) >= 2 && (($part[0] === '"' && str_ends_with($part, '"')) || ($part[0] === "'" && str_ends_with($part, "'")))){
                $value = self::parseValue($part, $line);
                if(!is_string($value)) self::fail($line, "table key must be a string");
                $out[] = $value;
            }elseif(preg_match('/^[A-Za-z0-9_-]+$/D', $part) === 1){
                $out[] = $part;
            }else self::fail($line, "invalid key '{$part}'");
        }
        return $out;
    }

    private static function parseValue(string $value, int $line) : mixed{
        if($value === "true") return true;
        if($value === "false") return false;
        if(preg_match('/^[+-]?(?:0|[1-9](?:_?[0-9])*)$/D', $value) === 1) return (int)str_replace("_", "", $value);
        if(preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) === 1) return (float)$value;
        if(strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')){
            $decoded = json_decode($value, true);
            if(is_string($decoded)) return $decoded;
            self::fail($line, "invalid basic string");
        }
        if(strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) return substr($value, 1, -1);
        if(strlen($value) >= 2 && $value[0] === "[" && str_ends_with($value, "]")){
            $inside = trim(substr($value, 1, -1));
            if($inside === "") return [];
            return array_map(static fn(string $part) => self::parseValue(trim($part), $line), self::splitTopLevel($inside, ",", $line));
        }
        if(strlen($value) >= 2 && $value[0] === "{" && str_ends_with($value, "}")){
            $inside = trim(substr($value, 1, -1)); $result = [];
            if($inside === "") return $result;
            foreach(self::splitTopLevel($inside, ",", $line) as $pair){
                $equals = self::findEquals($pair);
                if($equals === null) self::fail($line, "invalid inline table entry");
                $keys = self::parseKeyPath(trim(substr($pair, 0, $equals)), $line);
                if(count($keys) !== 1) self::fail($line, "inline table keys cannot be dotted");
                if(array_key_exists($keys[0], $result)) self::fail($line, "duplicate inline table key");
                $result[$keys[0]] = self::parseValue(trim(substr($pair, $equals + 1)), $line);
            }
            return $result;
        }
        self::fail($line, "unsupported or invalid TOML value");
    }

    /** @return list<string> */
    private static function splitTopLevel(string $text, string $separator, int $line) : array{
        $parts = []; $start = 0; $quote = ""; $depth = 0; $escaped = false;
        for($i = 0, $n = strlen($text); $i < $n; ++$i){
            $c = $text[$i];
            if($quote === '"' && $c === "\\" && !$escaped){ $escaped = true; continue; }
            if(($c === '"' || $c === "'") && !$escaped){ if($quote === "") $quote = $c; elseif($quote === $c) $quote = ""; }
            if($quote === ""){
                if($c === "[" || $c === "{") ++$depth;
                elseif($c === "]" || $c === "}") --$depth;
                elseif($c === $separator && $depth === 0){ $parts[] = substr($text, $start, $i - $start); $start = $i + 1; }
            }
            $escaped = false;
        }
        if($quote !== "" || $depth !== 0) self::fail($line, "unbalanced string, array or table value");
        $parts[] = substr($text, $start);
        return $parts;
    }

    /** @param list<string|int> $path
     *  @return array<string|int, mixed>
     */
    private static function &tableAt(array &$root, array $path, int $line) : array{
        $cursor =& $root;
        foreach($path as $part){
            if(!isset($cursor[$part])) $cursor[$part] = [];
            if(!is_array($cursor[$part])) self::fail($line, "table conflicts with an existing value");
            $cursor =& $cursor[$part];
        }
        return $cursor;
    }

    private static function fail(int $line, string $reason) : never{
        throw new PluginException("plugin.toml line {$line}: {$reason}");
    }
}
