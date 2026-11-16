<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use function is_string;
use function preg_split;
use function trim;

/**
 * A command owned by a plugin. It takes the rest of the line as raw text and
 * hands the plugin the arguments already split, so a plugin command can accept
 * anything without declaring one CommandParameter per argument.
 */
abstract class PluginCommand extends Command{

    /**
     * @param list<string> $aliases
     */
    public function __construct(
        string $name,
        string $description,
        private Plugin $plugin,
        ?string $usage = null,
        ?string $permission = null,
        array $aliases = []
    ){
        parent::__construct($name, $description, [[CommandParameter::rawText("args", true)]], $aliases);

        if($usage !== null){
            $this->setUsage($usage);
        }

        if($permission !== null){
            $this->setPermission($permission);
        }
    }

    public function getPlugin() : Plugin{
        return $this->plugin;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function onSent(CommandSender $sender, array $args) : void{
        $raw = isset($args["args"]) && is_string($args["args"]) ? trim($args["args"]) : "";
        $list = $raw === "" ? [] : preg_split('/\s+/', $raw);

        if(!$this->execute($sender, $list === false ? [] : $list)){
            $sender->sendMessage("§cUsage: " . $this->getUsage());
        }
    }

    /**
     * @param list<string> $args
     *
     * @return bool false to print the usage line (when the arguments do not fit)
     */
    abstract public function execute(CommandSender $sender, array $args) : bool;
}
