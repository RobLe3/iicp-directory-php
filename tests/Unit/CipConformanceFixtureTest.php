<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CipConformanceFixtureTest extends TestCase
{
    private function evaluate(array $v): array
    {
        $error = $this->validateEnvelope($v);
        if ($error !== null) {
            return $error;
        }
        $hardGate = $this->hardGate($v);
        if ($hardGate !== null) {
            return $hardGate;
        }

        return $this->evaluateMode($v);
    }

    private function validateEnvelope(array $v): ?array
    {
        $replicas = $v['replicas'] ?? null;
        if (! is_int($replicas) || $replicas < 1 || $replicas > 10) {
            return ['envelope' => 'reject', 'execution' => 'reject', 'error' => 'IICP-E028'];
        }
        $quorum = $v['quorum'] ?? null;
        if ($quorum !== null && (! is_int($quorum) || $quorum < 1 || $quorum > $replicas)) {
            return ['envelope' => 'reject', 'execution' => 'reject', 'error' => 'IICP-E028'];
        }

        return null;
    }

    private function hardGate(array $v): ?array
    {
        $out = ['envelope' => 'accept'];
        if (($v['sensitivity'] ?? null) === 'high' && ! ($v['send_sensitive_prompts'] ?? false)) {
            return $out + ['execution' => 'local', 'remote_eligible' => false];
        }
        $intent = (string) ($v['intent'] ?? '');
        if (str_starts_with($intent, 'urn:iicp:intent:mcp:') || str_starts_with($intent, 'urn:iicp:intent:tool:')) {
            return $out + ['execution' => 'reject', 'remote_eligible' => false];
        }

        return null;
    }

    private function evaluateMode(array $v): array
    {
        $replicas = $v['replicas'];
        $quorum = $v['quorum'] ?? null;
        $out = ['envelope' => 'accept'];
        $operatorMax = min(10, max(1, (int) ($v['operator_max_replicas'] ?? 10)));

        return match ($v['policy'] ?? null) {
            null => $replicas === 1 ? $out + ['execution' => 'accept', 'quorum' => null] : $out + ['execution' => 'reject', 'error' => 'IICP-E028'],
            'best_of_n' => $replicas >= 2 && $replicas <= $operatorMax ? $out + ['execution' => 'accept', 'quorum' => null] : $out + ['execution' => 'reject', 'error' => 'IICP-E028'],
            'majority_vote' => $replicas < 3 || $replicas % 2 === 0
                ? $out + ['execution' => 'reject', 'error' => 'IICP-E025']
                : ($replicas > $operatorMax ? $out + ['execution' => 'reject', 'error' => 'IICP-E028'] : $out + ['execution' => 'accept', 'quorum' => $quorum ?? intdiv($replicas, 2) + 1]),
            'map_reduce' => in_array('map_reduce', $v['implemented_modes'] ?? [], true)
                ? $out + ['execution' => 'accept', 'quorum' => $quorum]
                : $out + ['execution' => 'unsupported', 'advertise' => false],
            default => $out + ['execution' => 'reject', 'error' => 'IICP-E028'],
        };
    }

    public function test_canonical_cip_fixture(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/parity/cip-conformance-v0.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $this->assertSame($case['expected'], $this->evaluate($case['input']), $case['name']);
        }
        $v = $fixture['canonical_receipt_vectors'][0];
        $this->assertSame($v['response_hash'], hash('sha256', $v['canonical_result_json']));
        $this->assertSame($v['signature_hmac_sha256'], hash_hmac('sha256', $v['canonical_message'], $v['hmac_key_utf8']));
    }
}
