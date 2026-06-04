<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    protected $fillable = ['node_id', 'amount', 'type', 'task_id', 'reason'];

    protected $casts = ['amount' => 'decimal:4'];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class, 'node_id', 'node_id');
    }
}
