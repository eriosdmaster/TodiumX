<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

use function str_contains;
use function trim;

class ScriptEventCommand extends Command{

	public function __construct(){
		parent::__construct("scriptevent");

		$this->setDescription($this->translate("command.scriptevent.description"));
		$this->setPermission("raspberry.command.scriptevent");

		$this->setArgument(0, CommandParameter::string("id"));
		$this->setArgument(1, CommandParameter::message("message"));
	}

	public function onSent(CommandSender $sender, array $args) : void{
		$id = (string) ($args["id"] ?? "");
		$message = trim((string) ($args["message"] ?? ""));

		if($id === "" || !str_contains($id, ":")){
			throw new CommandException($this->translate("command.scriptevent.badId"));
		}

		$manager = Server::getInstance()->getScriptManager();

		if($manager === null || !$manager->isRunning()){
			throw new CommandException($this->translate("command.scriptevent.notRunning"));
		}

		$manager->emitScriptEvent($id, $message);
		$sender->sendMessage($this->translate("command.scriptevent.sent", ["id" => $id]));
	}
}
