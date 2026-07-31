<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use Tests\TestCase;

class ReplicaLifecycleContractFixtureTest extends TestCase
{
    public function test_normative_replica_lifecycle_fixture_is_consumed(): void
    {
        $path = base_path('parity/replica-lifecycle-contract-v1.json');
        $this->assertSame('9ae2e3536891c3488a2b4d04e543364359a2b9a2c389203ece0eaca1a341b541', hash_file('sha256', $path));
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/v1/replicas/deregister', $fixture['deregister']['path']);
        $this->assertSame('REPLICA_DEREGISTERED', $fixture['deregister']['event_type']);
        $this->assertSame(['expired', 'dormant', 'archived', 'decommissioned'], $fixture['persistent_auth_rejections']);
        $this->assertSame('active', $fixture['same_did_reregistration']['status']);
        $this->assertSame('low', $fixture['same_did_reregistration']['trust_tier']);
        $this->assertFalse($fixture['privacy']['contains_credentials']);
    }
}
