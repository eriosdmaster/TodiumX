<?php

declare(strict_types=1);

namespace raspberry\engine\network;

class AuthResult {
    public function __construct(
        public readonly bool $success,
        public readonly ?string $uuid = null,
        public readonly ?string $username = null,
        public readonly array $permissions = [],
        public readonly array $metadata = [],
        public readonly ?string $error = null
    ) {}
    
    public static function success(string $uuid, string $username, array $permissions = [], array $metadata = []): self {
        return new self(true, $uuid, $username, $permissions, $metadata);
    }
    
    public static function failure(string $error): self {
        return new self(false, error: $error);
    }
}
