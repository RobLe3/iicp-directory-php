<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 6 federated directory replica record.
 *
 * Created by ReplicasController::register() per S.13 §7.1. One row per
 * unique `did` — re-registration is idempotent, rotates `replica_token_hash`
 * and refreshes `expires_at`.
 *
 * The `replica_token` (plaintext JWT) is NEVER stored; only its SHA-256
 * hash is persisted, parity with how node_token is handled on the Node
 * model. Validation compares hash(presented_token) to the stored hash.
 */
class Replica extends Model
{
    protected $fillable = [
        'replica_id',
        'did',
        'endpoint',
        'trust_tier',
        'replica_token_hash',
        'expires_at',
        'last_seen_at',
        // Phase A.3 / ADR-039: lifecycle state
        'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    // Phase A.3 lifecycle states
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DORMANT = 'dormant';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DECOMMISSIONED = 'decommissioned';

    public function touchLastSeen(): void
    {
        $this->last_seen_at = now();
        $this->saveQuietly();
    }

    /**
     * Is this replica's token still valid (not expired)?
     */
    public function isActive(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
