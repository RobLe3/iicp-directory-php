<?php

namespace Tests\Unit;

use App\Services\JcsCanonicalizer;
use InvalidArgumentException;
use Tests\TestCase;

class CipConsumerCosignatureFixtureTest extends TestCase
{
    private const DOMAIN = "IICP-CIP-CONSUMER-COSIGNATURE-V1\0";

    public function test_canonical_digest_and_dual_ed25519_signatures_are_portable(): void
    {
        $vector = $this->fixture()['canonical_vector'];
        $canonical = app(JcsCanonicalizer::class)->canonicalize($vector['receipt']);

        $this->assertSame($vector['canonical_json_utf8'], $canonical);
        $this->assertSame($vector['canonical_json_sha256'], hash('sha256', $canonical));
        $digest = hash('sha256', self::DOMAIN.$canonical, true);
        $this->assertSame($vector['receipt_digest_hex'], bin2hex($digest));

        foreach (['provider', 'consumer'] as $role) {
            $this->assertTrue(sodium_crypto_sign_verify_detached(
                $this->decodeBase64Url($vector[$role.'_signature_b64url']),
                $digest,
                $this->decodeBase64Url($vector[$role.'_public_key_b64url'])
            ));
        }
    }

    public function test_all_pre_normative_semantic_cases_match_the_reference_contract(): void
    {
        foreach ($this->fixture()['conformance_cases'] as $case) {
            $this->assertSame($case['expected'], $this->evaluate($case['input']), $case['name']);
        }
    }

    public function test_receipt_excludes_private_content_and_self_reported_authority(): void
    {
        $fixture = $this->fixture();
        $fields = array_keys($fixture['canonical_vector']['receipt']);
        $this->assertSame([], array_intersect($fields, $fixture['privacy_contract']['forbidden_fields']));
        $this->assertFalse($fixture['privacy_contract']['self_reported_metrics_have_authority']);
    }

    public function test_settlement_outcomes_remain_idempotent_and_fail_closed(): void
    {
        foreach ($this->fixture()['settlement_cases'] as $case) {
            $input = $case['input'];
            if ($input['reservation'] !== 'held') {
                $actual = ['action' => 'refuse_dispatch', 'awards' => 0, 'debits' => 0];
            } elseif (in_array($input['outcome'], ['timeout', 'cancelled', 'partial'], true)) {
                $actual = ['action' => 'release', 'awards' => 0, 'debits' => 0];
            } else {
                $actual = ['action' => 'settle_once', 'awards' => 1, 'debits' => 1];
            }
            $this->assertSame($case['expected'], $actual, $case['name']);
        }
    }

    public function test_full_jcs_vectors_and_invalid_numbers_fail_closed(): void
    {
        $canonicalizer = app(JcsCanonicalizer::class);
        foreach ($this->fixture()['jcs_vectors'] as $vector) {
            $this->assertSame($vector['canonical_json_utf8'], $canonicalizer->canonicalize($vector['input']), $vector['name']);
        }

        foreach ([NAN, INF, 9007199254740992] as $invalid) {
            try {
                $canonicalizer->canonicalize(['invalid' => $invalid]);
                $this->fail('Invalid JCS number was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 2).'/parity/cip-consumer-cosignature-v1.json';

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function decodeBase64Url(string $value): string
    {
        return base64_decode(strtr($value.str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'), true);
    }

    /** @param array<string,string> $value
     * @return array<string,string>
     */
    private function evaluate(array $value): array
    {
        $refusal = $this->preSignatureRefusal($value) ?? $this->signatureRefusal($value);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($value['relationship'] === 'same_node') {
            return ['action' => 'exclude', 'reason' => 'self_node', 'trust_weight' => '0.0'];
        }
        if ($value['relationship'] === 'same_operator') {
            return ['action' => 'exclude', 'reason' => 'self_operator', 'trust_weight' => '0.0'];
        }

        return ['action' => 'accept', 'reason' => 'cosignature_verified', 'trust_weight' => '1.0'];
    }

    /** @param array<string,string> $value
     * @return array<string,string>|null
     */
    private function preSignatureRefusal(array $value): ?array
    {
        if ($value['binding'] !== 'match') {
            $reasons = [
                'response_hash_mismatch' => 'response_hash_mismatch',
                'cost_mismatch' => 'cost_mismatch',
                'task_node_intent_mismatch' => 'receipt_binding_mismatch',
            ];

            return ['action' => 'refuse_signing', 'reason' => $reasons[$value['binding']], 'trust_weight' => '0.0'];
        }
        if ($value['consumer_key'] === 'revoked') {
            return ['action' => 'reject', 'reason' => 'consumer_key_revoked', 'trust_weight' => '0.0'];
        }
        if ($value['consumer_key'] === 'rotated_outside_validity') {
            return ['action' => 'reject', 'reason' => 'consumer_key_not_valid_at_completion', 'trust_weight' => '0.0'];
        }
        if ($value['time'] !== 'valid') {
            return ['action' => 'reject', 'reason' => 'receipt_expired', 'trust_weight' => '0.0'];
        }
        if ($value['nonce'] !== 'fresh') {
            return ['action' => 'reject', 'reason' => 'dispatch_nonce_replayed', 'trust_weight' => '0.0'];
        }

        return null;
    }

    /** @param array<string,string> $value
     * @return array<string,string>|null
     */
    private function signatureRefusal(array $value): ?array
    {
        if ($value['provider_signature'] !== 'valid') {
            return ['action' => 'reject', 'reason' => 'provider_signature_invalid', 'trust_weight' => '0.0'];
        }
        if ($value['consumer_signature'] !== 'valid') {
            if ($value['consumer_signature'] === 'missing' && $value['mode'] === 'optional') {
                return ['action' => 'accept_legacy', 'reason' => 'consumer_signature_missing_optional', 'trust_weight' => '0.0'];
            }
            $reason = $value['consumer_signature'] === 'missing' ? 'consumer_signature_required' : 'consumer_signature_invalid';

            return ['action' => 'reject', 'reason' => $reason, 'trust_weight' => '0.0'];
        }

        return null;
    }
}
