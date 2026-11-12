<?php

declare(strict_types=1);

/**
 * Behavior pack system test suite for TodiumX.
 * Run: php tools/test_behavior_packs.php
 * Uses the real PackManifest, BehaviorPack, BehaviorPackManager, PackContentParser,
 * FunctionRunner, CraftingManager and ItemTypeDictionary classes; a minimal PSR-4
 * autoloader keeps the test independent from composer and the full server.
 */

error_reporting(E_ALL);

const PROJECT = __DIR__ . "/..";

spl_autoload_register(static function(string $class) : void{
    $prefix = "raspberry\\";
    if(str_starts_with($class, $prefix)){
        $path = PROJECT . "/src/raspberry/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
        if(is_file($path)){
            require $path;
        }
    }
});

use raspberry\crafting\CraftingManager;
use raspberry\item\Item;
use raspberry\lang\Language;
use raspberry\pack\BehaviorPack;
use raspberry\pack\BehaviorPackManager;
use raspberry\pack\PackContentParser;
use raspberry\pack\PackManifest;
use raspberry\pack\FunctionRunner;
use raspberry\utils\Logger;

$failures = 0;
$tests = 0;

function check(bool $condition, string $name) : void{
    global $failures, $tests;
    $tests++;
    if($condition){
        echo "  ok  {$name}\n";
    }else{
        $failures++;
        echo " FAIL {$name}\n";
    }
}

function expectException(callable $fn, string $name) : void{
    global $failures, $tests;
    $tests++;
    try{
        $fn();
        $failures++;
        echo " FAIL {$name} (no exception thrown)\n";
    }catch(Throwable){
        echo "  ok  {$name}\n";
    }
}

$logger = new Logger();

echo "== 1. PackManifest parses the official vanilla behavior pack ==\n";

$vanillaBp = "/home/z/my-project/work/bedrock-server/behavior_packs/vanilla/manifest.json";
if(is_file($vanillaBp)){
    $manifest = PackManifest::fromFileTyped($vanillaBp, PackManifest::TYPE_BEHAVIOR);
    check($manifest->isBehaviorPack(), "vanilla manifest detected as behavior pack");
    check($manifest->getModuleTypes() === ["data"], "vanilla module type is data");
    check($manifest->getUuid() === "fe9f8597-5454-481a-8730-8d070a8e2e58", "vanilla header.uuid parsed");
    check($manifest->getVersion() === "0.0.1", "vanilla version [0,0,1] parsed as 0.0.1");
    check($manifest->getVersionArray() === [0, 0, 1], "getVersionArray matches world_behavior_packs.json form");
    check(count($manifest->getDependencies()) >= 1, "vanilla dependencies parsed");
    check(isset($manifest->getDependencyVersions()[$manifest->getDependencies()[0]]), "dependency version recorded");
}else{
    check(false, "vanilla behavior pack manifest available for the test");
}

$vanillaRp = "/home/z/my-project/work/bedrock-server/resource_packs/vanilla/manifest.json";
if(is_file($vanillaRp)){
    $rp = PackManifest::fromFileTyped($vanillaRp, PackManifest::TYPE_RESOURCE);
    check($rp->isResourcePack() && !$rp->isBehaviorPack(), "resource pack detection still works");
}

expectException(static fn() => PackManifest::fromFileTyped(__FILE__, PackManifest::TYPE_BEHAVIOR), "a non-manifest PHP file is rejected");

echo "== 2. BehaviorPack folder packs: manifest + content index ==\n";

$example = PROJECT . "/behavior_packs/ExampleAddonBP";
$pack = BehaviorPack::fromPath($example, "ExampleAddonBP");
check($pack->getUuid() === "4b8f1c2d-9a3e-4d7a-b1c6-2f5e8d0a9b31", "example pack uuid");
check($pack->getName() === "Example Add-On (Behavior)", "example pack name");
check($pack->getVersion() === "1.0.0", "example pack version");
check($pack->getContentFiles("items") === ["items/ruby.json"], "items indexed");
check($pack->getContentFiles("blocks") === ["blocks/ruby_block.json"], "blocks indexed");
check(count($pack->getContentFiles("recipes")) === 2, "recipes indexed");
check($pack->getContentFiles("functions") === ["functions/welcome.mcfunction"], "functions indexed");
check($pack->getContentFiles("entities") === ["entities/ruby_golem.json"], "entities indexed");
check($pack->getContentCount() === 6, "content count = 6 files");
check(str_contains($pack->readContentFile("functions/welcome.mcfunction"), "give @s todium:ruby 5"), "content file readable from folder pack");
check(!$pack->isArchive() && !$pack->hasScripts(), "folder pack, no scripts");

