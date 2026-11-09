<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Event;
use raspberry\player\Player;

abstract class PlayerEvent extends Event{
    public function __construct(protected Player $player){}
    public function getPlayer() : Player{ return $this->player; }
}
