<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TrustDomainMembership extends Model
{
    use HasUuids;

    protected $fillable = [
        'domain_id',
        'subject_kind',
        'subject_id',
        'issuer_id',
        'token_hash',
        'scopes',
        'generation',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'generation' => 'integer',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected $hidden = ['token_hash'];
}
