<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 6 prerequisite: signed event log entry.
 *
 * Spec: spec/iicp-federated-directory.md §3.4
 */
class NodeEvent extends Model
{
    protected $table = 'node_events';

    protected $fillable = [
        'event_id',
        'seq',
        'event_type',
        'service_id',
        'node_id',
        'ts_ms',
        'payload',
        'prev_hash',
        'signature',
    ];

    protected $casts = [
        'payload' => 'array',
        'seq' => 'integer',
        'ts_ms' => 'integer',
    ];

    /** Valid event types per spec §3.4. */
    public const TYPES = [
        'REGISTER',
        'HEARTBEAT',
        'SCORE_UPDATE',
        'REPUTATION_UPDATE',
        'CREDIT_AWARD',
        'DEREGISTER',
        'REPLICA_REGISTERED',
        'REPLICA_DEREGISTERED',
        'REPUTATION_DECAY',
        'OPERATOR_OBSERVED',
        // ADR-048 (#374): per-node, per-evaluator health vector gossiped for
        // federation-wide mesh_health aggregation.
        'HEALTH',
        // #310 / spec/iicp-recognition.md §5.4 — founder-ordinal recognition (signed on the
        // #458 hash-chain). FOUNDER_LOCKIN assigns the immutable ordinal after lock-in;
        // FOUNDER_SUCCESSION transfers an already-locked ordinal's current-holder.
        'FOUNDER_LOCKIN',
        'FOUNDER_SUCCESSION',
        // Uptime tracking (#508): session lifecycle events that make cumulative node
        // uptime computable from the signed event log rather than approximated.
        // EVICT: emitted by LivenessMonitor when a node transitions active→dormant.
        // REACTIVATE: emitted by HeartbeatController when a dormant node sends a heartbeat.
        'EVICT',
        'REACTIVATE',
    ];
}
