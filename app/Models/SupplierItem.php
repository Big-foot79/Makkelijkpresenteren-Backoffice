<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One article in a supplier's catalogue. Written by the imports in bulk, so changes are not
 * recorded one by one in the change history (the import run is the history).
 */
class SupplierItem extends Model
{
    protected $guarded = [];

    public const STATUSES = [
        'eol' => 'End of life',
        'eol_with_follower' => 'End of life, met opvolger',
        'allocation' => 'Op allocatie',
        'not_deliverable' => 'Niet leverbaar',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'msrp' => 'decimal:2',
            'net_price' => 'decimal:2',
            'surcharge' => 'decimal:2',
            'is_kit' => 'boolean',
            'is_listed' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'price_updated_at' => 'datetime',
        ];
    }

    /** Adds `stock_quantity` (sum over locations in the supplier's current stock run) to each row. */
    public function scopeWithCurrentStock(Builder $query): void
    {
        $query->addSelect([
            'stock_quantity' => DB::table('supplier_stock')
                ->join('suppliers', 'suppliers.id', '=', 'supplier_stock.supplier_id')
                ->whereColumn('supplier_stock.supplier_id', 'supplier_items.supplier_id')
                ->whereColumn('supplier_stock.sku', 'supplier_items.sku')
                ->whereColumn('supplier_stock.job_run_id', 'suppliers.current_stock_run_id')
                ->selectRaw('sum(supplier_stock.quantity)'),
        ]);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
