<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\NodeEventLogger;
use App\Services\RestrictedDomainMembershipAssertionService;
use Tests\TestCase;

class RestrictedDomainMembershipAssertionTest extends TestCase
{
    public function test_shared_authority_signature_vectors_are_consumed_without_translation(): void
    {
        $fixture = json_decode(file_get_contents(base_path('parity/restricted-trust-domain-membership-v0.json')), true, flags: JSON_THROW_ON_ERROR);
        $service = app(RestrictedDomainMembershipAssertionService::class);

        foreach ($fixture['vectors'] as $vector) {
            $valid = $service->verify($vector['envelope'], $fixture['authority_public_key_ed25519']);
            $this->assertSame($vector['expected'] === 'valid', $valid, $vector['id']);
        }
    }

    public function test_shared_gossip_vectors_bind_member_signature_payload_and_replay_id(): void
    {
        $fixture = json_decode(file_get_contents(base_path('parity/restricted-trust-domain-membership-v0.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($fixture['gossip_vectors'] as $vector) {
            $assertion = $vector['membership']['assertion'];
            $proof = $vector['gossip']['proof'];
            $this->assertTrue(sodium_crypto_sign_verify_detached(
                $this->decode($vector['gossip']['signature']['value']),
                "IICP-RTD-GOSSIP-V0\n".NodeEventLogger::canonicalJson($proof),
                $this->decode($assertion['subject']['public_key_ed25519']),
            ), $vector['id']);
            $this->assertSame(hash('sha256', $vector['payload_utf8']), $proof['payload_sha256']);
            $replayed = in_array($proof['replay_id'], $vector['seen_replay_ids'] ?? [], true);
            $this->assertSame($vector['expected'] === 'replay_detected', $replayed);
        }
    }

    private function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
