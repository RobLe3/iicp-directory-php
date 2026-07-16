<?php

namespace Tests\Unit;

use App\Services\PolicyDetailDisclosurePolicy;
use PHPUnit\Framework\TestCase;

final class PolicyDetailDisclosurePolicyTest extends TestCase
{
    public function test_portable_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2).'/parity/policy-detail-disclosure-v0.json';
        $fixtureBytes = file_get_contents($fixturePath);
        $this->assertNotFalse($fixtureBytes);

        // The standalone directory export cannot rely on the monorepo's spec/
        // tree. Keep its portable copy byte-identical whenever the canonical
        // pre-normative fixture is available in the monorepo checkout.
        $canonicalPath = dirname(__DIR__, 2).'/../spec/proposals/fixtures/policy-detail-disclosure-v0.json';
        if (is_file($canonicalPath)) {
            $this->assertSame(file_get_contents($canonicalPath), $fixtureBytes);
        }

        $fixture = json_decode($fixtureBytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(PolicyDetailDisclosurePolicy::ALLOWED_DETAIL_FIELDS, $fixture['allowed_detail_fields']);

        foreach ($fixture['cases'] as $case) {
            $decision = PolicyDetailDisclosurePolicy::evaluate($case['context']);
            $this->assertSame($case['expected']['status'], $decision['status'], $case['id']);
            $this->assertSame($case['expected']['reason'], $decision['reason'], $case['id']);
            if ($decision['status'] === 200) {
                $encoded = json_encode($decision['body'], JSON_THROW_ON_ERROR);
                foreach (['must-not-leak', 'private.example', 'backend_topology', 'natural_person_contact'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $encoded, $case['id']);
                }
            }
        }

        $vector = $fixture['crypto_vectors'];
        $verify = fn (string $token): array => PolicyDetailDisclosurePolicy::verifyConsumerToken(
            $token,
            $vector['public_key_hex'],
            $vector['expected_target_node_id'],
            $vector['expected_intent'],
            $vector['evaluated_at_unix'],
        );
        $valid = $verify($vector['valid_consumer_token']);
        $this->assertSame('valid', $valid['status']);
        $this->assertSame($vector['expected_subject'], $valid['claims']['sub']);
        $this->assertSame('expired', $verify($vector['expired_consumer_token'])['status']);
        $this->assertSame('invalid', $verify($vector['tampered_consumer_token'])['status']);
    }
}