echo "== 3. BehaviorPack archive packs (.mcpack/.zip, flat and wrapped) ==\n";

$tmp = sys_get_temp_dir() . "/todiumx_bp_test_" . getmypid();
if(is_dir($tmp)){
    exec("rm -rf " . escapeshellarg($tmp));
}
mkdir($tmp, 0777, true);

$buildArchive = static function(string $target, ?string $prefix) use ($example) : bool{
    $zip = new ZipArchive();
    if($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true){
        return false;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($example, FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){
        if(!$file->isFile()){
            continue;
        }
        $relative = substr($file->getPathname(), strlen($example) + 1);
        $zip->addFile($file->getPathname(), ($prefix ?? "") . $relative);
    }
    return $zip->close();
};
check($buildArchive($tmp . "/wrapped.mcpack", "ExampleAddonBP/"), "wrapped .mcpack built");
check($buildArchive($tmp . "/flat.mcpack", null), "flat .mcpack built");

$wrapped = BehaviorPack::fromPath($tmp . "/wrapped.mcpack", "wrapped.mcpack");
check($wrapped->getUuid() === $pack->getUuid(), "wrapped .mcpack manifest read");
check($wrapped->getContentFiles("items") === ["items/ruby.json"], "wrapped .mcpack content indexed through root folder");
check(str_contains($wrapped->readContentFile("items/ruby.json"), "todium:ruby"), "wrapped .mcpack file read");

$flat = BehaviorPack::fromPath($tmp . "/flat.mcpack", "flat.mcpack");
check($flat->getContentFiles("recipes") === ["recipes/ruby_block.json", "recipes/ruby_from_redstone.json"], "flat .mcpack content indexed");
check(str_contains($flat->readContentFile("recipes/ruby_block.json"), "ruby_block_from_gems"), "flat .mcpack file read");

file_put_contents($tmp . "/bad.mcpack", "not a zip at all");
expectException(static fn() => BehaviorPack::fromPath($tmp . "/bad.mcpack", "bad.mcpack"), "corrupt archive rejected");

echo "== 4. BehaviorPackManager: scan, auto-enable, world stacks, enable/disable ==\n";

$root = $tmp . "/server";
mkdir($root . "/behavior_packs", 0777, true);
mkdir($root . "/worlds/DemoWorld", 0777, true);
exec("cp -r " . escapeshellarg($example) . " " . escapeshellarg($root . "/behavior_packs/ExampleAddonBP"));

$uuid = "4b8f1c2d-9a3e-4d7a-b1c6-2f5e8d0a9b31";
file_put_contents($root . "/worlds/DemoWorld/world_behavior_packs.json", json_encode([
    ["pack_id" => $uuid, "version" => [1, 0, 0]],
    ["pack_id" => "00000000-0000-0000-0000-000000000000", "version" => [1, 0, 0]]
]));
$language = new Language("en_US");
$manager = new BehaviorPackManager($root . "/behavior_packs", $root . "/behavior_packs.json", $language, $logger, $root . "/worlds");
$manager->load();

check(count($manager->getPacks()) === 1, "manager scanned one pack");
$foundPack = $manager->find($uuid);
check($foundPack !== null, "find() by uuid");
check($manager->find("exampleaddonbp") !== null && $manager->find("Example Add-On (Behavior)") !== null, "find() by folder and manifest name");
check($manager->getFolder() === $root . "/behavior_packs", "getFolder()");
check($manager->isEnabled($foundPack) && $manager->isActive($foundPack), "new pack auto-enabled and active");
check(is_file($root . "/behavior_packs.json"), "state file created");
$state = json_decode(file_get_contents($root . "/behavior_packs.json"), true, 8);
check(is_array($state) && in_array($uuid, $state["enabled"] ?? [], true), "state file records the enabled pack");

check($manager->find("00000000-0000-0000-0000-000000000000") === null, "world stack entry for a missing pack uuid is skipped");
check($manager->getActiveCount() === 1, "load() ended with one active pack");

$changed = $manager->disable($foundPack);
check(count($changed) === 1 && !$manager->isEnabled($foundPack) && !$manager->isActive($foundPack), "disable() removes the pack");
$enabledAgain = $manager->enable($foundPack);
check(count($enabledAgain) === 1 && $manager->isActive($foundPack), "enable() re-adds the pack");
$manager->disable($foundPack);
$manager->load();
check($manager->isActive($foundPack), "world_behavior_packs.json force-re-enables the pack on reload (official semantics)");
check($manager->getFunctionIds() === ["welcome"], "getFunctionIds() lists pack functions");

echo "== 5. PackContentParser: recipes, entities, items, blocks ==\n";

$crafting = new CraftingManager();
$beforeRecipes = count($crafting->getCraftingRecipes());
$beforeFurnace = count($crafting->getFurnaceRecipes());

$shapedData = json_decode($pack->readContentFile("recipes/ruby_block.json"), true, 64);
$parsed = PackContentParser::recipes($shapedData, "recipes/ruby_block.json", $crafting);
check(count($parsed) === 1 && $parsed[0]["kind"] === "shaped", "shaped recipe parsed");
check($parsed[0]["id"] === "todium:ruby_block_from_gems", "recipe identifier used as id");
check($parsed[0]["width"] === 2 && $parsed[0]["height"] === 2 && count($parsed[0]["input"]) === 4, "pattern 2x2 with 4 cells");
check($parsed[0]["input"][0] !== null && $parsed[0]["input"][0]->getItemId() === "todium:ruby", "key X resolves to todium:ruby");
check($parsed[0]["output"][0]->getId() === "todium:ruby_block", "result item");
check($parsed[0]["unlock"] !== null, "unlock list parsed");

check($crafting->registerPackShaped($parsed[0]["id"], $parsed[0]["block"], $parsed[0]["priority"], $parsed[0]["width"], $parsed[0]["height"], $parsed[0]["input"], $parsed[0]["output"], $parsed[0]["unlock"]), "shaped recipe registered");
check(!$crafting->registerPackShaped($parsed[0]["id"], $parsed[0]["block"], 0, 2, 2, $parsed[0]["input"], $parsed[0]["output"], null), "duplicate recipe id rejected");
check(count($crafting->getCraftingRecipes()) === $beforeRecipes + 1, "crafting registry grew by one");

$furnaceData = json_decode($pack->readContentFile("recipes/ruby_from_redstone.json"), true, 64);
$parsedFurnace = PackContentParser::recipes($furnaceData, "recipes/ruby_from_redstone.json", $crafting);
check(count($parsedFurnace) === 1 && $parsedFurnace[0]["kind"] === "furnace", "furnace recipe parsed");
check($parsedFurnace[0]["blocks"] === ["furnace", "blast_furnace"], "furnace tags mapped to blocks");
check($crafting->registerPackFurnace($parsedFurnace[0]["id"], $parsedFurnace[0]["blocks"], $parsedFurnace[0]["priority"], $parsedFurnace[0]["input"], $parsedFurnace[0]["output"]), "furnace recipe registered");
$found = $crafting->getFurnaceRecipe(new Item("minecraft:redstone"), "furnace");
check($found !== null && $found->getOutput()->getId() === "todium:ruby", "smelting redstone now yields todium:ruby");

$itemData = json_decode($pack->readContentFile("items/ruby.json"), true, 64);
[$itemId, $itemJson, $itemTags] = PackContentParser::itemDefinition($itemData, "items/ruby.json");
check($itemId === "todium:ruby" && isset($itemJson["minecraft:item"]), "item definition parsed");
check($itemTags === ["todium:is_gem"], "item tags parsed");
$crafting->addItemTag("todium:is_gem", [$itemId]);
check(isset($crafting->getItemTags()["todium:is_gem"]) && in_array("todium:ruby", $crafting->getItemTags()["todium:is_gem"], true), "custom tag registered in crafting manager");

$tagged = PackContentParser::recipes([
    "minecraft:recipe_shapeless" => [
        "description" => ["identifier" => "todium:gem_swap"],
        "tags" => ["crafting_table"],
        "ingredients" => [["tag" => "todium:is_gem", "count" => 2]],
        "result" => ["item" => "minecraft:diamond", "count" => 1]
    ]
], "inline.json", $crafting);
check(count($tagged) === 1 && $tagged[0]["kind"] === "shapeless" && $tagged[0]["input"][0]->getTag() === "todium:is_gem", "shapeless recipe with custom tag ingredient");
expectException(static fn() => PackContentParser::recipes([
    "minecraft:recipe_shapeless" => [
        "description" => ["identifier" => "todium:bad_tag"],
        "ingredients" => [["tag" => "todium:unknown_tag"]],
        "result" => "minecraft:diamond"
    ]
], "inline.json", $crafting), "unknown item tag rejected with content-log error");

$entityData = json_decode($pack->readContentFile("entities/ruby_golem.json"), true, 64);
[$entityId, $entityDef] = PackContentParser::entityDefinition($entityData, "entities/ruby_golem.json");
check($entityId === "todium:ruby_golem", "entity identifier");
check($entityDef["max_health"] === 40.0 && $entityDef["wander"] === true && $entityDef["follow_players"] === false, "entity components mapped to CustomEntity definition");
check(isset($entityDef["pack_definition"]["components"]) && isset($entityDef["pack_definition"]["description"]), "original definition preserved for plugins");

$blockData = json_decode($pack->readContentFile("blocks/ruby_block.json"), true, 64);
[$blockId, $blockJson] = PackContentParser::blockDefinition($blockData, "blocks/ruby_block.json");
check($blockId === "todium:ruby_block", "block identifier");

$lines = PackContentParser::functionLines($pack->readContentFile("functions/welcome.mcfunction"));
check($lines === ["say O Example Add-On (Behavior) esta ativo!", "give @s todium:ruby 5", "tell @s Veja os outros exemplos com /behaviorpack list"], "mcfunction lines: comments and blanks dropped");

echo "== 6. FunctionRunner registry ==\n";

$runner = new FunctionRunner(new Language("en_US"), $logger);
$runner->addFunction("welcome", $lines);
$runner->addFunction("buildings/house", ["say house"]);
check($runner->has("WELCOME"), "function lookup is case-insensitive");
check($runner->getFunctionIds() === ["welcome", "buildings/house"], "function ids listed");

echo "== 7. Item registry accepts pack items ==\n";

$dictionary = new raspberry\network\mcpe\convert\ItemTypeDictionary();
check(!$dictionary->hasItem("todium:ruby"), "pack item not yet in dictionary");
$newId = $dictionary->registerCustomItem("todium:ruby", true, 1, null);
check($dictionary->hasItem("todium:ruby") && $dictionary->getRuntimeId("todium:ruby") === $newId, "registerCustomItem assigns a runtime id");
$entryNames = array_column($dictionary->getRegistryEntries(), 0);
check(in_array("todium:ruby", $entryNames, true), "pack item present in ItemRegistryPacket entries");

echo "== 8. Language keys exist for the new commands ==\n";

$lang = new Language("en_US");
check(str_contains($lang->translate("command.behaviorpack.description"), "behavior"), "command.behaviorpack.description");
check(str_contains($lang->translate("command.function.done", ["function" => "welcome", "count" => 2]), "welcome"), "command.function.done placeholders");
check(str_contains($lang->translate("behavior.loaded", ["found" => 1, "active" => 1]), "1"), "behavior.loaded placeholders");
foreach(["de_DE", "es_ES", "fr_FR"] as $code){
    $other = new Language($code);
    check($other->translate("command.behaviorpack.header") !== "command.behaviorpack.header", "{$code} has the behavior pack keys");
}

echo "\n";
if($failures === 0){
    echo "ALL {$tests} CHECKS PASSED\n";
    exit(0);
}
echo "{$failures} of {$tests} CHECKS FAILED\n";
exit(1);
