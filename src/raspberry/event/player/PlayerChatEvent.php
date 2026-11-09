<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\player\Player;

class PlayerChatEvent extends PlayerEvent implements Cancellable{
    public function __construct(Player $player, private string $message, private string $format = "<%s> %s"){
        parent::__construct($player);
    }
    public function getMessage() : string{ return $this->message; }
    public function setMessage(string $message) : void{ $this->message = $message; }
    public function getFormat() : string{ return $this->format; }
    public function setFormat(string $format) : void{ $this->format = $format; }
}
