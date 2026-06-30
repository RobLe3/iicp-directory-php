<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-048 — a per-node, per-evaluator health snapshot replicated via the signed
 * HEALTH event (S.13 §3.4, #374). One row per (node_id, evaluator_did): the latest
 * vector that evaluator published for that node.
 *
 * The federation-wide mesh_health read resolves each node's canonical health by
 * majority-vote across these rows' evaluator_did (fallback most-recent by
 * evaluated_at_ms).
 */
class NodeHealthObservation extends Model
{
    protected $table = 'node_health_observations';

    protected $fillable = [
        'node_id',
        'evaluator_did',
        'score',
        'label',
        'components',
        'evaluated_at_ms',
        'event_id',
    ];

    protected $casts = [
        'components' => 'array',
        'score' => 'float',
        'evaluated_at_ms' => 'integer',
    ];
}
