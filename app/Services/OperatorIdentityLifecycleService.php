<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Accountless operator identity rotation/revocation (#618).
 *
 * A normal dual-key rotation preserves the operator's directory-earned
 * continuity: node bindings, founder recognition and node-backed credit/reputation
 * state move atomically to the successor.  The previous key is retained only as
 * private historical evidence and is no longer eligible to make new claims.
 */
class OperatorIdentityLifecycleService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ROTATED = 'rotated';

    public const STATUS_REVOKED = 'revoked';

    public function __construct(private PolicyKeyLifecycleService $policyKeys) {}

    /** @return array{operator:Operator,linked_nodes:int,rotation_epoch:int} */
    public function rotate(Operator $old, string $newOperatorPub, ?int $requestedEpoch = null, string $reasonClass = 'operator_rotation'): array
    {
        $this->assertActive($old);
        $this->assertPublicKey($newOperatorPub, 'new_operator_pub');
        if (hash_equals($old->operator_pubkey, $newOperatorPub)) {
            throw new InvalidArgumentException('The successor operator key must differ from the current key.');
        }

        return DB::transaction(function () use ($old, $newOperatorPub, $requestedEpoch, $reasonClass): array {
            $old = Operator::query()->lockForUpdate()->findOrFail($old->id);
            $this->assertActive($old);
            if (Operator::query()->where('operator_pubkey', $newOperatorPub)->exists()) {
                throw new RuntimeException('The successor operator key is already registered.');
            }

            $epoch = max((int) ($old->rotation_epoch ?? 0) + 1, (int) ($requestedEpoch ?? 0));
            $successor = Operator::create([
                'operator_pubkey' => $newOperatorPub,
                'identity_status' => self::STATUS_ACTIVE,
                'display_name' => $old->display_name,
                'attested_created_at' => $old->attested_created_at,
                'operator_integrity_hash' => $old->operator_integrity_hash,
                'first_seen_ms' => $old->first_seen_ms,
                'ordinal' => $old->ordinal,
                'tier' => $old->tier,
                'badge' => $old->badge,
                'provenance' => $old->provenance,
                'terms_version' => $old->terms_version,
                'terms_accepted_at' => $old->terms_accepted_at,
                'dpa_version' => $old->dpa_version,
                'dpa_accepted_at' => $old->dpa_accepted_at,
                'acceptance_method' => $old->terms_accepted_at || $old->dpa_accepted_at
                    ? 'operator_key_rotation' : null,
                'acceptance_nonce_sha256' => null,
            ]);

            $linkedNodes = Node::query()->where('operator_pubkey', $old->operator_pubkey)->count();
            Node::query()->where('operator_pubkey', $old->operator_pubkey)->update([
                'operator_pubkey' => $newOperatorPub,
                'operator_verified' => true,
                'updated_at' => now(),
            ]);
            $old->update([
                'identity_status' => self::STATUS_ROTATED,
                'successor_operator_pubkey_sha256' => self::keySha($newOperatorPub),
                'rotation_epoch' => $epoch,
                'identity_reason_class' => $this->normalizeReasonClass($reasonClass),
            ]);
            // A policy manifest signed with the old operator key must no longer
            // claim an active operator binding. Independent policy keys remain
            // separate lifecycle objects and are not touched here.
            $this->policyKeys->markSuperseded(
                $this->rawKeySha($old->operator_pubkey),
                $this->rawKeySha($newOperatorPub),
                'operator_rotation',
                $epoch,
            );

            return ['operator' => $successor, 'linked_nodes' => $linkedNodes, 'rotation_epoch' => $epoch];
        });
    }

    /** @return array{linked_nodes:int,revoked_at:string} */
    public function revoke(Operator $operator, string $reasonClass = 'operator_request'): array
    {
        $this->assertActive($operator);

        return DB::transaction(function () use ($operator, $reasonClass): array {
            $operator = Operator::query()->lockForUpdate()->findOrFail($operator->id);
            $this->assertActive($operator);
            $revokedAt = now();
            $linkedNodes = Node::query()->where('operator_pubkey', $operator->operator_pubkey)->count();
            Node::query()->where('operator_pubkey', $operator->operator_pubkey)->update([
                'operator_verified' => false,
                'operator_trust_tier' => null,
                'updated_at' => $revokedAt,
            ]);
            $operator->update([
                'identity_status' => self::STATUS_REVOKED,
                'identity_revoked_at' => $revokedAt,
                'identity_reason_class' => $this->normalizeReasonClass($reasonClass),
            ]);
            $this->policyKeys->markRevoked(
                $this->rawKeySha($operator->operator_pubkey),
                $revokedAt,
                'operator_revocation',
                $operator->rotation_epoch,
            );

            return ['linked_nodes' => $linkedNodes, 'revoked_at' => $revokedAt->toIso8601String()];
        });
    }

    public function assertActive(Operator $operator): void
    {
        if (($operator->identity_status ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) {
            throw new RuntimeException('The operator identity is no longer active.');
        }
    }

    public static function keySha(string $operatorPub): string
    {
        return hash('sha256', $operatorPub);
    }

    private function rawKeySha(string $operatorPub): string
    {
        $raw = base64_decode($operatorPub, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new InvalidArgumentException('Invalid stored operator public key.');
        }

        return hash('sha256', $raw);
    }

    private function assertPublicKey(string $value, string $field): void
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new InvalidArgumentException("Invalid {$field}; expected base64 Ed25519 public key.");
        }
    }

    private function normalizeReasonClass(string $value): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
            throw new InvalidArgumentException('Invalid identity reason class.');
        }

        return $value;
    }
}
