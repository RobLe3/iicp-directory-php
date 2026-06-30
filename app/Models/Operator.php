<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operator-identity record keyed by operator_pubkey (== operator_id, #464). One per
 * operator. Holds the public mutable display_name + identity-integrity fields + (#310)
 * founder recognition state. See migration create_operators_table.
 *
 * operator_pubkey is PRIVATE — never serialise it into a public API response; only
 * display_name (+ recognition ordinal/tier/badge) is public.
 */
class Operator extends Model
{
    protected $fillable = [
        'operator_pubkey',
        'display_name',
        'attested_created_at',
        'operator_integrity_hash',
        'first_seen_ms',
        'ordinal',
        'tier',
        'badge',
        'provenance',
    ];

    protected $casts = [
        'first_seen_ms' => 'integer',
        'ordinal' => 'integer',
        'provenance' => 'array',
    ];

    /** operator_pubkey is a shared secret-ish identity key — keep it out of array/JSON casts. */
    protected $hidden = ['operator_pubkey'];

    /**
     * Public, non-secret operator key fingerprint for display-name disambiguation.
     *
     * The full operator_pubkey remains private, but a short stable hash lets clients
     * distinguish "Alice #a1b2…" from an attempted look-alike without exposing the key.
     */
    public static function publicFingerprint(string $operatorPubkey): string
    {
        return substr(hash('sha256', $operatorPubkey), 0, 12);
    }
}
