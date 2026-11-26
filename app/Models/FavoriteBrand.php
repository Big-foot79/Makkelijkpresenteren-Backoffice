<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A brand we sell often, shown as a quick tab above the supplier articles.
 * Matching is case-insensitive, because suppliers spell brands differently (IIYAMA / Iiyama).
 */
class FavoriteBrand extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    /** Limits a supplier_items query to this brand, whatever the capitalisation. */
    public static function applyTo(Builder $query, string $brand): Builder
    {
        return $query->whereRaw('lower(supplier_items.brand) = ?', [mb_strtolower($brand)]);
    }

    public static function isFavorite(?string $brand): bool
    {
        return filled($brand) && static::whereRaw('lower(name) = ?', [mb_strtolower($brand)])->exists();
    }

    /**
     * Distinct brand names from the supplier catalogues, for pickers.
     *
     * @return array<string, string>
     */
    public static function knownBrands(?string $search = null, int $limit = 50): array
    {
        return SupplierItem::query()
            ->whereNotNull('brand')
            ->where('is_listed', true)
            ->when($search, fn (Builder $q) => $q->where('brand', 'like', $search.'%'))
            ->distinct()
            ->orderBy('brand')
            ->limit($limit)
            ->pluck('brand', 'brand')
            ->all();
    }
}
