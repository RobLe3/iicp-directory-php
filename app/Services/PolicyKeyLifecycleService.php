<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\PolicyKeyLifecycleRecord;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Minimal directory-owned API for policy-key revocation/rotation (#608).
 *
 * Stores lifecycle state by SHA-256(public_key_bytes). Raw policy keys, operator keys
 * and evidence documents stay outside public APIs.
 */
class PolicyKeyLifecycleService
{
    public function markRevoked(
        string $policyKeySha256,
        ?CarbonInterface $revokedAt = null,
        string $reasonClass = 'operator_request',
        ?int $rotationEpoch = null,
        ?string $evidenceRef = null,
    ): PolicyKeyLifecycleRecord {
        return $this->upsert(
            policyKeySha256: $policyKeySha256,
            status: PolicyKeyLifecycleRecord::STATUS_REVOKED,
            revokedAt: $revokedAt,
            reasonClass: $reasonClass,
            rotationEpoch: $rotationEpoch,
            evidenceRef: $evidenceRef,
        );
    }

    public function markSuperseded(
        string $policyKeySha256,
        ?string $supersededByPolicyKeySha256 = null,
        string $reasonClass = 'rotation',
        ?int $rotationEpoch = null,
        ?string $evidenceRef = null,
    ): PolicyKeyLifecycleRecord {
        return $this->upsert(
            policyKeySha256: $policyKeySha256,
            status: PolicyKeyLifecycleRecord::STATUS_SUPERSEDED,
            reasonClass: $reasonClass,
            rotationEpoch: $rotationEpoch,
            supersededByPolicyKeySha256: $supersededByPolicyKeySha256,
            evidenceRef: $evidenceRef,
        );
    }

    public function recordForKeySha(string $policyKeySha256): ?PolicyKeyLifecycleRecord
    {
        return PolicyKeyLifecycleRecord::query()
            ->where('policy_key_sha256', $this->normalizeSha256($policyKeySha256, 'policy_key_sha256'))
            ->first();
    }

    private function upsert(
        string $policyKeySha256,
        string $status,
        ?CarbonInterface $revokedAt = null,
        string $reasonClass = 'directory_record',
        ?int $rotationEpoch = null,
        ?string $supersededByPolicyKeySha256 = null,
        ?string $evidenceRef = null,
    ): PolicyKeyLifecycleRecord {
        if (! in_array($status, PolicyKeyLifecycleRecord::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid policy-key lifecycle status.');
        }

        $data = [
            'status' => $status,
            'revoked_at' => $revokedAt,
            'revocation_reason_class' => $this->normalizeReasonClass($reasonClass),
            'rotation_epoch' => $rotationEpoch === null ? null : max(0, $rotationEpoch),
            'superseded_by_policy_key_sha256' => $supersededByPolicyKeySha256 === null
                ? null
                : $this->normalizeSha256($supersededByPolicyKeySha256, 'superseded_by_policy_key_sha256'),
            'evidence_ref' => $evidenceRef,
        ];

        return PolicyKeyLifecycleRecord::query()->updateOrCreate(
            ['policy_key_sha256' => $this->normalizeSha256($policyKeySha256, 'policy_key_sha256')],
            $data,
        );
    }

    private function normalizeSha256(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^[0-9a-f]{64}$/', $value)) {
            throw new InvalidArgumentException("Invalid {$field}; expected lowercase SHA-256 hex.");
        }

        return $value;
    }

    private function normalizeReasonClass(string $value): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
            throw new InvalidArgumentException('Invalid revocation reason class.');
        }

        return $value;
    }
}
