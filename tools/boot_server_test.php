<?php

declare(strict_types=1);

/**
 * End-to-end test: builds the real raspberry\Server in a sandbox data path with the
 * ExampleAddonBP behavior pack and verifies its content lands in every registry.
 * The network is never started; only the constructor pipeline runs.
 * Run: php /home/z/my-project/scripts/boot_server_test.php
 */

error_reporting(E_ALL);

const PROJECT = dirname(__DIR__);

require PROJECT . "/vendor/autoload.php";

use raspberry\Server;

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

$dataPath = sys_get_temp_dir() . "/todiumx_e2e_" . getmypid();
exec("rm -rf " . escapeshellarg($dataPath));
mkdir($dataPath . "/behavior_packs", 0777, true);
exec("cp -r " . escapeshellarg(PROJECT . "/behavior_packs/ExampleAddonBP") . " " . escapeshellarg($dataPath . "/behavior_packs/ExampleAddonBP"));
file_put_contents($dataPath . "/server.properties", "language=en_US\nserver-name=E2E Test\n");

echo "== booting raspberry\\Server in {$dataPath} ==\n";
$server = new Server($dataPath);

echo "== behavior pack manager ==\n";
$manager = $server->getBehaviorPackManager();
check($manager->getActiveCount() === 1, "ExampleAddonBP is the single active pack");
$pack = $manager->find("todium:ruby") ?? $manager->find("ExampleAddonBP");
check($pack !== null && $pack->getName() === "Example Add-On (Behavior)", "pack findable by folder name");
check($manager->getFunctionIds() === ["welcome"], "function ids from active pack");
check(!$manager->hasScriptPacks(), "no script packs");

echo "== items ==\n";
$dictionary = $server->getItemTypeDictionary();
check($dictionary->hasItem("todium:ruby"), "todium:ruby registered in the item type dictionary");
$entryNames = array_column($dictionary->getRegistryEntries(), 0);
check(in_array("todium:ruby", $entryNames, true), "todium:ruby present in ItemRegistryPacket payload");
check($server->getPluginContentRegistry()->hasItem("todium:ruby"), "item definition stored in the content registry");

echo "== blocks ==\n";
check($server->getPluginCustomBlockFallback("todium:ruby_block") === "minecraft:stone", "todium:ruby_block registered with stone fallback");

echo "== entities ==\n";
$entity = $server->getPluginContentRegistry()->getEntity("todium:ruby_golem");
check($entity !== null && ($entity["max_health"] ?? null) === 40.0, "todium:ruby_golem registered with 40 health");
check(($entity["pack_definition"]["description"]["identifier"] ?? null) === "todium:ruby_golem", "pack entity definition preserved");
//spawnCustomEntity needs a loaded world, which the constructor pipeline does not create; the registry check above covers the pack side

echo "== recipes ==\n";
$crafting = $server->getCraftingManager();
check($crafting->getCraftingRecipeById("todium:ruby_block_from_gems") !== null, "shaped pack recipe registered");
check(in_array("todium:ruby_from_redstone", array_map(static fn($r) => $r->getId(), $crafting->getFurnaceRecipes()), true), "furnace pack recipe registered");
check($crafting->getFurnaceRecipe(new raspberry\item\Item("minecraft:redstone"), "furnace") !== null, "furnace lookup finds the pack recipe");
check(in_array("todium:ruby", $crafting->getItemTags()["todium:is_gem"] ?? [], true), "custom item tag applied");

echo "== functions ==\n";
check($server->getFunctionRunner()->has("welcome"), "welcome function registered in the runner");

echo "== creative content ==\n";
$creative = $server->getCreativeContent();
$groups = array_column($creative->groups ?? [], 1);
check(in_array("Behavior Packs", $groups, true), "\"Behavior Packs\" creative group exists");
$rubyRuntimeId = $dictionary->getRuntimeId("todium:ruby");
$creativeIds = [];
foreach(($creative->items ?? []) as $entry){
    $creativeIds[] = $entry[0]->networkId ?? 0;
}
check($rubyRuntimeId !== null && in_array($rubyRuntimeId, $creativeIds, true), "todium:ruby added to the creative content");

echo "\n";
if($failures === 0){
    echo "ALL {$tests} BOOT CHECKS PASSED\n";
    exit(0);
}
echo "{$failures} of {$tests} BOOT CHECKS FAILED\n";
exit(1);
