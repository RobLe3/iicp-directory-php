<?php

namespace Tests\Unit;

use App\Services\E050OwnershipPolicy;
use PHPUnit\Framework\TestCase;

class E050OwnershipPolicyTest extends TestCase
{
    public function test_shared_strict_e050_fixture(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/parity/e050-strict-v0.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('iicp.e050_strict_parity.v0', $fixture['schema']);
        foreach ($fixture['cases'] as $case) {
            $input = $case['input'];
            $actual = E050OwnershipPolicy::allows(
                $input['strict'],
                $input['secured'],
                $input['endpoint_changed'],
                $input['transport_endpoint_changed'],
                $input['relay_endpoint_changed'],
                $input['has_ownership'],
                $input['old_endpoint_alive'],
            );
            $this->assertSame($case['allowed'], $actual, $case['name']);
        }
    }
}
