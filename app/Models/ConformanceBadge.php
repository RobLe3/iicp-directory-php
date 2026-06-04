<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConformanceBadge extends Model
{
    protected $primaryKey = 'badge_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'badge_id',
        'tier',
        'subject_did',
        'subject_component',
        'suite_version',
        'passed_at',
        'expires_at',
        'test_results_url',
        'issuer_did',
        'verification_mode',
        'sig',
        'status',
    ];

    protected $casts = [
        'passed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function markExpiredIfNeeded(): bool
    {
        if ($this->status === 'active' && $this->expires_at->isPast()) {
            $this->update(['status' => 'expired']);

            return true;
        }

        return false;
    }
}
