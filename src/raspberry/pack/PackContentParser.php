<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\crafting\CraftingManager;
use raspberry\crafting\RecipeIngredient;
use raspberry\crafting\RecipeUnlock;
use raspberry\item\Item;

use function array_intersect;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function preg_match;
use function str_contains;
use function str_split;
use function strtolower;
use function str_starts_with;
use function trim;

/**
 * Turns behavior pack content JSON into parts the server's registries can build:
 * recipes into recipe part arrays the CraftingManager turns into its recipe classes,
 * entity/item/block JSON into definitions the registries accept. Throws PackException
 * with a content-log style message when a file cannot be used.
 */
final class PackContentParser{

    /** the vanilla crafting surfaces TodiumX knows */
    private const SHAPELESS_BLOCKS = ["crafting_table", "cartography_table", "stonecutter"];

    /** the vanilla smelting surfaces a furnace recipe may run in */
    private const FURNACE_BLOCKS = ["furnace", "blast_furnace", "smoker", "campfire", "soul_campfire"];

    /**
     * Parses one recipes/*.json file into recipe parts the CraftingManager can build.
     * Every result has a "kind" key: "shaped", "shapeless", "furnace" or "smithing".
     *
     * @return list<array<string, mixed>>
     *
     * @throws PackException
     */
    public static function recipes(array $data, string $fileName, CraftingManager $manager) : array{
        $results = [];
        foreach($data as $key => $recipe){
            if(!is_string($key)){
                continue;
            }
            $kind = match($key){
                "minecraft:recipe_shaped" => "shaped",
                "minecraft:recipe_shapeless" => "shapeless",
                "minecraft:recipe_furnace" => "furnace",
                "minecraft:recipe_smithing_transform", "minecraft:recipe_smithing_trim" => "smithing",
                default => null
            };
            if($kind === null){
                continue; //brewing mix/container and unknown types: skipped, like the vanilla server skips unsupported files
            }
            if(!is_array($recipe)){
                throw new PackException("{$fileName}: {$key} must be an object");
            }

            $description = is_array($recipe["description"] ?? null) ? $recipe["description"] : [];
            $identifier = is_string($description["identifier"] ?? null) ? trim($description["identifier"]) : "";
            if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/Di', $identifier) !== 1){
                throw new PackException("{$fileName}: {$key} has no usable description.identifier");
            }
            $identifier = strtolower($identifier);

            $tags = [];
            foreach((is_array($recipe["tags"] ?? null) ? $recipe["tags"] : []) as $tag){
                if(is_string($tag)){
                    $tags[] = strtolower($tag);
                }
            }
            $priority = is_int($recipe["priority"] ?? null) ? $recipe["priority"] : 0;

            $parts = match($kind){
                "shaped" => self::shapedParts($identifier, $priority, $recipe, $fileName, $manager),
                "shapeless" => self::shapelessParts($identifier, $tags, $priority, $recipe, $fileName, $manager),
                "furnace" => self::furnaceParts($identifier, $tags, $priority, $recipe, $fileName, $manager),
                default => self::smithingParts($identifier, $recipe, $fileName, $manager)
            };
            $results[] = ["kind" => $kind, ...$parts];
        }
        return $results;
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * @return array<string, mixed> shaped parts: id, block, priority, width, height, input, output, unlock
     *
     * @throws PackException
     */
    private static function shapedParts(string $id, int $priority, array $recipe, string $fileName, CraftingManager $manager) : array{
        $pattern = $recipe["pattern"] ?? null;
        $key = $recipe["key"] ?? null;

        if(!is_array($pattern) || $pattern === [] || !is_array($key)){
            throw new PackException("{$fileName}: {$id} needs a pattern and a key");
        }

        $width = 0;
        $rows = [];
        foreach($pattern as $row){
            if(!is_string($row)){
                throw new PackException("{$fileName}: {$id} pattern rows must be strings");
            }
            $width = max($width, strlen($row));
            $rows[] = $row;
        }
        if($width < 1 || count($rows) > 3 || $width > 3){
            throw new PackException("{$fileName}: {$id} pattern must be 1-3 rows of 1-3 cells");
        }

        $input = [];
        foreach($rows as $row){
            $cells = str_split($row);
            foreach($cells as $char){
                $input[] = $char === " " ? null : self::keyIngredient((string) $char, $key, $id, $fileName, $manager);
            }
            for($padding = count($cells); $padding < $width; $padding++){
                $input[] = null;
            }
        }

        $output = self::resultItems($recipe["result"] ?? null, $id, $fileName);

        return [
            "id" => $id,
            "block" => "crafting_table",
            "priority" => $priority,
            "width" => $width,
            "height" => count($rows),
            "input" => $input,
            "output" => $output,
            "unlock" => self::unlock($recipe["unlock"] ?? null, $fileName, $manager)
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     * @param list<string> $tags
     *
     * @return array<string, mixed> shapeless parts: id, block, priority, input, output, unlock
     *
     * @throws PackException
     */
    private static function shapelessParts(string $id, array $tags, int $priority, array $recipe, string $fileName, CraftingManager $manager) : array{
        $rawIngredients = $recipe["ingredients"] ?? null;
        if(!is_array($rawIngredients) || $rawIngredients === []){
            throw new PackException("{$fileName}: {$id} needs ingredients");
        }

        $input = [];
        foreach($rawIngredients as $entry){
            $ingredient = self::ingredientEntry($entry, $id, $fileName, $manager);
            if($ingredient === null){
                throw new PackException("{$fileName}: {$id} has an unusable ingredient");
            }
            $input[] = $ingredient;
        }

        $block = "crafting_table";
        foreach(self::SHAPELESS_BLOCKS as $surface){
            if(in_array($surface, $tags, true)){
                $block = $surface;
                break;
            }
        }

        $output = self::resultItems($recipe["result"] ?? null, $id, $fileName);

        return [
            "id" => $id,
            "block" => $block,
            "priority" => $priority,
            "input" => $input,
            "output" => $output,
            "unlock" => self::unlock($recipe["unlock"] ?? null, $fileName, $manager)
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     * @param list<string> $tags
     *
     * @return array<string, mixed> furnace parts: id, blocks, priority, input, output
     *
     * @throws PackException
     */
    private static function furnaceParts(string $id, array $tags, int $priority, array $recipe, string $fileName, CraftingManager $manager) : array{
        $ingredient = self::ingredientEntry($recipe["input"] ?? null, $id, $fileName, $manager);
        if($ingredient === null){
            throw new PackException("{$fileName}: {$id} has no usable input");
        }
        $output = self::resultItems($recipe["output"] ?? null, $id, $fileName);

        $blocks = array_values(array_intersect(self::FURNACE_BLOCKS, $tags));
        if($blocks === []){
            $blocks = ["furnace"];
        }

        return [
            "id" => $id,
            "blocks" => $blocks,
            "priority" => $priority,
            "input" => $ingredient,
            "output" => $output[0]
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * @return array<string, mixed> smithing parts: id, template, base, addition, output
     *
     * @throws PackException
     */
    private static function smithingParts(string $id, array $recipe, string $fileName, CraftingManager $manager) : array{
        $template = self::ingredientEntry($recipe["template"] ?? null, $id, $fileName, $manager);
        $base = self::ingredientEntry($recipe["base"] ?? null, $id, $fileName, $manager);
        $addition = self::ingredientEntry($recipe["addition"] ?? null, $id, $fileName, $manager);
        if($template === null || $base === null || $addition === null){
            throw new PackException("{$fileName}: {$id} needs template, base and addition");
        }
        $output = self::resultItems($recipe["result"] ?? null, $id, $fileName);

        return [
            "id" => $id,
            "template" => $template,
            "base" => $base,
            "addition" => $addition,
            "output" => $output[0]
        ];
    }

    /**
     * One cell of a shaped recipe's key: "#" => {"item": ...} / {"tag": ...} / "minecraft:x".
     *
     * @param array<string, mixed> $key
     *
     * @throws PackException
     */
    private static function keyIngredient(string $char, array $key, string $id, string $fileName, CraftingManager $manager) : RecipeIngredient{
        $ingredient = self::ingredientEntry($key[$char] ?? null, $id, $fileName, $manager);
        if($ingredient === null){
            throw new PackException("{$fileName}: {$id} key \"{$char}\" has no usable ingredient");
        }
        return $ingredient;
    }

    /**
     * @param mixed $entry one ingredient in any accepted spelling
     *
     * @throws PackException
     */
    private static function ingredientEntry(mixed $entry, string $id, string $fileName, CraftingManager $manager) : ?RecipeIngredient{
        $item = null;
        $tag = null;
        $count = 1;
        $meta = RecipeIngredient::ANY_META;

        if(is_string($entry)){
            $item = $entry;
        }elseif(is_array($entry)){
            $item = is_string($entry["item"] ?? null) ? trim($entry["item"]) : null;
            $tag = is_string($entry["tag"] ?? null) ? strtolower(trim($entry["tag"])) : null;
            $count = is_int($entry["count"] ?? null) && $entry["count"] > 0 ? $entry["count"] : 1;
            foreach(["data", "aux_value", "meta"] as $metaField){
                if(is_int($entry[$metaField] ?? null)){
                    $meta = $entry[$metaField];
                    break;
                }
            }
        }

        if(is_string($item) && $item !== ""){
            return new RecipeIngredient(self::itemId($item), $meta, null, [], $count);
        }

        if(is_string($tag) && $tag !== ""){
            if(!isset($manager->getItemTags()[$tag])){
                throw new PackException("{$fileName}: {$id} uses unknown item tag {$tag}");
            }
            return new RecipeIngredient(null, RecipeIngredient::ANY_META, $tag, $manager->getItemTags()[$tag], $count);
        }

        return null;
    }

    /**
     * The result item(s): {"item": "x", "count": n}, "x", or a list of those.
     *
     * @return list<Item>
     *
     * @throws PackException
     */
    private static function resultItems(mixed $result, string $id, string $fileName) : array{
        $entries = is_array($result) && isset($result[0]) && (is_array($result[0]) || is_string($result[0])) ? $result : [$result];

        $items = [];
        foreach($entries as $entry){
            $item = null;
            $count = 1;
            $meta = 0;
            if(is_string($entry)){
                $item = $entry;
            }elseif(is_array($entry)){
                $item = is_string($entry["item"] ?? null) ? trim($entry["item"]) : null;
                $count = is_int($entry["count"] ?? null) && $entry["count"] > 0 ? $entry["count"] : 1;
                $meta = is_int($entry["data"] ?? null) ? $entry["data"] : 0;
            }
            if(is_string($item) && $item !== ""){
                $items[] = new Item(self::itemId($item), $count, $meta);
            }
        }

        if($items === []){
            throw new PackException("{$fileName}: {$id} result must name an item");
        }
        return $items;
    }

    /**
     * @param mixed $unlock the Bedrock "unlock" list: [{"item": "..."}]
     *
     * @throws PackException
     */
    private static function unlock(mixed $unlock, string $fileName, CraftingManager $manager) : ?RecipeUnlock{
        if(!is_array($unlock)){
            return null;
        }
        $items = [];
        foreach($unlock as $entry){
            $ingredient = self::ingredientEntry($entry, "unlock", $fileName, $manager);
            if($ingredient === null){
                return null; //unlocking is cosmetic; a bad entry drops the whole unlock, not the recipe
            }
            $items[] = $ingredient;
        }
        if($items === []){
            return null;
        }
        return new RecipeUnlock(RecipeUnlock::CONTEXT_NONE, $items);
    }

    /**
     * Entity JSON => [identifier, CustomEntity definition]. Only entities the player may summon
     * are accepted, matching the official /summon rules.
     *
     * @return array{string, array<string, mixed>}
     *
     * @throws PackException
     */
    public static function entityDefinition(array $data, string $fileName) : array{
        $entity = is_array($data["minecraft:entity"] ?? null) ? $data["minecraft:entity"] : null;
        if($entity === null){
            throw new PackException("{$fileName}: no minecraft:entity object");
        }
        $description = is_array($entity["description"] ?? null) ? $entity["description"] : [];
        $identifier = is_string($description["identifier"] ?? null) ? strtolower(trim($description["identifier"])) : "";

        if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_\/.-]+$/', $identifier) !== 1){
            throw new PackException("{$fileName}: description.identifier is missing or not namespace:name");
        }

        if(($description["is_summonable"] ?? false) !== true){
            throw new PackException("{$fileName}: {$identifier} is not summonable, skipped");
        }

        $components = is_array($entity["components"] ?? null) ? $entity["components"] : [];

        $health = is_array($components["minecraft:health"] ?? null) ? $components["minecraft:health"] : [];
        $healthValue = $health["max"] ?? $health["value"] ?? null;
        if(!is_numeric($healthValue)){
            $healthValue = 10;
        }

        $movement = is_array($components["minecraft:movement"] ?? null) ? ($components["minecraft:movement"]["value"] ?? null) : null;
        $followRange = is_array($components["minecraft:follow_range"] ?? null) ? ($components["minecraft:follow_range"]["value"] ?? null) : null;
        $collision = is_array($components["minecraft:collision_box"] ?? null) ? $components["minecraft:collision_box"] : [];

        $definition = [
            "name" => $identifier,
            "max_health" => (float) $healthValue,
            "speed" => is_numeric($movement) ? (float) $movement : 0.08,
            "follow_range" => is_numeric($followRange) ? (float) $followRange : 12.0,
            "width" => is_numeric($collision["width"] ?? null) ? (float) $collision["width"] : 0.6,
            "height" => is_numeric($collision["height"] ?? null) ? (float) $collision["height"] : 1.8,
            "wander" => isset($components["minecraft:behavior.random_stroll"]),
            "follow_players" => isset($components["minecraft:behavior.nearest_attackable_target"]),
            "pack_definition" => $entity
        ];

        return [$identifier, $definition];
    }

    /**
     * Item JSON => [identifier, full JSON for the item registry, list<string> item tags].
     *
     * @return array{string, array<string, mixed>, list<string>}
     *
     * @throws PackException
     */
    public static function itemDefinition(array $data, string $fileName) : array{
        $item = is_array($data["minecraft:item"] ?? null) ? $data["minecraft:item"] : null;
        if($item === null){
            throw new PackException("{$fileName}: no minecraft:item object");
        }
        $description = is_array($item["description"] ?? null) ? $item["description"] : [];
        $identifier = is_string($description["identifier"] ?? null) ? strtolower(trim($description["identifier"])) : "";

        if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_\/.-]+$/', $identifier) !== 1){
            throw new PackException("{$fileName}: description.identifier is missing or not namespace:name");
        }

        $components = is_array($item["components"] ?? null) ? $item["components"] : [];
        $tags = [];
        $rawTags = is_array($components["minecraft:tags"] ?? null) ? ($components["minecraft:tags"]["tags"] ?? null) : null;
        if(is_array($rawTags)){
            foreach($rawTags as $tag){
                if(is_string($tag) && $tag !== ""){
                    $tags[] = strtolower($tag);
                }
            }
        }

        return [$identifier, $data, $tags];
    }

    /**
     * Block JSON => [identifier, full JSON for the block definition list].
     *
     * @return array{string, array<string, mixed>}
     *
     * @throws PackException
     */
    public static function blockDefinition(array $data, string $fileName) : array{
        $block = is_array($data["minecraft:block"] ?? null) ? $data["minecraft:block"] : null;
        if($block === null){
            throw new PackException("{$fileName}: no minecraft:block object");
        }
        $description = is_array($block["description"] ?? null) ? $block["description"] : [];
        $identifier = is_string($description["identifier"] ?? null) ? strtolower(trim($description["identifier"])) : "";

        if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_\/.-]+$/', $identifier) !== 1){
            throw new PackException("{$fileName}: description.identifier is missing or not namespace:name");
        }

        return [$identifier, $data];
    }

    /**
     * An .mcfunction file's command lines: comments and blank lines dropped.
     *
     * @return list<string>
     */
    public static function functionLines(string $contents) : array{
        $lines = [];
        foreach(preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line){
            $line = trim($line);
            if($line === "" || str_starts_with($line, "#")){
                continue;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Normalizes an item reference to the server's "minecraft:name" form.
     */
    private static function itemId(string $raw) : string{
        $raw = strtolower(trim($raw));
        if(!str_contains($raw, ":")){
            $raw = "minecraft:" . $raw;
        }
        return $raw;
    }
}
