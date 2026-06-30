<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelemetryProbe extends Model
{
    public $timestamps = false;

    protected $table = 'iicp_telemetry_probes';

    protected $fillable = [
        'probe_token_id', 'node_id', 'run_id', 'probe_id', 'probe_type',
        'test_id', 'level', 'passed', 'latency_ms', 'detail', 'metadata', 'probed_at',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'metadata' => 'array',
        'probed_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(ProbeToken::class, 'probe_token_id');
    }
}
