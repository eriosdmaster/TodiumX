<?php

declare(strict_types=1);

namespace raspberry\engine\network;

interface InstanceConnection {
    public function send(array $message): void;
    public function close(): void;
    public function isConnected(): bool;
}
