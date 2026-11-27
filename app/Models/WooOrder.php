<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An order from the shop, kept for insight (which products sell, which campaigns bring orders).
 * Only business data: no names, e-mail addresses or street addresses.
 */
class WooOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'lines' => 'array',
            'attribution' => 'array',
            'ordered_at' => 'datetime',
        ];
    }
}
