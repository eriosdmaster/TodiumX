<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use raspberry\command\CommandSender;
use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissionManager;

use raspberry\utils\Logger;

use function strlen;

/**
 * CommandSender for commands dispatched from scripts (world.getDimension().runCommand()).
 * Output goes to the server console and back to the script as the command result.
 */
class ScriptCommandSender implements CommandSender{
	use PermissibleTrait;

	private Logger $logger;

	private PermissionManager $permissionManager;

	/** @var list<string> */
	private array $output = [];

	public function __construct(
		private string $packName,
		Logger $logger,
		PermissionManager $permissionManager
	){
		$this->logger = $logger;
		$this->permissionManager = $permissionManager;
	}

	public function getName() : string{
		return "ScriptAPI:" . $this->packName;
	}

	public function sendMessage(string $message) : void{
		//TextFormat markers are meaningless for scripts; strip them from the result
		$message = preg_replace("/\xc2\xa7./", "", $message) ?? $message;
		$this->logger->info("[" . $this->getName() . "] " . $message);
		if($message !== "" && strlen($message) < 2048){
			$this->output[] = $message;
		}
	}

	public function getPermissionLevel() : PermissionLevel{
		return PermissionLevel::CONSOLE;
	}

	protected function getPermissionManager() : PermissionManager{
		return $this->permissionManager;
	}

	/**
	 * Everything sendMessage() collected, joined for the script's command result.
	 */
	public function collectOutput() : string{
		$output = implode("\n", $this->output);
		$this->output = [];
		return $output;
	}
}
