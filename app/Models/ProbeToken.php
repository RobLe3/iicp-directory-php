<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProbeToken extends Model
{
    protected $fillable = ['token_hash', 'label', 'region', 'expires_at', 'last_seen_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function probes(): HasMany
    {
        return $this->hasMany(TelemetryProbe::class);
    }

    public function touchLastSeen(): void
    {
        $this->last_seen_at = now();
        $this->saveQuietly();
    }
}
