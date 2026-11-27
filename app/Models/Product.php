<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use App\Suppliers\StockAvailability;
use App\Support\Brands;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A product the backoffice owns. Linked to WooCommerce by woo_id and to supplier articles by EAN.
 * Price and availability are decided here and pushed to the shop by ShopSync.
 */
class Product extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    /** Sync bookkeeping and the daily availability are no product changes worth a history line. */
    protected array $changeHistoryAlsoHidden = ['woo_synced_state', 'woo_synced_at', 'availability', 'availability_date', 'availability_updated_at'];

    public const STATUSES = [
        'publish' => 'Gepubliceerd',
        'draft' => 'Concept',
        'pending' => 'Ter beoordeling',
        'private' => 'Privé',
    ];

    protected function casts(): array
    {
        return [
            'webshop_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'availability_date' => 'date',
            'availability_updated_at' => 'datetime',
            'woo_synced_state' => 'array',
            'woo_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            if ($product->isDirty('brand')) {
                $product->brand = Brands::canonical($product->brand);
            }
        });
    }

    public function wooProduct(): HasOne
    {
        return $this->hasOne(WooProduct::class);
    }

    public function supplierItems(): HasMany
    {
        return $this->hasMany(SupplierItem::class, 'ean', 'ean')->where('is_listed', true);
    }

    public function availabilityLabel(): ?string
    {
        return $this->availability ? StockAvailability::STATUS_LABELS[$this->availability] ?? $this->availability : null;
    }

    /** The cheapest listed supplier article with the same EAN. */
    public function lowestPurchase(): ?SupplierItem
    {
        return $this->ean
            ? SupplierItem::query()->with('supplier:id,name')->where('ean', $this->ean)->where('is_listed', true)
                ->whereNotNull('net_price')->orderBy('net_price')->first()
            : null;
    }
}
