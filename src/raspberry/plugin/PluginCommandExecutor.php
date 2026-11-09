<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\Command;
use raspberry\command\CommandSender;

interface PluginCommandExecutor{
    /**
     * @param list<string> $args
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool;
}
