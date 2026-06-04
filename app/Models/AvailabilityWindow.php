<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityWindow extends Model
{
    protected $fillable = [
        'node_id',
        'start_time',
        'end_time',
        'share',
    ];

    protected $casts = [
        'share' => 'float',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
