<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\NodeHealthObservation;
use App\Models\Replica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Apply a federated event from the seed's /v1/events stream to local
 * state. Used by `iicp:replica-start` (the replica-mode artisan).
 *
 * Idempotent per (event_id) — re-applying a previously-seen event is a
 * no-op. Caller (ReplicaStartCommand) tracks last applied event_id.
 *
 * Closed event-type list per S.13 v0.3.0 §5.1 / DIR-FED-16:
 *   REGISTER, DEREGISTER, CREDIT_AWARD, REPLICA_REGISTERED,
 *   REPLICA_DEREGISTERED, REPUTATION_DECAY
 *
 * NOT in scope here (P6-1.3b-iii):
 *   - Ed25519 signature verification (apply trusts the caller fetched
 *     events over an authenticated channel; sig verify is the next slice)
 *   - DID document resolution / public key caching
 *   - GET /v1/snapshot bootstrap
 *
 * Charter: P6-1.3b-i (the "data-application" half of P6-1.3b).
 */
class ReplicaEventApplier
{
    public const RESULT_APPLIED = 'applied';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_REJECTED = 'rejected';

    /**
     * @param  array  $event  Event row from /v1/events
     * @param  string|null  $verifyKey  Raw 32-byte Ed25519 public key, or null to skip verification
     *                                  (P6-1.3b-iv — required to reject tampered events; null means
     *                                  the replica is running in "trust-poll-source" mode, e.g. during
     *                                  bootstrap before the DID document is fetched).
     * @param  string|null  $expectedPrevHash  #458: the prev_hash the event MUST carry to chain
     *                                         onto the predecessor (= SHA256_hex of the last
     *                                         applied signature). Null skips the continuity check
     *                                         (e.g. the first event of a drain, where the
     *                                         predecessor signature is unknown).
     */
    public function apply(array $event, ?string $verifyKey = null, ?string $expectedPrevHash = null): array
    {
        $type = $event['event_type'] ?? null;
        $nodeId = $event['node_id'] ?? null;
        $payload = $event['payload'] ?? [];
        $eventId = $event['event_id'] ?? null;

        if (! $type || ! $eventId) {
            return $this->result(self::RESULT_REJECTED, 'missing event_type or event_id', $event);
        }
        $serviceId = $event['service_id'] ?? null;
        if ($serviceId !== null
            && (! is_string($serviceId) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $serviceId))) {
            return $this->result(self::RESULT_REJECTED, 'invalid service_id', ['event_id' => $eventId]);
        }

        $verificationRequired = $this->verificationRequired();
        if ($verificationRequired && $verifyKey === null) {
            return $this->result(self::RESULT_REJECTED, 'seed verification key unavailable', ['event_id' => $eventId]);
        }

        if ($verifyKey !== null) {
            $sig = $event['sig'] ?? null;
            if ($sig === null && $verificationRequired) {
                return $this->result(self::RESULT_REJECTED, 'missing signature', ['event_id' => $eventId]);
            }
            if ($sig !== null) {
                // #458: hash-chain continuity — reject an event whose prev_hash does not link
                // to the predecessor we just applied, even if its own signature is valid
                // (defends against a seed deleting/inserting/reordering its log).
                if ($expectedPrevHash !== null
                    && (string) ($event['prev_hash'] ?? NodeEventLogger::GENESIS_ROOT) !== $expectedPrevHash) {
                    return $this->result(self::RESULT_REJECTED, 'hash-chain continuity broken', ['event_id' => $eventId]);
                }
                if (! $this->verifySig($event, $verifyKey)) {
                    return $this->result(self::RESULT_REJECTED, 'Ed25519 signature verification failed', ['event_id' => $eventId]);
                }
            }
        }

