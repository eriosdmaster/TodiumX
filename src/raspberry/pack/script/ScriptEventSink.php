<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use raspberry\entity\Entity;
use raspberry\player\Player;

/**
 * The one-way link from gameplay code (BlockBreaker, BlockPlacer, …) to the
 * Script API host. Engine classes call the static methods; the sink forwards a
 * player/entity snapshot to the ScriptManager when one is attached, and does
 * nothing otherwise — gameplay never depends on scripts being loaded.
 *
 * Server.php attaches the ScriptManager after it loads and detaches it on stop.
 */
final class ScriptEventSink{

	private static ?ScriptManager $manager = null;

	private function __construct(){
	}

	public static function attach(?ScriptManager $manager) : void{
		self::$manager = $manager;
	}

	/**
	 * Fires world.afterEvents.playerBreakBlock on every subscribed pack.
	 */
	public static function playerBreakBlock(Player $player, string $blockType, int $x, int $y, int $z) : void{
		self::$manager?->emitPlayerBreakBlock(ServerScriptBridge::playerSnapshot($player), $blockType, $x, $y, $z);
	}

	/**
	 * Fires world.afterEvents.playerPlaceBlock on every subscribed pack.
	 */
	public static function playerPlaceBlock(Player $player, string $blockType, int $x, int $y, int $z) : void{
		self::$manager?->emitPlayerPlaceBlock(ServerScriptBridge::playerSnapshot($player), $blockType, $x, $y, $z);
	}

	/**
	 * Shared snapshot builder for non-player entities (used by script bridges).
	 *
	 * @return array{id: int, name: string, type: string, x: float, y: float, z: float, dimension: string, yaw: float, pitch: float, health: float|null}
	 */
	public static function entitySnapshot(Entity $entity) : array{
		$position = $entity->getPosition();
		$type = $entity->getNetworkTypeId();

		return [
			"id" => $entity->getId(),
			"name" => $type,
			"type" => $type,
			"x" => $position->x,
			"y" => $position->y,
			"z" => $position->z,
			"dimension" => $entity->getWorld()->getName(),
			"yaw" => $entity->getYaw(),
			"pitch" => $entity->getPitch(),
			"health" => null
		];
	}
}
