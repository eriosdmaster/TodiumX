<?php

declare(strict_types=1);

namespace raspberry\pack;

use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\lang\Language;
use raspberry\utils\Logger;
use raspberry\Server;

use function count;
use function strtolower;
use function trim;

/**
 * Runs the .mcfunction files of the active behavior packs. Functions are registered
 * by their path inside the pack's functions/ folder ("buildings/house"), and each
 * line is dispatched as a normal console command.
 */
final class FunctionRunner{

    /** @var array<string, list<string>> function id => command lines */
    private array $functions = [];

    private Language $language;
    private Logger $logger;

    public function __construct(Language $language, Logger $logger){
        $this->language = $language;
        $this->logger = $logger;
    }

    /**
     * Registers one function; later packs override earlier ones, like the official stack.
     *
     * @param list<string> $lines
     */
    public function addFunction(string $id, array $lines) : void{
        $id = strtolower(trim($id));
        if($id === ""){
            return;
        }
        if(isset($this->functions[$id])){
            $this->logger->debug("Function \"{$id}\" was overridden by another behavior pack");
        }
        $this->functions[$id] = array_values($lines);
    }

    public function has(string $id) : bool{
        return isset($this->functions[strtolower(trim($id))]);
    }

    /**
     * @return list<string>
     */
    public function getFunctionIds() : array{
        return array_keys($this->functions);
    }

    /**
     * Runs every command line of the function. Returns the number of commands that ran.
     *
     * @throws CommandException when the function does not exist
     */
    public function run(CommandSender $sender, string $name) : int{
        $lines = $this->functions[strtolower(trim($name))] ?? null;

        if($lines === null){
            throw new CommandException($this->language->translate("command.function.notFound", ["function" => $name]));
        }

        $count = 0;
        foreach($lines as $line){
            try{
                if(Server::getInstance()->dispatchCommand($sender, $line)){
                    $count++;
                }
            }catch(\Throwable $exception){
                //dispatchCommand already logs and reports failures; one bad line must not stop the function
                $this->logger->debug("Function \"{$name}\" line failed: " . $exception->getMessage());
            }
        }
        return $count;
    }
}
