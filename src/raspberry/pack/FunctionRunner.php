<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\SilentCommandSender;

use raspberry\Server;

use Throwable;

/**
 * Runs the functions of the enabled behavior packs by sending each line through the command system,
 * as if the sender had typed it.
 */
class FunctionRunner{

    /** a function may call another function, up to this many levels */
    public const MAX_DEPTH = 16;

    /** most commands one /function may run, counting the functions it calls; stops loops that call themselves */
    public const MAX_COMMANDS = 10000;

    private Server $server;

    private BehaviorPackManager $packs;

    private SilentCommandSender $silentSender;

    private int $depth = 0;

    private int $executed = 0;

    public function __construct(Server $server, BehaviorPackManager $packs, SilentCommandSender $silentSender){
        $this->server = $server;
        $this->packs = $packs;
        $this->silentSender = $silentSender;
    }

    /**
     * Runs one function. A line that fails sends its message to $sender and the function goes on
     * with the next line, like in the game.
     *
     * @return int how many lines of this function were run
     *
     * @throws CommandException when the function does not exist, calls too deep, or runs too many commands
     */
    public function run(CommandSender $sender, string $id) : int{
        $language = $this->server->getLanguage();
        $function = $this->packs->getFunction($id) ?? throw new CommandException($language->translate("command.function.unknown", ["function" => $id]));

        if($this->depth >= self::MAX_DEPTH){
            throw new CommandException($language->translate("command.function.depth", ["max" => self::MAX_DEPTH]));
        }

        if($this->depth === 0){
            $this->executed = 0;
        }

        ++$this->depth;
        $count = 0;

        try{
            foreach($function->getLines() as $line){
                if(++$this->executed > self::MAX_COMMANDS){
                    throw new CommandException($language->translate("command.function.limit", ["max" => self::MAX_COMMANDS]));
                }

                $this->server->dispatchCommand($sender, $line);
                ++$count;
            }
        }finally{
            --$this->depth;
        }

        return $count;
    }

    /**
     * Runs the functions listed in tick.json. Called once per server tick; does nothing when there are none.
     */
    public function tick() : void{
        foreach($this->packs->getTickFunctionIds() as $id){
            try{
                $this->run($this->silentSender, $id);
            }catch(Throwable $exception){
                $this->server->getLogger()->debug("Tick function \"{$id}\" stopped: " . $exception->getMessage());
            }
        }
    }
}
