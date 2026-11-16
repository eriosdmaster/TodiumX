<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\plugin\Plugin;
use raspberry\Server;

use function count;

class PluginsCommand extends Command{

    public function __construct(){
        parent::__construct("plugins", "", [[]], ["pl"]);

        $this->setDescription($this->translate("command.plugins.description"));
        $this->setPermission("raspberry.command.plugins");

        $this->setArgument(0, CommandParameter::enum("action", new CommandEnum("PluginAction", ["list", "enable", "disable", "reload"]), true));
        $this->setArgument(1, CommandParameter::string("name", true));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $manager = Server::getInstance()->getPluginManager();
        $action = $args["action"] ?? "list";

        if($action === "list"){
            $this->showList($sender, $manager->getPlugins());
            return;
        }

        $name = $args["name"] ?? throw new CommandException($this->translate("command.plugins.nameRequired"));
        $plugin = $manager->getPlugin($name);

        if($plugin === null){
            throw new CommandException($this->translate("command.plugins.unknown", ["name" => $name]));
        }

        switch($action){
            case "enable":
                $this->enable($sender, $plugin);
                return;

            case "disable":
                $this->disable($sender, $plugin);
                return;

            case "reload":
                if($manager->reloadPlugin($plugin->getName())){
                    $sender->sendMessage($this->translate("command.plugins.reloaded", ["name" => $plugin->getName()]));
                }else{
                    throw new CommandException($this->translate("command.plugins.reloadFailed", ["name" => $plugin->getName()]));
                }
                return;
        }

        throw new CommandException($this->translate("command.plugins.usage"));
    }

    /**
     * @param array<string, Plugin> $plugins
     */
    private function showList(CommandSender $sender, array $plugins) : void{
        if($plugins === []){
            $sender->sendMessage($this->translate("command.plugins.none"));
            return;
        }

        $enabled = 0;
        foreach($plugins as $plugin){
            if($plugin->isEnabled()){
                $enabled++;
            }
        }

        $sender->sendMessage($this->translate("command.plugins.header", ["enabled" => $enabled, "count" => count($plugins)]));

        foreach($plugins as $plugin){
            $sender->sendMessage($this->translate("command.plugins.entry", [
                "state" => $this->translate($plugin->isEnabled() ? "command.plugins.state.on" : "command.plugins.state.off"),
                "name" => $plugin->getName(),
                "version" => $plugin->getDescription()->getVersion()
            ]));
        }
    }

    private function enable(CommandSender $sender, Plugin $plugin) : void{
        if($plugin->isEnabled()){
            $sender->sendMessage($this->translate("command.plugins.alreadyEnabled", ["name" => $plugin->getName()]));
            return;
        }

        if(!Server::getInstance()->getPluginManager()->enablePlugin($plugin)){
            throw new CommandException($this->translate("command.plugins.enableFailed", ["name" => $plugin->getName()]));
        }

        $sender->sendMessage($this->translate("command.plugins.enabled", ["name" => $plugin->getName()]));
    }

    private function disable(CommandSender $sender, Plugin $plugin) : void{
        if(!$plugin->isEnabled()){
            $sender->sendMessage($this->translate("command.plugins.alreadyDisabled", ["name" => $plugin->getName()]));
            return;
        }

        Server::getInstance()->getPluginManager()->disablePlugin($plugin);

        $sender->sendMessage($this->translate("command.plugins.disabled", ["name" => $plugin->getName()]));
    }
}
