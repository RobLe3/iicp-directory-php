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
        'node_id',
        'ts_ms',
        'payload',
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
    ];
}
