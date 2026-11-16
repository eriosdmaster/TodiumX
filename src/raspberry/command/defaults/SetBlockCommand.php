<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\block\BlockState;
use raspberry\block\BlockStateRegistry;
use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;
use raspberry\Server;

use InvalidArgumentException;

use function array_map;
use function count;
use function explode;
use function floor;
use function in_array;
use function is_bool;
use function is_int;
use function preg_match;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Places one block, the console equivalent of a player breaking/placing it:
 *
 *   /setblock 10 64 -3 minecraft:stone
 *   /setblock ~ ~-1 ~ oak_log[axis=y]
 */
class SetBlockCommand extends Command{

    public function __construct(){
        parent::__construct("setblock");

        $this->setDescription($this->translate("command.setblock.description"));
        $this->setPermission("raspberry.command.setblock");

        $this->setArgument(0, CommandParameter::blockPosition("position"));
        $this->setArgument(1, CommandParameter::rawText("block"));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $server = Server::getInstance();
        $position = $args["position"];
        $x = (int) floor($position->x);
        $y = (int) floor($position->y);
        $z = (int) floor($position->z);

        $state = $this->parseBlock($server->getBlockStateRegistry(), (string) $args["block"]);

        $world = $sender instanceof Player ? $sender->getWorld() : $server->getWorldManager()->getDefaultWorld();
        $world->setBlock($x, $y, $z, $state);

        $sender->sendMessage($this->translate("command.setblock.done", [
            "block" => $state->getName(),
            "x" => $x,
            "y" => $y,
            "z" => $z
        ]));
    }

    /**
     * Reads "minecraft:oak_log[axis=y, stripped_bit=true]" (the namespace and the states optional).
     */
    private function parseBlock(BlockStateRegistry $registry, string $input) : BlockState{
        $input = trim($input);
        $name = $input;
        $states = [];

        if(preg_match('/^([^\[\]]+)\[([^\]]*)\]$/D', $input, $match) === 1){
            $name = trim($match[1]);

            foreach(explode(",", $match[2]) as $pair){
                $pair = trim($pair);

                if($pair === ""){
                    continue;
                }

                $parts = array_map(trim(...), explode("=", $pair, 2));

                if(count($parts) !== 2 || $parts[0] === "" || $parts[1] === ""){
                    throw new CommandException($this->translate("command.setblock.invalid", ["block" => $input]));
                }

                $states[$parts[0]] = $parts[1];
            }
        }

        $name = strtolower($name);

        if(!str_contains($name, ":")){
            $name = "minecraft:" . $name;
        }

        if(!$registry->isRegistered($name)){
            throw new CommandException($this->translate("command.setblock.unknownBlock", ["block" => $name]));
        }

        $default = $registry->getDefaultState($name);
        $properties = [];

        foreach($states as $property => $value){
            if(!$default->hasProperty($property)){
                throw new CommandException($this->translate("command.setblock.unknownState", ["block" => $name, "state" => $property]));
            }

            $current = $default->getProperty($property);
            $properties[$property] = match(true){
                is_bool($current) => in_array(strtolower($value), ["true", "1", "yes"], true),
                is_int($current) => (int) $value,
                default => $value
            };
        }

        try{
            return $registry->get($name, $properties);
        }catch(InvalidArgumentException){
            throw new CommandException($this->translate("command.setblock.invalidStates", ["block" => $name]));
        }
    }
}
