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
        'capability_version',
        'capability_phase',
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

    public function toEffectiveCapabilityArray(): array
    {
        return array_filter([
            'intent' => $this->intent,
            'version' => $this->capability_version,
            'phase' => $this->capability_phase,
            'variant_id' => $this->variant_id,
            'models' => $this->models ?: [],
            'max_tokens' => $this->max_tokens ?: null,
            'quantization' => $this->quantization,
            'inference_engine' => $this->inference_engine,
            'input_modalities' => $this->input_modalities ?: ['text'],
            'output_modalities' => $this->output_modalities,
            'features' => $this->features,
            'execution_capabilities' => $this->execution_capabilities,
            'limits' => $this->capability_limits,
            'supported_profiles' => $this->supported_profiles ?: [],
            'claim_provenance' => $this->claim_provenance,
            'extensions' => $this->extensions,
        ], fn (mixed $value): bool => $value !== null);
    }

    protected $casts = [
        'models' => 'array',
        'capability_phase' => 'integer',
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
