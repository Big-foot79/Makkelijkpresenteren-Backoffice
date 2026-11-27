<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'msrp' => 'decimal:2',
            'purchase_price' => 'decimal:2',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
