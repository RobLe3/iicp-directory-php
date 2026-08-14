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
        'variant_id',
        'models',
        'max_tokens',
        'quantization',
        'inference_engine',
        'input_modalities',
        'output_modalities',
        'features',
        'execution_capabilities',
        'capability_limits',
        'supported_profiles',
        'claim_provenance',
        'extensions',
    ];

    protected $casts = [
        'models' => 'array',
        // #408/ADR-046 — ["text"] | ["text","image"] (vision). Default text-only.
        'input_modalities' => 'array',
        'output_modalities' => 'array',
        'features' => 'array',
        'execution_capabilities' => 'array',
        'capability_limits' => 'array',
        'supported_profiles' => 'array',
        'claim_provenance' => 'array',
        'extensions' => 'array',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
