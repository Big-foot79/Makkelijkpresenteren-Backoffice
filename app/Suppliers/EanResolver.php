<?php

namespace App\Suppliers;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the EAN of supplier articles that have none (TD Synnex sends fake EANs for some items,
 * which the import drops), by manufacturer part number + brand:
 *  - another supplier's article with the same part number and a real EAN, or
 *  - a webshop product whose SKU is that part number.
 * Only when exactly one EAN fits, and the brands do not contradict each other.
 */
class EanResolver
{
    /** Short part numbers ("100", "A1") are too ambiguous without a brand. */
    private const MIN_LENGTH_WITHOUT_BRAND = 5;

    public function forSupplier(Supplier $supplier): int
    {
        $resolved = 0;

        DB::table('supplier_items')
            ->where('supplier_id', $supplier->id)
            ->where('is_listed', true)
            ->whereNull('ean')
            ->whereNotNull('mpn')
            ->orderBy('id')
            ->select(['id', 'mpn', 'brand'])
            ->chunk(1000, function ($items) use ($supplier, &$resolved) {
                $mpns = $items->map(fn ($i) => mb_strtolower(trim($i->mpn)))->unique()->values()->all();

                $known = DB::table('supplier_items')
                    ->where('supplier_id', '!=', $supplier->id)
                    ->where('is_listed', true)
                    ->whereNotNull('ean')
                    ->whereNull('ean_source') // only EANs from a supplier's own file
                    ->whereIn(DB::raw('lower(mpn)'), $mpns)
                    ->get(['mpn', 'ean', 'brand'])
                    ->concat(DB::table('products')
                        ->whereNotNull('ean')
                        ->whereIn(DB::raw('lower(sku)'), $mpns)
                        ->get(['sku as mpn', 'ean', 'brand']))
                    ->groupBy(fn ($row) => mb_strtolower(trim($row->mpn)));

                foreach ($items as $item) {
                    $key = mb_strtolower(trim($item->mpn));
                    $brand = $item->brand ? mb_strtolower($item->brand) : null;

                    $eans = collect($known->get($key, []))
                        ->filter(fn ($row) => $brand === null || $row->brand === null || mb_strtolower($row->brand) === $brand)
                        ->filter(fn ($row) => $brand !== null || $row->brand !== null || mb_strlen($key) >= self::MIN_LENGTH_WITHOUT_BRAND)
                        ->pluck('ean')
                        ->unique();

                    if ($eans->count() === 1) {
                        DB::table('supplier_items')->where('id', $item->id)->update(['ean' => $eans->first(), 'ean_source' => 'mpn']);
                        $resolved++;
                    }
                }
            });

        return $resolved;
    }
}
