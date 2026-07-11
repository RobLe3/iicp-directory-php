<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSubjectAction extends Model
{
    protected $fillable = [
        'tracking_id',
        'action',
        'subject_hash',
        'selector',
        'affected_counts',
        'retention_reason',
        'applied_at',
    ];

    protected $casts = [
        'selector' => 'array',
        'affected_counts' => 'array',
        'applied_at' => 'datetime',
    ];
}
