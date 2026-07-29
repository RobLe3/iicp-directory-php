<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Support\AppKeyPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppKeyPolicyTest extends TestCase
{
    public function test_allows_test_sentinel_only_in_testing(): void
    {
        AppKeyPolicy::assertSafeForEnvironment('testing', AppKeyPolicy::TEST_SENTINEL);
        $this->addToAssertionCount(1);
    }

    public function test_rejects_test_sentinel_outside_testing(): void
    {
        $this->expectException(RuntimeException::class);
        AppKeyPolicy::assertSafeForEnvironment('production', AppKeyPolicy::TEST_SENTINEL);
    }

    public function test_allows_non_sentinel_production_key(): void
    {
        AppKeyPolicy::assertSafeForEnvironment('production', str_repeat('x', 32));
        $this->addToAssertionCount(1);
    }
}
