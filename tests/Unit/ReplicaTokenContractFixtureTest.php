<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\JwtService;
use Tests\TestCase;

class ReplicaTokenContractFixtureTest extends TestCase
{
    public function test_fixture_pins_the_php_rust_security_boundary(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('parity/federation-profile-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('iicp.replica-token-contract.v1', $fixture['schema']);
        $this->assertSame(JwtService::REPLICA_SCOPE, $fixture['current_scope']);
        $this->assertSame(JwtService::LEGACY_REPLICA_SCOPE, $fixture['legacy_scope']);
        $this->assertSame(200, $fixture['registration']['success_status']);
        $this->assertTrue($fixture['registration']['rotates_token']);
        $this->assertFalse($fixture['registration']['plaintext_token_persisted']);
        $this->assertTrue($fixture['snapshot']['bearer_required']);
        $this->assertSame(401, $fixture['snapshot']['missing_status']);
        $this->assertFalse($fixture['events']['bearer_required']);
        $this->assertTrue($fixture['events']['signed']);
    }
}
