<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

class FunctionCommand extends Command{

    public function __construct(){
        parent::__construct("function");

        $this->setDescription($this->translate("command.function.description"));
        $this->setPermission("raspberry.command.function");

        $this->setArgument(0, CommandParameter::filepath("name"));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $count = Server::getInstance()->getFunctionRunner()->run($sender, $args["name"]);

        $sender->sendMessage($this->translate("command.function.done", [
            "function" => $args["name"],
            "count" => $count
        ]));
    }
}
