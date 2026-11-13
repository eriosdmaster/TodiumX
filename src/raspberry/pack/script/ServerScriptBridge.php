<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use raspberry\engine\api\ScoreboardsAPI;
use raspberry\math\Vector3;
use raspberry\permission\PermissionManager;
use raspberry\Server;
use raspberry\player\Player;

use function array_values;
use function count;
use function is_int;
use function is_string;
use function strtolower;
use function ucfirst;

/**
 * ScriptApiBridge backed by the real Server instance.
 */
final class ServerScriptBridge implements ScriptApiBridge{

	private ?ScoreboardsAPI $scoreboards = null;

	public function __construct(private Server $server){}

	public function broadcastMessage(string $message) : int{
		return $this->server->broadcastMessage($message);
	}

	public function sendPlayerMessage(int $playerId, string $message) : bool{
		foreach($this->server->getInGamePlayers() as $player){
			if($player->getId() === $playerId){
				$player->sendMessage($message);
				return true;
			}
		}
		return false;
	}

	public function getOnlinePlayers() : array{
		$snapshot = [];
		foreach($this->server->getInGamePlayers() as $player){
			$snapshot[] = self::playerSnapshot($player);
		}
		return $snapshot;
	}

	public static function playerSnapshot(Player $player) : array{
		$position = $player->getPosition();
		return [
			"id" => $player->getId(),
			"name" => $player->getName(),
			"type" => "minecraft:player",
			"x" => $position->x,
			"y" => $position->y,
			"z" => $position->z,
			"dimension" => $player->getWorld()->getName(),
			"yaw" => $player->getYaw(),
			"pitch" => $player->getPitch(),
			"gameMode" => ucfirst(strtolower($player->getGameMode()->name)),
			"health" => $player->getHealth()
		];
	}

	public function executeCommandLine(string $commandLine, ScriptCommandSender $sender) : bool{
		return $this->server->dispatchCommand($sender, $commandLine);
	}

	public function getCurrentTick() : int{
		return $this->server->getCurrentTick();
	}

	public function getPermissionManager() : PermissionManager{
		return $this->server->getPermissionManager();
	}

	public function getEntityById(int $entityId) : ?array{
		foreach($this->server->getInGamePlayers() as $player){
			if($player->getId() === $entityId){
				return self::playerSnapshot($player);
			}
		}

		foreach($this->server->getWorldManager()->getWorlds() as $world){
			$entity = $world->getEntity($entityId);
			if($entity !== null){
				return ScriptEventSink::entitySnapshot($entity);
			}
		}

		return null;
	}

	public function getEntities() : array{
		$snapshot = [];

		foreach($this->server->getInGamePlayers() as $player){
			$snapshot[] = self::playerSnapshot($player);
		}

		//worlds after players, capped so a huge world cannot stall a script tick
		foreach($this->server->getWorldManager()->getWorlds() as $world){
			foreach($world->getEntities() as $entity){
				if($entity instanceof Player){
					continue; //players were already added with their full snapshot
				}
				$snapshot[] = ScriptEventSink::entitySnapshot($entity);
				if(count($snapshot) >= 500){
					return $snapshot;
				}
			}
		}

		return $snapshot;
	}

	public function teleportEntity(int $entityId, float $x, float $y, float $z, ?string $dimension) : bool{
		$world = $dimension !== null ? $this->server->getWorldManager()->getWorld($dimension) : null;

		if($dimension !== null && $world === null){
			return false;
		}

		$position = new Vector3($x, $y, $z);

		foreach($this->server->getInGamePlayers() as $player){
			if($player->getId() === $entityId){
				$player->teleport($position, null, null, $world);
				return true;
			}
		}

		foreach($this->server->getWorldManager()->getWorlds() as $loaded){
			$entity = $loaded->getEntity($entityId);
			if($entity !== null){
				$entity->teleport($position, null, null, $world);
				return true;
			}
		}

		return false;
	}

	public function getBlockInfo(int $x, int $y, int $z) : ?array{
		try{
			$world = $this->server->getWorldManager()->getDefaultWorld();
			$block = $world->getBlock($x, $y, $z);
		}catch(\Throwable){
			return null; //no default world, or the chunk is not loaded
		}

		return [
			"type" => $block->getName(),
			"location" => ["x" => $x, "y" => $y, "z" => $z]
		];
	}

	public function scoreboardOperation(string $method, array $args) : mixed{
		$scoreboards = $this->getScoreboards();

		return match($method){
			"getObjective" => $scoreboards->getObjective(self::string($args, 0)),
			"getObjectives" => array_values($scoreboards->getAllObjectives()),
			"addObjective" => $scoreboards->createObjective(
				self::string($args, 0),
				self::string($args, 1, "dummy"),
				self::string($args, 2, self::string($args, 0))
			),
			"removeObjective" => $scoreboards->removeObjective(self::string($args, 0)),
			"getScore" => $scoreboards->getScore(self::string($args, 0), self::string($args, 1)),
			"setScore" => $scoreboards->setScore(self::string($args, 0), self::string($args, 1), self::int($args, 2)),
			"addScore" => $scoreboards->addScore(self::string($args, 0), self::string($args, 1), self::int($args, 2)),
			"removeScore" => $scoreboards->removeScore(self::string($args, 0), self::string($args, 1)),
			default => throw new \InvalidArgumentException("unknown scoreboard operation '" . $method . "'")
		};
	}

	/**
	 * The scoreboard instance lives as long as the server does; scripts share
	 * the same objectives and scores the engine API sees.
	 */
	public function getScoreboards() : ScoreboardsAPI{
		return $this->scoreboards ??= new ScoreboardsAPI($this->server);
	}

	/**
	 * Scoreboard participants accept a name string or an entity reference the
	 * shim prepped to {id, name}; anything else falls back to $default.
	 */
	public static function participantName(mixed $value, string $default = "") : string{
		if(is_string($value)){
			return $value;
		}
		if(is_array($value) && is_string($value["name"] ?? null)){
			return $value["name"];
		}
		return $default;
	}

	private static function string(array $args, int $index, string $default = "") : string{
		return self::participantName($args[$index] ?? null, $default);
	}

	private static function int(array $args, int $index) : int{
		return is_int($args[$index] ?? null) ? $args[$index] : 0;
	}
}
