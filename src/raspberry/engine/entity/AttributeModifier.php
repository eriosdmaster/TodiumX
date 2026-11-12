<?php

declare(strict_types=1);

namespace raspberry\engine\entity;

class AttributeModifier {
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly float $amount,
        public readonly int $operation, // 0 = add, 1 = multiply base, 2 = multiply total
        public readonly string $attribute
    ) {}
    
    public static function add(string $uuid, string $name, float $amount, string $attribute): self {
        return new self($uuid, $name, $amount, 0, $attribute);
    }
    
    public static function multiplyBase(string $uuid, string $name, float $amount, string $attribute): self {
        return new self($uuid, $name, $amount, 1, $attribute);
    }
    
    public static function multiplyTotal(string $uuid, string $name, float $amount, string $attribute): self {
        return new self($uuid, $name, $amount, 2, $attribute);
    }
}
