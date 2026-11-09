<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\player\Player;

class PlayerJoinEvent extends PlayerEvent{
    public function __construct(Player $player, private string $joinMessage = ""){
        parent::__construct($player);
    }
    public function getJoinMessage() : string{ return $this->joinMessage; }
    public function setJoinMessage(string $joinMessage) : void{ $this->joinMessage = $joinMessage; }
}
