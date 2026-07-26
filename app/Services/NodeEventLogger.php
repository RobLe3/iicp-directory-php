<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\NodeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 6 prerequisite: append-only event log emitter.
 *
 * Spec: spec/iicp-federated-directory.md §3.4
 * Sequence numbers and predecessor signatures are assigned atomically from a
 * durable, row-locked chain head.
 * Ed25519 signing: when IICP_GENESIS_ED25519_SECRET_KEY is set (64-byte hex = 128 chars),
 * each event is signed per §3.4 — input is SHA256(event_id:event_type:seq:ts_ms:SHA256_hex(canonical_json(payload))).
 * Signature is hex-encoded sodium detached signature (64 bytes → 128 hex chars).
 */
class NodeEventLogger
{
    private const CHAIN_ID = 'genesis';

    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Hash-chain genesis root (#458): SHA256_hex("iicp:dir:event-log:genesis:v1"). The
     * prev_hash of the first signed event (or the first after an unsigned span) is this.
     * MUST equal iicp-directory-rs federation::GENESIS_ROOT byte-for-byte.
     */
    public const GENESIS_ROOT = 'c44802bedf3e63b5a3f1634c5d19263634f92f26dd15401b09b06dd53a80cf9d';

    public function log(string $eventType, string $nodeId, array $payload, ?string $serviceId = null): NodeEvent
    {
        if ($serviceId !== null && ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $serviceId)) {
            throw new \InvalidArgumentException('service_id must be a lowercase portable identifier');
        }

        return DB::transaction(function () use ($eventType, $nodeId, $payload, $serviceId): NodeEvent {
            $head = DB::table('node_event_chain_heads')
                ->where('chain_id', self::CHAIN_ID)
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                throw new \LogicException('Event chain head is missing; run the database migrations.');
            }

            $eventId = (string) Str::uuid();
            $seq = (int) $head->last_seq + 1;
            $tsMs = (int) (microtime(true) * 1000);
            $prevHash = $this->prevHash($head->last_signature);
            $signature = $this->sign(
                $eventId,
                $eventType,
                $seq,
                $tsMs,
                $payload,
                $prevHash,
                $serviceId,
            );

            $event = NodeEvent::create([
                'event_id' => $eventId,
                'seq' => $seq,
                'event_type' => $eventType,
                'service_id' => $serviceId,
                'node_id' => $nodeId,
                'ts_ms' => $tsMs,
                'payload' => $payload,
                'prev_hash' => $prevHash,
                'signature' => $signature,
            ]);

            DB::table('node_event_chain_heads')
                ->where('chain_id', self::CHAIN_ID)
                ->update([
                    'last_seq' => $seq,
                    'last_signature' => $signature,
                    'updated_at' => now(),
                ]);

            return $event;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * prev_hash for an event whose predecessor's signature is $prevSig (#458): the
     * SHA-256 (hex) of that 128-hex signature's ASCII bytes; GENESIS_ROOT when there is no
     * signed predecessor. Chaining on the signature (a hex string identical on the wire
     * across implementations) keeps prev_hash reproducible regardless of number canonicalization.
     */
    public function prevHash(?string $prevSig): string
    {
        return ($prevSig === null || $prevSig === '')
            ? self::GENESIS_ROOT
            : hash('sha256', $prevSig);
    }

    /**
     * Compute Ed25519 signature per spec/iicp-federated-directory.md §3.4 (#458: prev_hash
     * is bound into the signing input, making the log a tamper-evident chain).
     * Returns null when no genesis key is configured (Phase 6B opt-in).
     */
    private function sign(
        string $eventId,
        string $eventType,
        int $seq,
        int $tsMs,
        array $payload,
        string $prevHash,
        ?string $serviceId = null
    ): ?string {
        $hexKey = config('app.genesis_ed25519_secret_key');
        if (! $hexKey || strlen($hexKey) !== 128) {
            return null;
        }

        $secretKey = sodium_hex2bin($hexKey);
        $payloadHash = hash('sha256', self::canonicalJson($payload));
        $fields = [$eventId, $eventType, (string) $seq, (string) $tsMs, $payloadHash, $prevHash];
        if ($serviceId !== null) {
            // V2 is selected only by an explicitly present service_id. The
            // domain prefix prevents a V2 event from colliding with a legacy
            // signing input. Colons are forbidden by service_id validation.
            array_unshift($fields, 'iicp-event-v2', $serviceId);
        }
        $message = hash('sha256', implode(':', $fields), true);

        return bin2hex(sodium_crypto_sign_detached($message, $secretKey));
    }

    /**
     * Deterministic JSON serialization — key-sorted, no whitespace.
     * Sufficient for spec §3.4; full RFC 8785 compliance added in Phase 6B.
     * Public + static since #508: the compliance-attestation endpoint signs with
     * the same canonical form so external verifiers need ONE canonicalization rule.
     *
     * NO JSON_PRESERVE_ZERO_FRACTION: a whole-number float (e.g. credit_cost_multiplier
     * 1.0) loses its fraction on the storage/wire round-trip (DB JSON column + the
     * /api/v1/events json_encode + json_decode → integer 1), so signing it as "1.0"
     * made the signature UNVERIFIABLE from the transmitted payload — by the seed itself
     * and by any replica (DIR-FED-01). Dropping the flag serializes 1.0 and 1 alike as
     * "1" (RFC 8785 / JCS behaviour), so the signed canonical form is reproducible from
     * the wire and matches the Rust replica's serde_json canonicalisation. Regression:
     * tests/Feature/NodeEventLoggerSignatureTest::test_signature_verifies_after_wire_roundtrip.
     */
    public static function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $v = json_decode(self::canonicalJson($v), true);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
