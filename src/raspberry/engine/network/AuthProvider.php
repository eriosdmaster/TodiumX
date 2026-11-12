<?php

declare(strict_types=1);

namespace raspberry\engine\network;

interface AuthProvider {
    public function authenticate(string $username, string $token, array $extraData = []): ?AuthResult;
    public function getName(): string;
}
