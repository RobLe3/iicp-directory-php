<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

final readonly class JwtVerificationResult
{
    private function __construct(
        public ?array $claims,
        public ?string $failure,
    ) {}

    public static function valid(array $claims): self
    {
        return new self($claims, null);
    }

    public static function invalid(string $failure = 'invalid'): self
    {
        return new self(null, $failure);
    }

    public function isValid(): bool
    {
        return $this->claims !== null;
    }

    public function isExpired(): bool
    {
        return $this->failure === 'expired';
    }
}
