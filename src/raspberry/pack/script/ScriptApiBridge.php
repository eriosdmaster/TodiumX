<?php

declare(strict_types=1);

namespace raspberry\pack\script;

/**
 * Everything the Script API host may ask the running server to do, kept behind
 * an interface so ScriptManager can be exercised by tests without a full server.
 */
interface ScriptApiBridge{

	/**
	 * Broadcasts a chat/system message to every player, as world.sendMessage() does.
	 */
	public function broadcastMessage(string $message) : int;

	/**
	 * Sends a message to one player by runtime entity id, as Player.sendMessage() does.
	 */
	public function sendPlayerMessage(int $playerId, string $message) : bool;

	/**
	 * @return list<array{id: int, name: string, x: float, y: float, z: float, dimension: string}>
	 */
	public function getOnlinePlayers() : array;

	/**
	 * Runs a command line coming from a script (dimension.runCommand / player.runCommand),
	 * with the console-level permissions scripts expect; output sent to $sender.
	 */
	public function executeCommandLine(string $commandLine, ScriptCommandSender $sender) : bool;

	public function getCurrentTick() : int;

	/**
	 * Permission manager used to build ScriptCommandSender instances.
	 */
	public function getPermissionManager() : \raspberry\permission\PermissionManager;
}
