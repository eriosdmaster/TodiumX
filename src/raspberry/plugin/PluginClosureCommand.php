<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\CommandSender;

use Closure;
use Throwable;

/**
 * Plugin command whose executor is a closure handed to PluginBase::registerCommand(),
 * so a plugin can add a command in onEnable() without an entry in plugin.toml.
 */
final class PluginClosureCommand extends PluginCommand{

    /**
     * @param Closure(CommandSender, list<string>, PluginCommand) : mixed $executor
     * @param list<string>                                                 $aliases
     */
    public function __construct(
        string $name,
        string $description,
        Plugin $plugin,
        private Closure $executor,
        ?string $usage = null,
        ?string $permission = null,
        array $aliases = []
    ){
        parent::__construct($name, $description, $plugin, $usage, $permission, $aliases);
    }

    public function execute(CommandSender $sender, array $args) : bool{
        try{
            return ($this->executor)($sender, $args, $this) !== false;
        }catch(Throwable $exception){
            $sender->sendMessage("§cAn error occurred while executing this command.");
            $this->getPlugin()->getLogger()->error("Command {$this->getName()} error: " . $exception->getMessage());

            return true;
        }
    }
}
