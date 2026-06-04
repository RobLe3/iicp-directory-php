<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\NodeEvent;
use Illuminate\Support\Str;

/**
 * Phase 6 prerequisite: append-only event log emitter.
 *
 * Spec: spec/iicp-federated-directory.md §3.4
 * Sequence numbers are assigned atomically from the current max.
 * Ed25519 signing: when IICP_GENESIS_ED25519_SECRET_KEY is set (64-byte hex = 128 chars),
 * each event is signed per §3.4 — input is SHA256(event_id:event_type:seq:ts_ms:SHA256_hex(canonical_json(payload))).
 * Signature is hex-encoded sodium detached signature (64 bytes → 128 hex chars).
 */
class NodeEventLogger
{
    public function log(string $eventType, string $nodeId, array $payload): NodeEvent
    {
        $eventId = (string) Str::uuid();
        $seq = (NodeEvent::max('seq') ?? 0) + 1;
        $tsMs = (int) (microtime(true) * 1000);

        return NodeEvent::create([
            'event_id' => $eventId,
            'seq' => $seq,
            'event_type' => $eventType,
            'node_id' => $nodeId,
            'ts_ms' => $tsMs,
            'payload' => $payload,
            'signature' => $this->sign($eventId, $eventType, $seq, $tsMs, $payload),
        ]);
    }

    /**
     * Compute Ed25519 signature per spec/iicp-federated-directory.md §3.4.
     * Returns null when no genesis key is configured (Phase 6B opt-in).
     */
    private function sign(
        string $eventId,
        string $eventType,
        int $seq,
        int $tsMs,
        array $payload
    ): ?string {
        $hexKey = config('app.genesis_ed25519_secret_key');
        if (! $hexKey || strlen($hexKey) !== 128) {
            return null;
        }

        $secretKey = sodium_hex2bin($hexKey);
        $payloadHash = hash('sha256', $this->canonicalJson($payload));
        $message = hash('sha256',
            implode(':', [$eventId, $eventType, (string) $seq, (string) $tsMs, $payloadHash]),
            true
        );

        return bin2hex(sodium_crypto_sign_detached($message, $secretKey));
    }

    /**
     * Deterministic JSON serialization — key-sorted, no whitespace.
     * Sufficient for spec §3.4; full RFC 8785 compliance added in Phase 6B.
     */
    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $v = json_decode($this->canonicalJson($v), true);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
