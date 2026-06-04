<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credit extends Model
{
    protected $primaryKey = 'node_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['node_id', 'balance', 'free_credit_last_allocation_at'];

    protected $casts = [
        'balance' => 'decimal:4',
        'free_credit_last_allocation_at' => 'datetime',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'node_id', 'node_id');
    }
}
