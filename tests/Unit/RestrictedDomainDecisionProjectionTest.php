<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Models\TrustDomainMembership;
use App\Services\RestrictedDomainDecisionProjection;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RestrictedDomainDecisionProjectionTest extends TestCase
{
    private const FIXTURE_SHA256 = 'd97cbccab0ca86b546a574aab0bd5e92d3138269f4a5adb3b99cfe9dac927d6b';

    public function test_projection_matches_canonical_positive_vectors(): void
    {
        $path = dirname(__DIR__, 2).'/parity/restricted-trust-domain-directory-decision-v0.json';
        $bytes = file_get_contents($path);
        $this->assertSame(self::FIXTURE_SHA256, hash('sha256', $bytes));
        $fixture = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        config()->set('iicp.restricted_domain.domain_id', 'domain-test-a');
        config()->set('iicp.restricted_domain.authority_id', 'did:iicp:test:directory-a');
        $service = app(RestrictedDomainDecisionProjection::class);

        foreach ($fixture['vectors'] as $vector) {
            if ($vector['expected'] !== 'eligible') {
                continue;
            }
            $expected = $vector['projection'];
            $membership = new TrustDomainMembership;
            $membership->subject_kind = $expected['subject_kind'];
            $membership->generation = $expected['membership_generation'];
            $membership->expires_at = Carbon::createFromTimestamp($expected['membership_expires_at']);
            $inputOperation = $expected['operation'] === 'dispatch_ticket' ? 'dispatch' : $expected['operation'];

            $this->assertSame($expected, $service->forOperation($inputOperation, $membership), $vector['id']);
        }
    }

    public function test_uncovered_operations_have_no_projection(): void
    {
        $membership = new TrustDomainMembership;
        $membership->subject_kind = 'node';
        $membership->generation = 1;
        $membership->expires_at = now()->addHour();

        $this->assertNull(app(RestrictedDomainDecisionProjection::class)->forOperation('heartbeat', $membership));
    }
}
