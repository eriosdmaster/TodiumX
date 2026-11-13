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

	/**
	 * Snapshot of one entity (player or mob) by runtime entity id, as
	 * world.getEntity() does, or null when it does not exist. The snapshot has
	 * the getOnlinePlayers() keys plus type, yaw, pitch and health.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getEntityById(int $entityId) : ?array;

	/**
	 * Snapshots of every entity the server knows (players first), as
	 * world.getEntities() does.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getEntities() : array;

	/**
	 * Teleports the entity with this runtime id to a position, optionally into
	 * another dimension by name. False when the entity or the dimension is unknown.
	 */
	public function teleportEntity(int $entityId, float $x, float $y, float $z, ?string $dimension) : bool;

	/**
	 * The block of the default world at these coordinates, as world.getBlock()
	 * does: ["type" => "minecraft:stone", "location" => ["x"=>..,"y"=>..,"z"=>..]],
	 * or null when the world/chunk is not loaded.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getBlockInfo(int $x, int $y, int $z) : ?array;

	/**
	 * One scoreboard operation, dispatched by path from ScriptManager:
	 * $method is the vanilla-shaped name (getObjective, addObjective, setScore, …)
	 * and $args the positional arguments the script passed. The return value
	 * becomes the script's promise result (JSON encoded).
	 */
	public function scoreboardOperation(string $method, array $args) : mixed;
}
