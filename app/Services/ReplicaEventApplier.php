<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
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
 *   REGISTER, DEREGISTER, CREDIT_AWARD, REPLICA_REGISTERED, REPUTATION_DECAY
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
     */
    public function apply(array $event, ?string $verifyKey = null): array
    {
        $type = $event['event_type'] ?? null;
        $nodeId = $event['node_id'] ?? null;
        $payload = $event['payload'] ?? [];
        $eventId = $event['event_id'] ?? null;

        if (! $type || ! $eventId) {
            return $this->result(self::RESULT_REJECTED, 'missing event_type or event_id', $event);
        }

        if ($verifyKey !== null) {
            $sig = $event['sig'] ?? null;
            if ($sig !== null && ! $this->verifySig($event, $verifyKey)) {
                return $this->result(self::RESULT_REJECTED, 'Ed25519 signature verification failed', ['event_id' => $eventId]);
            }
        }

        return match ($type) {
            'REGISTER' => $this->applyRegister($nodeId, $payload, $eventId),
            'DEREGISTER' => $this->applyDeregister($nodeId, $eventId),
            'CREDIT_AWARD' => $this->applyCreditAward($nodeId, $payload, $eventId),
            'REPLICA_REGISTERED' => $this->applyReplicaRegistered($nodeId, $payload, $eventId),
            'REPUTATION_DECAY' => $this->applyReputationDecay($nodeId, $payload, $eventId),
            'OPERATOR_OBSERVED' => $this->applyOperatorObserved($nodeId, $payload, $eventId),
            default => $this->result(self::RESULT_SKIPPED, "unsupported event_type: {$type}", $event),
        };
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
            'available' => true,
            'last_seen' => Carbon::now(),
        ], fn ($v) => $v !== null));

        Node::updateOrCreate(['id' => $nodeId], $attrs);

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
            ]
        );

        return $this->result(self::RESULT_APPLIED, 'REPLICA_REGISTERED applied', [
            'event_id' => $eventId, 'replica_id' => $replicaId, 'did' => $p['did'],
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
     * Verify Ed25519 signature per S.13 §3.4. Signing input is:
     *   SHA256_bin(event_id:event_type:seq:ts_ms:SHA256_hex(canonical_json(payload)))
     * Sig is hex-encoded (128 chars). Returns true if valid, false otherwise.
     *
     * MUST match NodeEventLogger::sign() byte-for-byte — divergence here is a
     * silent acceptance bug.
     */
    private function verifySig(array $event, string $pubKey): bool
    {
        $sigHex = (string) ($event['sig'] ?? '');
        if (strlen($sigHex) !== 128 || ! ctype_xdigit($sigHex)) {
            return false;
        }
        $payloadHash = hash('sha256', $this->canonicalJson($event['payload'] ?? []));
        $signingInput = implode(':', [
            (string) ($event['event_id'] ?? ''),
            (string) ($event['event_type'] ?? ''),
            (string) ($event['seq'] ?? ''),
            (string) ($event['ts_ms'] ?? ''),
            $payloadHash,
        ]);
        $message = hash('sha256', $signingInput, true);
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

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
