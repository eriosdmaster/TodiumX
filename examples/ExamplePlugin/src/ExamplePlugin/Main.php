<?php

declare(strict_types=1);

namespace ExamplePlugin;

use raspberry\command\Command;
use raspberry\command\CommandSender;
use raspberry\event\EventHandler;
use raspberry\event\EventPriority;
use raspberry\event\Listener;
use raspberry\event\player\PlayerChatEvent;
use raspberry\event\player\PlayerJoinEvent;
use raspberry\plugin\PluginBase;
use raspberry\plugin\PluginCommandExecutor;

class Main extends PluginBase implements Listener, PluginCommandExecutor{

    public function onLoad() : void{
        $this->getLogger()->info("[ExamplePlugin] loaded");
    }

    public function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("[ExamplePlugin] enabled. Use /hello");
    }

    public function onDisable() : void{
        $this->getLogger()->info("[ExamplePlugin] disabled");
    }

    #[EventHandler(priority: EventPriority::NORMAL)]
    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $event->setJoinMessage("§e[+] §f" . $player->getName() . " joined (handled by ExamplePlugin)");
    }

    #[EventHandler]
    public function onChat(PlayerChatEvent $event) : void{
        // Example: censor a word (keeps message cancellable/modifiable)
        $msg = $event->getMessage();
        if(str_contains(strtolower($msg), "badword")){
            $event->setMessage(str_ireplace("badword", "***", $msg));
        }
    }

    /**
     * @param list<string> $args
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(strtolower($command->getName()) === "hello"){
            $sender->sendMessage("§aHello from ExamplePlugin! Server: " . $this->getServer()->getServerName());
            return true;
        }
        return false;
    }
}
