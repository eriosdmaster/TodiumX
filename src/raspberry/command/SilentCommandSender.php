<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissionManager;

/**
 * Runs commands like the console does, but drops their messages. Used for functions that run by
 * themselves, such as the ones in tick.json, so they do not fill the log.
 */
class SilentCommandSender implements CommandSender{
    use PermissibleTrait;

    private PermissionManager $permissionManager;

    public function __construct(PermissionManager $permissionManager){
        $this->permissionManager = $permissionManager;
    }

    public function getName() : string{
        return "Function";
    }

    public function sendMessage(string $message) : void{
    }

    public function getPermissionLevel() : PermissionLevel{
        return PermissionLevel::CONSOLE;
    }

    protected function getPermissionManager() : PermissionManager{
        return $this->permissionManager;
    }
}
