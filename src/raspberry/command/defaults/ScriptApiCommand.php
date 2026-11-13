<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\pack\script\ScriptApiVersion;
use raspberry\pack\script\ScriptManager;
use raspberry\Server;
use raspberry\utils\TextFormat;

use function count;
use function trim;

class ScriptApiCommand extends Command{

	public function __construct(){
		parent::__construct("scriptapi", "", [[]], ["sapi"]);

		$this->setDescription($this->translate("command.scriptapi.description"));
		$this->setPermission("raspberry.command.scriptapi");

		$this->setArgument(0, CommandParameter::enum("action", new CommandEnum("ScriptApiAction", ["status", "list", "reload", "eval", "version"])));
		$this->setArgument(1, CommandParameter::message("value", true));
	}

	public function onSent(CommandSender $sender, array $args) : void{
		$manager = Server::getInstance()->getScriptManager();

		if($manager === null){
			throw new CommandException($this->translate("command.scriptapi.disabled"));
		}

		switch($args["action"]){
			case "status":
			case "list":
				$this->showStatus($sender, $manager->getStatus(), $args["action"] === "list");
				return;

			case "reload":
				$loaded = $manager->reload();
				$sender->sendMessage($this->translate("command.scriptapi.reloaded", ["count" => $loaded]));
				return;

			case "eval":
				$this->runEval($sender, $manager, (string) ($args["value"] ?? ""));
				return;

			case "version":
				$this->runVersion($sender, $manager, trim((string) ($args["value"] ?? "")));
				return;
		}

		throw new CommandException($this->translate("command.scriptapi.usage"));
	}

	private function showStatus(CommandSender $sender, array $status, bool $listOnly) : void{
		if(!$status["enabled"]){
			$sender->sendMessage($this->translate("command.scriptapi.disabledByProperty"));
			return;
		}

		if($listOnly){
			if($status["packs"] === []){
				$sender->sendMessage($this->translate("command.scriptapi.noPacks"));
				return;
			}
			foreach($status["packs"] as $pack){
				$sender->sendMessage($this->translate($pack["ok"] ? "command.scriptapi.packOk" : "command.scriptapi.packFailed", [
					"name" => $pack["name"],
					"entry" => $pack["entry"]
				]) . ($pack["error"] !== null ? " - " . $pack["error"] : ""));
			}
			return;
		}

		$sender->sendMessage($this->translate("command.scriptapi.statusLine", [
			"running" => $status["running"] ? "yes" : "no",
			"engine" => $status["engine"] ?? "native library missing",
			"count" => count($status["packs"])
		]));

		if(is_string($status["error"])){
			$sender->sendMessage(TextFormat::RED . $status["error"]);
		}

		foreach($status["packs"] as $pack){
			$sender->sendMessage($this->translate($pack["ok"] ? "command.scriptapi.packOk" : "command.scriptapi.packFailed", [
				"name" => $pack["name"],
				"entry" => $pack["entry"]
			]) . ($pack["error"] !== null ? " - " . $pack["error"] : ""));
		}
	}

	private function runVersion(CommandSender $sender, ScriptManager $manager, string $value) : void{
		if($value === ""){
			$sender->sendMessage($this->translate("command.scriptapi.version.current", [
				"version" => $manager->getVersionSetting(),
				"implemented" => ScriptApiVersion::IMPLEMENTED,
				"check" => $manager->getVersionCheck()
			]));

			foreach($manager->getStatus()["packs"] as $pack){
				$sender->sendMessage($this->translate("command.scriptapi.version.pack", [
					"name" => $pack["name"],
					"declared" => $pack["version"] ?? $this->translate("command.scriptapi.version.none"),
					"state" => $pack["ok"] ? "ok" : "failed"
				]));
			}
			return;
		}

		$normalized = ScriptApiVersion::normalize($value);

		if($normalized === null || !Server::getInstance()->setScriptApiVersion($normalized)){
			throw new CommandException($this->translate("command.scriptapi.version.invalid", ["value" => $value]));
		}

		$loaded = $manager->reload();
		$sender->sendMessage($this->translate("command.scriptapi.version.set", [
			"version" => $normalized,
			"count" => $loaded
		]));
	}

	private function runEval(CommandSender $sender, ScriptManager $manager, string $code) : void{
		if($code === ""){
			throw new CommandException($this->translate("command.scriptapi.usage"));
		}

		if(!$manager->isRunning()){
			throw new CommandException($this->translate("command.scriptapi.notRunning"));
		}

		if(!$manager->hasEvalCapability()){
			throw new CommandException($this->translate("command.scriptapi.evalDisabled"));
		}

		[$ok, $result] = $manager->evalScript($code);
		if(!$ok){
			throw new CommandException($this->translate("command.scriptapi.evalError", ["error" => $result]));
		}

		$sender->sendMessage($this->translate("command.scriptapi.evalDone", ["result" => $result]));
	}
}
