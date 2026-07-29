<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Support;

use RuntimeException;

final class AppKeyPolicy
{
    public const TEST_SENTINEL = '00000000000000000000000000000000';

    public static function assertSafeForEnvironment(string $environment, string $key): void
    {
        if ($environment !== 'testing' && hash_equals(self::TEST_SENTINEL, $key)) {
            throw new RuntimeException('The test-only APP_KEY cannot be used outside the testing environment.');
        }
    }
}
