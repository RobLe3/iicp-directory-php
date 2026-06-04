<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyTelemetry extends Model
{
    protected $table = 'proxy_telemetry';

    protected $fillable = [
        'node_id',
        'proxy_node_id',
        'time_bucket',
        'latency_ms_observed',
        'tokens_observed',
        'status',
        'qos_advertised',
        'qos_met',
    ];

    protected $casts = [
        'latency_ms_observed' => 'float',
        'tokens_observed' => 'integer',
        'qos_met' => 'boolean',
        'time_bucket' => 'integer',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
