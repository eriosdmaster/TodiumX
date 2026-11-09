<?php

declare(strict_types=1);

namespace raspberry\event;

use raspberry\plugin\Plugin;

use function array_values;
use function ksort;
use function spl_object_id;

class HandlerList{

    /** @var array<int, list<RegisteredListener>> priority => listeners */
    private array $handlers = [];

    /** @var array<class-string<Event>, HandlerList> */
    private static array $allLists = [];

    public function __construct(private string $eventClass){}

    public static function getHandlerListFor(string $eventClass) : self{
        return self::$allLists[$eventClass] ??= new self($eventClass);
    }

    /**
     * @param array<class-string<Event>> $classes
     */
    public static function bakeAll(array $classes) : void{
        foreach($classes as $class){
            self::getHandlerListFor($class);
        }
    }

    public function register(RegisteredListener $listener) : void{
        $p = $listener->getPriority();
        $this->handlers[$p][] = $listener;
        ksort($this->handlers);
    }

    public function unregister(Plugin|RegisteredListener $object) : void{
        foreach($this->handlers as $priority => $listeners){
            foreach($listeners as $i => $listener){
                $remove = false;
                if($object instanceof Plugin){
                    $remove = $listener->getPlugin() === $object;
                }else{
                    $remove = $listener === $object;
                }
                if($remove){
                    unset($this->handlers[$priority][$i]);
                }
            }
            $this->handlers[$priority] = array_values($this->handlers[$priority]);
        }
    }

    /**
     * @return list<RegisteredListener> in priority order
     */
    public function getListeners() : array{
        $result = [];
        foreach($this->handlers as $listeners){
            foreach($listeners as $l){
                $result[] = $l;
            }
        }
        return $result;
    }

    public static function unregisterAll(?Plugin $plugin = null) : void{
        foreach(self::$allLists as $list){
            if($plugin === null){
                $list->handlers = [];
            }else{
                $list->unregister($plugin);
            }
        }
    }
}
