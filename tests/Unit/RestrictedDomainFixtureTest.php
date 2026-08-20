<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\RestrictedDomainDecision;
use PHPUnit\Framework\TestCase;

class RestrictedDomainFixtureTest extends TestCase
{
    private const FIXTURE_SHA256 = '0b23cc925dd3409d1c39d788e54281e60255b16dcd83fe5e4be84720ddd6039f';

    public function test_canonical_fixture_digest_and_every_decision_match(): void
    {
        $path = dirname(__DIR__, 2).'/parity/restricted-trust-domain-v0.json';
        $bytes = file_get_contents($path);
        $this->assertSame(self::FIXTURE_SHA256, hash('sha256', $bytes));
        $fixture = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('0.1.0-draft', $fixture['fixture_version']);
        $this->assertSame('pre-normative', $fixture['status']);

        foreach ($fixture['vectors'] as $vector) {
            $this->assertSame(
                $vector['expected'],
                RestrictedDomainDecision::evaluate($vector['input']),
                $vector['id'],
            );
        }
    }
}
