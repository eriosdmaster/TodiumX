<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use raspberry\permission\PermissionManager;
use raspberry\Server;
use raspberry\player\Player;

use function count;

/**
 * ScriptApiBridge backed by the real Server instance.
 */
final class ServerScriptBridge implements ScriptApiBridge{

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
			"x" => $position->x,
			"y" => $position->y,
			"z" => $position->z,
			"dimension" => $player->getWorld()->getName()
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
}
