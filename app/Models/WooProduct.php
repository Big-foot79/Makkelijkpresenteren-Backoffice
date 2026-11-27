<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a product looks like in WooCommerce right now: a mirror, refreshed by fetches and webhooks. */
class WooProduct extends Model
{
    protected $guarded = [];

    public const MATCH_STATUSES = [
        'ok' => 'Te koppelen',
        'linked' => 'Gekoppeld',
        'no_ean' => 'Geen EAN',
        'duplicate_ean' => 'EAN dubbel in shop',
        'variable' => 'Variabel product',
        'other_type' => 'Ander producttype',
    ];

    protected function casts(): array
    {
        return [
            'regular_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'manage_stock' => 'boolean',
            'managed_meta' => 'array',
            'woo_modified_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function adminUrl(): ?string
    {
        $url = ShopConnection::current()?->url;

        return $url ? rtrim($url, '/')."/wp-admin/post.php?post={$this->woo_id}&action=edit" : null;
    }
}
