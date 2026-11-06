<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

final class DependencyPathBoundaryTest extends TestCase
{
    public function test_owned_child_is_within_the_declared_base(): void
    {
        $this->assertTrue(Path::isBasePath('/fixture-root', '/fixture-root/child/marker.txt'));
    }

    public function test_non_scheme_prefix_cannot_hide_escape_from_base(): void
    {
        $this->assertFalse(Path::isBasePath('/fixture-root', '/fixture-root/../x://../synthetic-marker.txt'));
    }
}