        return match ($type) {
            'REGISTER' => $this->applyRegister($nodeId, $payload, $eventId),
            'DEREGISTER' => $this->applyDeregister($nodeId, $eventId),
            'CREDIT_AWARD' => $this->applyCreditAward($nodeId, $payload, $eventId),
            'REPLICA_REGISTERED' => $this->applyReplicaRegistered($nodeId, $payload, $eventId),
            'REPLICA_DEREGISTERED' => $this->applyReplicaDeregistered($nodeId, $payload, $eventId),
            'REPUTATION_DECAY' => $this->applyReputationDecay($nodeId, $payload, $eventId),
            'OPERATOR_OBSERVED' => $this->applyOperatorObserved($nodeId, $payload, $eventId),
            'HEALTH' => $this->applyHealth($nodeId, $payload, $eventId),
            default => $this->result(self::RESULT_SKIPPED, "unsupported event_type: {$type}", $event),
        };
    }

    private function verificationRequired(): bool
    {
        return config('app.env') === 'production'
            || ! config('iicp.replica.dev_allow_unsigned_events', false);
    }

    /**
     * OPERATOR_OBSERVED — record-only. Replicas log the observation for the
     * audit trail but MUST NOT mutate node state (spec §5.4 OPERATOR_OBSERVED).
     * The trust auditor on the seed is the sole authority on score/tier impact.
     */
    private function applyOperatorObserved(?string $nodeId, array $p, string $eventId): array
    {
        $obsType = $p['observation_type'] ?? 'unknown';
        Log::info('iicp.replica.operator_observed', [
            'event_id' => $eventId,
            'node_id' => $nodeId,
            'observation_type' => $obsType,
            'severity' => $p['severity'] ?? 'info',
            'evidence' => $p['evidence'] ?? null,
            'observed_at' => $p['observed_at'] ?? null,
        ]);

        return $this->result(self::RESULT_APPLIED, "OPERATOR_OBSERVED recorded ({$obsType})", [
            'event_id' => $eventId, 'node_id' => $nodeId, 'observation_type' => $obsType,
        ]);
    }

    /**
     * HEALTH (ADR-048 / #374) — store the per-node, per-evaluator health snapshot so the
     * federation-wide mesh_health read can resolve each node by majority-vote across
     * evaluators. One row per (node_id, evaluator_did); the latest by `evaluated_at_ms`
     * wins (staleness rule — an out-of-order replay of an older snapshot is a no-op).
     *
     * Record-only with respect to node state: HEALTH never mutates the Node row (the
     * canonical aggregate is recomputed on read), mirroring OPERATOR_OBSERVED's discipline.
     */
    private function applyHealth(?string $nodeId, array $p, string $eventId): array
    {
        $evaluator = $p['evaluator_did'] ?? null;
        if (! $nodeId || ! $evaluator || ! array_key_exists('score', $p)) {
            return $this->result(self::RESULT_REJECTED, 'HEALTH missing node_id, evaluator_did, or score', ['event_id' => $eventId]);
        }

        $evaluatedAtMs = $this->healthEvaluatedAtMs($p);

        $existing = NodeHealthObservation::where('node_id', $nodeId)
            ->where('evaluator_did', $evaluator)
            ->first();

        // Staleness rule: never let an older snapshot overwrite a newer one.
        if ($existing !== null && $existing->evaluated_at_ms >= $evaluatedAtMs) {
            return $this->result(self::RESULT_SKIPPED, 'HEALTH older than stored snapshot', [
                'event_id' => $eventId, 'node_id' => $nodeId, 'evaluator_did' => $evaluator,
            ]);
        }

        NodeHealthObservation::updateOrCreate(
            ['node_id' => $nodeId, 'evaluator_did' => $evaluator],
            [
                'score' => (float) $p['score'],
                'label' => $p['label'] ?? null,
                'components' => $p['components'] ?? null,
                'evaluated_at_ms' => $evaluatedAtMs,
                'event_id' => $eventId,
            ]
        );

        return $this->result(self::RESULT_APPLIED, 'HEALTH applied', [
            'event_id' => $eventId, 'node_id' => $nodeId, 'evaluator_did' => $evaluator,
        ]);
    }

    /**
     * Normalize the producer's evaluation time to unix ms. Accepts an explicit
     * `evaluated_at_ms` (preferred — monotonic, no parse), or an ISO-8601
     * `evaluated_at` string (the ADR-044 forNode vector shape). Falls back to 0 so a
     * snapshot with no time is always treated as the oldest.
     */
    private function healthEvaluatedAtMs(array $p): int
    {
        if (isset($p['evaluated_at_ms']) && is_numeric($p['evaluated_at_ms'])) {
            return (int) $p['evaluated_at_ms'];
        }
        if (! empty($p['evaluated_at'])) {
            try {
                return (int) (Carbon::parse($p['evaluated_at'])->valueOf());
            } catch (\Throwable $e) {
                return 0;
            }
        }

        return 0;
    }

    private function applyRegister(?string $nodeId, array $p, string $eventId): array
    {
        if (! $nodeId || empty($p['endpoint'])) {
            return $this->result(self::RESULT_REJECTED, 'REGISTER missing node_id or endpoint', ['event_id' => $eventId]);
        }
        // Mirror-mode defaults for seed-only fields the schema requires NOT NULL.
        // A replica never issues or validates node tokens (writes are blocked at the
        // controller layer in P6-1.3b-ii); the placeholder is a safety value.
        $defaults = [
            'node_token_hash' => '',
            'region' => '',
            'max_concurrent' => 1,
            'tokens_per_min' => 0,
        ];
        $attrs = array_merge($defaults, array_filter([
            'endpoint' => $p['endpoint'] ?? null,
            'region' => $p['region'] ?? null,
            'allow_remote_inference' => $p['cip_policy']['allow_remote_inference'] ?? null,
            'allow_tool_execution' => $p['cip_policy']['allow_tool_execution'] ?? null,
            'allow_file_access' => $p['cip_policy']['allow_file_access'] ?? null,
            'credit_cost_multiplier' => $p['pricing']['credit_cost_multiplier'] ?? null,
            'pricing_model' => $p['pricing']['pricing_model'] ?? null,
            'attested' => $p['pricing']['attested'] ?? null,
            // #439 — mirror reachability so the replica's /v1/discover surfaces the node
            // (discover filters public_reachable=true OR exposure_mode in the relay set).
            'public_reachable' => $p['public_reachable'] ?? null,
            'exposure_mode' => $p['exposure_mode'] ?? null,
            'available' => true,
            'last_seen' => Carbon::now(),
        ], fn ($v) => $v !== null));

        $node = Node::updateOrCreate(['id' => $nodeId], $attrs);

        // #438 — rebuild capability rows from the event so the replica can serve
        // /v1/discover for this node (discover requires a capability matching the
        // intent). Idempotent: the capability set is replaced wholesale per REGISTER.
        if (! empty($p['capabilities']) && is_array($p['capabilities'])) {
            $node->capabilities()->delete();
            foreach ($p['capabilities'] as $cap) {
                if (empty($cap['intent'])) {
                    continue;
                }
                $node->capabilities()->create([
                    'intent' => $cap['intent'],
                    'models' => $cap['models'] ?? [],
                    'max_tokens' => $cap['max_tokens'] ?? 1,
                    'input_modalities' => $cap['input_modalities'] ?? ['text'],
                    'supported_profiles' => $cap['supported_profiles'] ?? [],
                ]);
            }
        }

        return $this->result(self::RESULT_APPLIED, 'REGISTER applied', ['event_id' => $eventId, 'node_id' => $nodeId]);
    }

    private function applyDeregister(?string $nodeId, string $eventId): array
    {
        if (! $nodeId) {
            return $this->result(self::RESULT_REJECTED, 'DEREGISTER missing node_id', ['event_id' => $eventId]);
        }
        $node = Node::find($nodeId);
        if (! $node) {
            return $this->result(self::RESULT_SKIPPED, 'DEREGISTER for unknown node', ['event_id' => $eventId, 'node_id' => $nodeId]);
        }
        $node->update(['available' => false, 'status' => 'deregistered']);

        return $this->result(self::RESULT_APPLIED, 'DEREGISTER applied', ['event_id' => $eventId, 'node_id' => $nodeId]);
    }

    private function applyCreditAward(?string $nodeId, array $p, string $eventId): array
    {
        if (! $nodeId || ! array_key_exists('new_balance', $p)) {
            return $this->result(self::RESULT_REJECTED, 'CREDIT_AWARD missing node_id or new_balance', ['event_id' => $eventId]);
        }
        $node = Node::find($nodeId);
        if (! $node) {
            return $this->result(self::RESULT_SKIPPED, 'CREDIT_AWARD for unknown node', ['event_id' => $eventId, 'node_id' => $nodeId]);
        }
        $node->update(['credit_balance' => $p['new_balance']]);

        return $this->result(self::RESULT_APPLIED, 'CREDIT_AWARD applied', [
            'event_id' => $eventId, 'node_id' => $nodeId, 'new_balance' => $p['new_balance'],
        ]);
    }

    private function applyReplicaRegistered(?string $replicaId, array $p, string $eventId): array
    {
        if (! $replicaId || empty($p['did']) || empty($p['endpoint'])) {
            return $this->result(self::RESULT_REJECTED, 'REPLICA_REGISTERED missing replica_id/did/endpoint', ['event_id' => $eventId]);
        }
        Replica::updateOrCreate(
            ['did' => $p['did']],
            [
                'replica_id' => $replicaId,
                'endpoint' => $p['endpoint'],
                'trust_tier' => $p['trust_tier'] ?? 'unverified',
                'replica_token_hash' => '',
                'expires_at' => Carbon::now()->addDays(90),
                'last_seen_at' => Carbon::now(),
                'status' => Replica::STATUS_ACTIVE,
            ]
        );

        return $this->result(self::RESULT_APPLIED, 'REPLICA_REGISTERED applied', [
            'event_id' => $eventId, 'replica_id' => $replicaId, 'did' => $p['did'],
        ]);
    }

    private function applyReplicaDeregistered(?string $replicaId, array $p, string $eventId): array
    {
        if (! $replicaId) {
            return $this->result(self::RESULT_REJECTED, 'REPLICA_DEREGISTERED missing replica_id', ['event_id' => $eventId]);
        }
        $replica = Replica::where('replica_id', $replicaId)->first();
        if (! $replica) {
            return $this->result(self::RESULT_SKIPPED, 'REPLICA_DEREGISTERED for unknown replica', ['event_id' => $eventId]);
        }
        $replica->update([
            'status' => Replica::STATUS_DECOMMISSIONED,
            'expires_at' => Carbon::now(),
            'replica_token_hash' => hash('sha256', random_bytes(32)),
        ]);

        return $this->result(self::RESULT_APPLIED, 'REPLICA_DEREGISTERED applied', [
            'event_id' => $eventId, 'replica_id' => $replicaId, 'did' => $p['did'] ?? $replica->did,
        ]);
    }

    private function applyReputationDecay(?string $nodeId, array $p, string $eventId): array
    {
        if (! $nodeId || ! array_key_exists('new_score', $p)) {
            return $this->result(self::RESULT_REJECTED, 'REPUTATION_DECAY missing node_id or new_score', ['event_id' => $eventId]);
        }
        $node = Node::find($nodeId);
        if (! $node) {
            return $this->result(self::RESULT_SKIPPED, 'REPUTATION_DECAY for unknown node', ['event_id' => $eventId, 'node_id' => $nodeId]);
        }
        $node->update(['reputation_score' => $p['new_score']]);

        return $this->result(self::RESULT_APPLIED, 'REPUTATION_DECAY applied', [
            'event_id' => $eventId, 'node_id' => $nodeId, 'new_score' => $p['new_score'],
        ]);
    }

    private function result(string $status, string $detail, array $meta = []): array
    {
        return ['status' => $status, 'detail' => $detail, 'meta' => $meta];
    }

    /**
     * Verify Ed25519 signature per S.13 §3.4 (#458: prev_hash bound into the input). Input:
     *   SHA256_bin(event_id:event_type:seq:ts_ms:SHA256_hex(canonical_json(payload)):prev_hash)
     * Sig is hex-encoded (128 chars). Returns true if valid, false otherwise.
     *
     * MUST match NodeEventLogger::sign() byte-for-byte — divergence here is a
     * silent acceptance bug. The event's own `prev_hash` field is used (legacy events
     * without one fall back to GENESIS_ROOT); the in-order continuity check that the
     * prev_hash actually links to the predecessor is enforced separately in apply().
     */
    private function verifySig(array $event, string $pubKey): bool
    {
        $sigHex = (string) ($event['sig'] ?? '');
        if (strlen($sigHex) !== 128 || ! ctype_xdigit($sigHex)) {
            return false;
        }
        $payloadHash = hash('sha256', $this->canonicalJson($event['payload'] ?? []));
        $fields = [
            (string) ($event['event_id'] ?? ''),
            (string) ($event['event_type'] ?? ''),
            (string) ($event['seq'] ?? ''),
            (string) ($event['ts_ms'] ?? ''),
            $payloadHash,
            (string) ($event['prev_hash'] ?? NodeEventLogger::GENESIS_ROOT),
        ];
        $serviceId = $event['service_id'] ?? null;
        if ($serviceId !== null) {
            if (! is_string($serviceId) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $serviceId)) {
                return false;
            }
            array_unshift($fields, 'iicp-event-v2', $serviceId);
        }
        $message = hash('sha256', implode(':', $fields), true);
        try {
            return sodium_crypto_sign_verify_detached(sodium_hex2bin($sigHex), $message, $pubKey);
        } catch (\SodiumException $e) {
            return false;
        }
    }

    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $v = json_decode($this->canonicalJson($v), true);
            }
        }

        // NO JSON_PRESERVE_ZERO_FRACTION — must match NodeEventLogger::canonicalJson()
        // byte-for-byte. The flag made a whole-number float sign as "1.0" but verify as
        // "1" after the wire round-trip → verification failed (DIR-FED-01). See the note
        // on NodeEventLogger::canonicalJson and NodeEventLoggerTest::test_signature_verifies_after_wire_roundtrip.
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
