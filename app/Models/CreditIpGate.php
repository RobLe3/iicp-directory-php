<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IP-level free credit gate row (RT-02b, #380).
 * One row per source IP; tracks last_allocation_at for the 6h window.
 */
class CreditIpGate extends Model
{
    protected $table = 'credit_ip_gates';

    protected $primaryKey = 'ip_address';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ip_address',
        'last_allocation_at',
        'allocation_count',
    ];

    protected $casts = [
        'last_allocation_at' => 'datetime',
        'allocation_count' => 'integer',
    ];
}
