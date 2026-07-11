<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Directory-owned lifecycle state for node policy-signing keys (#608).
 *
 * Keyed by SHA-256(public_key_bytes). Raw policy public keys, operator keys and evidence
 * documents must not be exposed through public APIs.
 */
class PolicyKeyLifecycleRecord extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_SUPERSEDED = 'superseded';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_REVOKED,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'policy_key_sha256',
        'status',
        'rotation_epoch',
        'revoked_at',
        'revocation_reason_class',
        'superseded_by_policy_key_sha256',
        'evidence_ref',
    ];

    protected $casts = [
        'rotation_epoch' => 'integer',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'policy_key_sha256',
        'superseded_by_policy_key_sha256',
        'evidence_ref',
    ];
}
