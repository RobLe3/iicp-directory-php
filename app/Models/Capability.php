<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Capability extends Model
{
    protected $fillable = [
        'node_id',
        'intent',
        'models',
        'max_tokens',
        'quantization',
        'inference_engine',
        'input_modalities',
        'supported_profiles',
    ];

    protected $casts = [
        'models' => 'array',
        // #408/ADR-046 — ["text"] | ["text","image"] (vision). Default text-only.
        'input_modalities' => 'array',
        'supported_profiles' => 'array',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
