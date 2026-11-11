<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\Server;
use raspberry\player\Player;
use raspberry\entity\CustomEntity;
use raspberry\entity\damage\DamageSource;
use raspberry\math\Vector3;
use raspberry\command\ConsoleCommandSender;
use raspberry\utils\Logger;

use Throwable;

use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function method_exists;
use function sprintf;
use function strtolower;

/**
 * Executes host calls from behavior pack scripts against the real server state.
 * This is the server-side half of the @minecraft/server bridge.
 */
class ScriptBridge{

    public function __construct(
        private Server $server,
        private Logger $logger
    ){}

    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    public function handle(string $method, array $params) : mixed{
        try{
            return $this->dispatch($method, $params);
        }catch(Throwable $e){
            $this->logger->debug("[ScriptBridge] {$method} failed: " . $e->getMessage());
            return ["__error" => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    private function dispatch(string $method, array $params) : mixed{
        return match($method){
            "world.getAllPlayers", "world.getPlayers" => $this->getAllPlayers($params),
            "world.getPlayer" => $this->getPlayer($params),
            "world.sendMessage" => $this->broadcastMessage($params),
            "world.runCommand", "world.runCommandAsync", "dimension.runCommand", "dimension.runCommandAsync" => $this->runCommand($params),
            "world.getDimension" => $this->getDimension($params),
            "world.getEntity" => $this->getEntity($params),
            "world.getDynamicProperty", "world.getDynamicPropertyIds" => $this->worldDynamicProperty($method, $params),
            "world.setDynamicProperty" => $this->setWorldDynamicProperty($params),
            "entity.getComponent", "entity.hasComponent" => $this->entityComponent($method, $params),
            "entity.teleport" => $this->teleportEntity($params),
            "entity.applyDamage" => $this->applyEntityDamage($params),
            "entity.setHealth" => $this->setEntityHealth($params),
            "entity.setNameTag" => $this->setEntityNameTag($params),
            "entity.remove" => $this->removeEntity($params),
            "entity.sendMessage" => $this->entitySendMessage($params),
            "player.sendMessage" => $this->playerSendMessage($params),
            "player.runCommand", "player.runCommandAsync" => $this->playerRunCommand($params),
            "player.getComponent" => $this->entityComponent($method, $params),
            "system.getCurrentTick" => $this->server->getCurrentTick(),
            "server.getInfo" => [
                "name" => $this->server->getServerName(),
                "motd" => $this->server->getMotd(),
                "maxPlayers" => $this->server->getMaxPlayers(),
                "onlinePlayers" => count($this->server->getInGamePlayers()),
                "tps" => method_exists($this->server, "getTicksPerSecond") ? $this->server->getTicksPerSecond() : 20.0,
            ],
            "block.getPermutation", "block.setPermutation", "block.setType" => $this->blockOperation($method, $params),
            "dimension.getBlock", "dimension.setBlock", "dimension.getEntities", "dimension.spawnEntity", "dimension.spawnItem" => $this->dimensionOperation($method, $params),
            default => ["__unsupported" => $method],
        };
    }

    /** @param array<string,mixed> $params */
    private function getAllPlayers(array $params) : array{
        $out = [];
        foreach($this->server->getInGamePlayers() as $p){
            $out[] = $this->serializePlayer($p);
        }
        return $out;
    }

    private function serializePlayer(Player $p) : array{
        $pos = $p->getPosition();
        $world = $p->getWorld();
        return [
            "__type" => "Player",
            "id" => $p->getId(),
            "name" => $p->getName(),
            "nameTag" => method_exists($p, "getNameTag") ? $p->getNameTag() : $p->getName(),
            "xuid" => method_exists($p, "getXuid") ? $p->getXuid() : "",
            "uuid" => method_exists($p, "getUniqueId") ? (string)$p->getUniqueId() : "",
            "location" => ["x" => $pos->x, "y" => $pos->y, "z" => $pos->z],
            "dimension" => ["id" => "minecraft:overworld", "name" => $world !== null ? $world->getName() : "world"],
            "isValid" => !$p->isClosed(),
            "gamemode" => method_exists($p, "getGamemode") ? strtolower($p->getGamemode()->name ?? "survival") : "survival",
        ];
    }

    /** @param array<string,mixed> $params */
    private function getPlayer(array $params) : ?array{
        $name = $params["name"] ?? null;
        $id = $params["id"] ?? null;
        foreach($this->server->getInGamePlayers() as $p){
            if(is_string($name) && strtolower($p->getName()) === strtolower($name)) return $this->serializePlayer($p);
            if((is_int($id) || is_numeric($id)) && $p->getId() === (int)$id) return $this->serializePlayer($p);
        }
        return null;
    }

    /** @param array<string,mixed> $params */
    private function broadcastMessage(array $params) : bool{
        $msg = $params["message"] ?? "";
        if(is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $this->server->broadcastMessage((string)$msg);
        return true;
    }

    /** @param array<string,mixed> $params */
    private function runCommand(array $params) : array{
        $command = (string)($params["command"] ?? $params["commandString"] ?? "");
        $command = ltrim($command, "/");
        if($command === "") return ["successCount" => 0, "statusCode" => 0];
        $senderName = $params["senderName"] ?? null;
        $sender = null;
        if(is_string($senderName) && $senderName !== ""){
            foreach($this->server->getInGamePlayers() as $p){
                if(strtolower($p->getName()) === strtolower($senderName)){ $sender = $p; break; }
            }
        }
        if($sender === null){
            // console fallback
            if(method_exists($this->server, "getConsoleSender")){
                $sender = $this->server->getConsoleSender();
            }
        }
        try{
            if($sender !== null){
                $this->server->dispatchCommand($sender, $command);
                return ["successCount" => 1, "statusCode" => 0];
            }
        }catch(Throwable $e){
            return ["successCount" => 0, "statusCode" => 1, "statusMessage" => $e->getMessage()];
        }
        return ["successCount" => 0, "statusCode" => 1];
    }

    /** @param array<string,mixed> $params */
    private function getDimension(array $params) : array{
        $id = (string)($params["id"] ?? $params["dimensionId"] ?? "minecraft:overworld");
        $worldManager = $this->server->getWorldManager();
        $world = $worldManager->getDefaultWorld();
        return [
            "__type" => "Dimension",
            "id" => $id,
            "name" => $world !== null ? $world->getName() : "world",
            "heightRange" => ["min" => -64, "max" => 320],
        ];
    }

    /** @param array<string,mixed> $params */
    private function getEntity(array $params) : ?array{
        $id = $params["id"] ?? null;
        if($id === null) return null;
        // check players first
        foreach($this->server->getInGamePlayers() as $p){
            if($p->getId() === (int)$id) return $this->serializePlayer($p);
        }
        // check world entities
        foreach($this->server->getWorldManager()->getWorlds() as $world){
            foreach($world->getEntities() as $e){
                if(method_exists($e, "getId") && $e->getId() === (int)$id){
                    $pos = method_exists($e, "getPosition") ? $e->getPosition() : null;
                    return [
                        "__type" => "Entity",
                        "id" => $e->getId(),
                        "typeId" => method_exists($e, "getNetworkTypeId") ? $e->getNetworkTypeId() : (method_exists($e, "getTypeId") ? $e->getTypeId() : "unknown"),
                        "nameTag" => method_exists($e, "getNameTag") ? $e->getNameTag() : "",
                        "location" => $pos ? ["x"=>$pos->x,"y"=>$pos->y,"z"=>$pos->z] : ["x"=>0,"y"=>0,"z"=>0],
                        "isValid" => method_exists($e, "isClosed") ? !$e->isClosed() : true,
                    ];
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $params */
    private function playerSendMessage(array $params) : bool{
        $p = $this->findPlayer($params);
        if($p === null) return false;
        $msg = $params["message"] ?? "";
        if(is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $p->sendMessage((string)$msg);
        return true;
    }

    /** @param array<string,mixed> $params */
    private function entitySendMessage(array $params) : bool{
        return $this->playerSendMessage($params);
    }

    /** @param array<string,mixed> $params */
    private function playerRunCommand(array $params) : array{
        $p = $this->findPlayer($params);
        $params["senderName"] = $p?->getName();
        return $this->runCommand($params);
    }

    /** @param array<string,mixed> $params */
    private function findPlayer(array $params) : ?Player{
        $name = $params["playerName"] ?? $params["name"] ?? null;
        $id = $params["playerId"] ?? $params["id"] ?? null;
        foreach($this->server->getInGamePlayers() as $p){
            if(is_string($name) && strtolower($p->getName()) === strtolower($name)) return $p;
            if((is_int($id) || is_numeric($id)) && $p->getId() === (int)$id) return $p;
        }
        return null;
    }

    /** @param array<string,mixed> $params */
    private function teleportEntity(array $params) : bool{
        $target = $this->findPlayer($params) ?? null;
        if($target === null && isset($params["id"])){
            foreach($this->server->getWorldManager()->getWorlds() as $candidateWorld){
                $candidate = $candidateWorld->getEntity((int)$params["id"]);
                if($candidate instanceof CustomEntity){ $target = $candidate; break; }
            }
        }
        if($target === null) return false;
        $loc = $params["location"] ?? $params;
        $x = (float)($loc["x"] ?? 0); $y = (float)($loc["y"] ?? 0); $z = (float)($loc["z"] ?? 0);
        $target->teleport(new Vector3($x, $y, $z));
        return true;
    }

    private function findCustomEntity(int $id) : ?CustomEntity{
        foreach($this->server->getWorldManager()->getWorlds() as $world){
            $entity = $world->getEntity($id);
            if($entity instanceof CustomEntity) return $entity;
        }
        return null;
    }

    private function applyEntityDamage(array $params) : bool{
        $entity = isset($params["id"]) ? $this->findCustomEntity((int)$params["id"]) : null;
        if($entity === null) return false;
        $amount = (float)($params["amount"] ?? 1);
        if($amount <= 0 || $amount > 2048) return false;
        $entity->damage(new DamageSource(DamageSource::CAUSE_CUSTOM, $amount));
        return true;
    }

    private function setEntityHealth(array $params) : bool{
        $entity = isset($params["id"]) ? $this->findCustomEntity((int)$params["id"]) : null;
        if($entity === null || !is_numeric($params["value"] ?? null)) return false;
        $entity->setHealth((float)$params["value"]);
        return true;
    }

    private function setEntityNameTag(array $params) : bool{
        $entity = isset($params["id"]) ? $this->findCustomEntity((int)$params["id"]) : null;
        if($entity === null || !is_string($params["nameTag"] ?? null)) return false;
        $entity->setNameTag($params["nameTag"]);
        foreach($entity->getViewers() as $viewer){ $viewer->getNetworkSession()->sendCustomEntityMetadata($entity); }
        return true;
    }

    private function removeEntity(array $params) : bool{
        $entity = isset($params["id"]) ? $this->findCustomEntity((int)$params["id"]) : null;
        if($entity === null) return false;
        $entity->close();
        return true;
    }

    /** @param array<string,mixed> $params */
    private function entityComponent(string $method, array $params) : mixed{
        // Components require deep engine integration; expose queryable stubs + supported data.
        $has = $method === "entity.hasComponent";
        $type = (string)($params["componentId"] ?? $params["type"] ?? "");
        $p = $this->findPlayer($params);
        $entity = null;
        if($p === null && isset($params["id"])){
            foreach($this->server->getWorldManager()->getWorlds() as $world){
                $candidate = $world->getEntity((int)$params["id"]);
                if($candidate instanceof CustomEntity){ $entity = $candidate; break; }
            }
        }
        if(($p !== null || $entity !== null) && str_contains($type, "health")){
            if($has) return true;
            return ["__type"=>"EntityHealthComponent","entityId"=>$entity?->getId(),"currentValue"=>$entity?->getHealth() ?? 20,"effectiveMax"=>$entity?->getMaxHealth() ?? 20,"effectiveMin"=>0];
        }
        if($p !== null && (str_contains($type, "inventory"))){
            if($has) return true;
            return ["__type"=>"EntityInventoryComponent"];
        }
        return $has ? false : null;
    }

    /** @param array<string,mixed> $params */
    private function worldDynamicProperty(string $method, array $params) : mixed{
        // Dynamic properties persistence not implemented in this server core; return safe defaults.
        if($method === "world.getDynamicPropertyIds") return [];
        return null;
    }

    /** @param array<string,mixed> $params */
    private function setWorldDynamicProperty(array $params) : bool{ return false; }

    /** @param array<string,mixed> $params */
    private function blockOperation(string $method, array $params) : mixed{
        return ["__unsupported" => $method, "note" => "block permutation/type operations require server block API mapping"];
    }

    /** @param array<string,mixed> $params */
    private function dimensionOperation(string $method, array $params) : mixed{
        $world = $this->server->getWorldManager()->getDefaultWorld();
        if($world === null) return ["__error" => "no world"];
        if($method === "dimension.spawnEntity"){
            $typeId = $params["typeId"] ?? $params["identifier"] ?? null;
            $location = $params["location"] ?? [];
            if(!is_string($typeId) || !is_array($location)) return ["__error" => "spawnEntity requires typeId and location"];
            $entity = $this->server->spawnCustomEntity($typeId, new Vector3((float)($location["x"] ?? 0), (float)($location["y"] ?? 0), (float)($location["z"] ?? 0)), $world);
            if(!$entity instanceof CustomEntity) return ["__error" => "unknown custom entity: {$typeId}"];
            return ["__type" => "Entity", "id" => $entity->getId(), "typeId" => $entity->getNetworkTypeId(), "nameTag" => $entity->getNameTag(), "isValid" => true];
        }
        if($method === "dimension.setBlock"){
            $location = $params["location"] ?? [];
            $typeId = $params["typeId"] ?? $params["identifier"] ?? null;
            if(!is_array($location) || !is_string($typeId)) return ["__error" => "setBlock requires location and typeId"];
            $x=(int)floor((float)($location["x"]??0)); $y=(int)floor((float)($location["y"]??0)); $z=(int)floor((float)($location["z"]??0));
            $fallback = $this->server->getPluginCustomBlockFallback($typeId) ?? $typeId;
            try{
                $world->setBlock($x, $y, $z, $this->server->getBlockStateRegistry()->getDefaultState($fallback));
                return true;
            }catch(Throwable $e){ return ["__error" => $e->getMessage()]; }
        }
        if($method === "dimension.getEntities"){
            $out = [];
            if(method_exists($world, "getEntities")){
                foreach($world->getEntities() as $e){
                    $pos = method_exists($e,"getPosition")?$e->getPosition():null;
                    $out[] = ["__type"=>"Entity","id"=>method_exists($e,"getId")?$e->getId():0,"typeId"=>method_exists($e,"getNetworkTypeId")?$e->getNetworkTypeId():(method_exists($e,"getName")?$e->getName():"entity"),"location"=>$pos?["x"=>$pos->x,"y"=>$pos->y,"z"=>$pos->z]:["x"=>0,"y"=>0,"z"=>0],"isValid"=>true];
                }
            }
            foreach($this->server->getInGamePlayers() as $p){
                if($p->getWorld() === $world) $out[] = $this->serializePlayer($p);
            }
            return $out;
        }
        if($method === "dimension.getBlock"){
            $loc = $params["location"] ?? $params;
            $x=(int)floor((float)($loc["x"]??0)); $y=(int)floor((float)($loc["y"]??0)); $z=(int)floor((float)($loc["z"]??0));
            if(method_exists($world, "getBlock")){
                try{
                    $b = $world->getBlock($x,$y,$z);
                    $name = method_exists($b,"getName")?$b->getName():"unknown";
                    return ["__type"=>"Block","location"=>["x"=>$x,"y"=>$y,"z"=>$z],"dimension"=>["id"=>"minecraft:overworld"],"typeId"=>$name,"isValid"=>true,"permutation"=>["typeId"=>$name]];
                }catch(Throwable $e){ return ["__error"=>$e->getMessage()]; }
            }
            return ["__unsupported"=>$method];
        }
        if($method === "dimension.runCommand" || $method === "dimension.runCommandAsync") return $this->runCommand($params);
        return ["__unsupported" => $method];
    }
}
