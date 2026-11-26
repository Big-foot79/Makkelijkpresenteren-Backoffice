<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stock line from one import run. Only the supplier's current run counts. */
class SupplierStock extends Model
{
    protected $table = 'supplier_stock';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'next_date' => 'date',
            'as_of' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
