<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reputation extends Model
{
    protected $primaryKey = 'node_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['node_id', 'score', 'tasks_total', 'tasks_failed', 'completed_tasks_count', 'avg_latency_ms', 'observed_latency_ms'];

    protected $casts = [
        'score' => 'float',
        'avg_latency_ms' => 'float',
        'observed_latency_ms' => 'float',
        'completed_tasks_count' => 'integer',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
