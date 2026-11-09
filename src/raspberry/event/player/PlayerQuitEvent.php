<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\player\Player;

class PlayerQuitEvent extends PlayerEvent{
    public function __construct(Player $player, private string $quitMessage = "", private string $reason = ""){
        parent::__construct($player);
    }
    public function getQuitMessage() : string{ return $this->quitMessage; }
    public function setQuitMessage(string $quitMessage) : void{ $this->quitMessage = $quitMessage; }
    public function getReason() : string{ return $this->reason; }
}
