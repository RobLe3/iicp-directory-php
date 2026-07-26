<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use InvalidArgumentException;

/**
 * Read the small allowlist of secrets that must remain available after
 * `config:cache` without serializing them into Laravel's config cache.
 */
final class RuntimeSecretProvider
{
    public const GENESIS_SEED_SECRET_KEY = 'GENESIS_SEED_SECRET_KEY';

    public const REPLICA_ED25519_SECRET_KEY = 'IICP_REPLICA_ED25519_SECRET_KEY';

    public const DEV_DID_DOCUMENT_JSON = 'IICP_DEV_DID_DOCUMENT_JSON';

    private const ALLOWED = [
        self::GENESIS_SEED_SECRET_KEY,
        self::REPLICA_ED25519_SECRET_KEY,
        self::DEV_DID_DOCUMENT_JSON,
    ];

    public function get(string $name): ?string
    {
        if (! in_array($name, self::ALLOWED, true)) {
            throw new InvalidArgumentException("Runtime secret is not allowlisted: {$name}");
        }

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
