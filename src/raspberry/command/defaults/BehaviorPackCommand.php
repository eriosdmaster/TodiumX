<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\pack\BehaviorPack;
use raspberry\pack\PackException;

use raspberry\Server;

use function array_map;
use function count;
use function implode;

class BehaviorPackCommand extends Command{

    public function __construct(){
        parent::__construct("behaviorpack", "", [[]], ["bp"]);

        $this->setDescription($this->translate("command.behaviorpack.description"));
        $this->setPermission("raspberry.command.behaviorpack");

        $this->setArgument(0, CommandParameter::enum("action", new CommandEnum("BehaviorPackAction", ["list", "reload"])));

        $this->setArgument(0, CommandParameter::enum("action", new CommandEnum("BehaviorPackToggle", ["enable", "disable"])), 1);
        $this->setArgument(1, CommandParameter::string("pack"), 1);
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $packs = Server::getInstance()->getBehaviorPackManager();

        switch($args["action"]){
            case "list":
                $this->showList($sender);
                return;

            case "reload":
                $packs->load();

                $sender->sendMessage($this->translate("command.behaviorpack.reloaded", [
                    "enabled" => count($packs->getActivePacks()),
                    "functions" => count($packs->getFunctionIds())
                ]));
                return;
        }

        $pack = $packs->find($args["pack"]) ?? throw new CommandException($this->translate("command.behaviorpack.notFound", ["pack" => $args["pack"]]));

        if($args["action"] === "enable"){
            $missing = $packs->findMissingDependency($pack);

            if($missing !== null){
                throw new CommandException($this->translate("command.behaviorpack.missingDependency", ["name" => $pack->getName(), "dependency" => $missing]));
            }

            try{
                $changed = $packs->enable($pack);
            }catch(PackException $exception){
                throw new CommandException($exception->getMessage());
            }

            if($changed === []){
                throw new CommandException($this->translate("command.behaviorpack.alreadyEnabled", ["name" => $pack->getName()]));
            }

            $sender->sendMessage($this->translate("command.behaviorpack.enabled", ["name" => self::names($changed)]));
        }else{
            $changed = $packs->disable($pack);

            if($changed === []){
                throw new CommandException($this->translate("command.behaviorpack.alreadyDisabled", ["name" => $pack->getName()]));
            }

            $sender->sendMessage($this->translate("command.behaviorpack.disabled", ["name" => self::names($changed)]));
        }
    }

    private function showList(CommandSender $sender) : void{
        $manager = Server::getInstance()->getBehaviorPackManager();
        $found = $manager->getPacks();

        if($found === []){
            $sender->sendMessage($this->translate("command.behaviorpack.none", ["folder" => $manager->getFolder()]));
            return;
        }

        $sender->sendMessage($this->translate("command.behaviorpack.header", [
            "enabled" => count($manager->getActivePacks()),
            "total" => count($found)
        ]));

        foreach($found as $pack){
            $state = $manager->isActive($pack) ? "on" : ($manager->isEnabled($pack) ? "waiting" : "off");

            $sender->sendMessage($this->translate("command.behaviorpack.entry", [
                "state" => $this->translate("command.behaviorpack.state." . $state),
                "name" => $pack->getName(),
                "version" => $pack->getVersion(),
                "uuid" => $pack->getUuid()
            ]));
        }
    }

    /**
     * @param list<BehaviorPack> $packs
     */
    private static function names(array $packs) : string{
        return implode(", ", array_map(fn(BehaviorPack $pack) => $pack->getName(), $packs));
    }
}
