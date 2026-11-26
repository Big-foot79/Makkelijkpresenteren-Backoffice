<?php

namespace App\Suppliers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock per EAN across suppliers, following the decisions of 28 Oct 2026:
 * - never sum across suppliers (several channels can share one distribution centre);
 * - the highest quantity at a single supplier decides the status;
 * - a supplier's stock list older than max_age_days does not count;
 * - more than ample_above = "ruim", 1..ample_above = "beperkt", 0 = "op nalevering"
 *   (with the earliest expected date, where a supplier delivers one).
 */
class StockAvailability
{
    public const STATUS_LABELS = [
        'ample' => 'Ruim op voorraad',
        'limited' => 'Beperkt op voorraad',
        'backorder' => 'Op nalevering',
        'unknown' => 'Onbekend',
    ];

    /**
     * @param  list<string>  $eans  normalised EANs
     * @return Collection<string, array{status: string, label: string, best_quantity: int, best_supplier: ?string, next_date: ?string, suppliers: list<array>}>
     */
    public function forEans(array $eans): Collection
    {
        $eans = array_values(array_unique(array_filter($eans)));

        if ($eans === []) {
            return collect();
        }

        $maxAge = (int) config('backoffice.stock.max_age_days');

        $lines = DB::table('supplier_items as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->leftJoin('supplier_stock as st', function ($join) {
                $join->on('st.supplier_id', '=', 'i.supplier_id')
                    ->on('st.sku', '=', 'i.sku')
                    ->on('st.job_run_id', '=', 's.current_stock_run_id');
            })
            ->whereIn('i.ean', $eans)
            ->where('i.is_listed', true)
            ->groupBy('i.ean', 's.id', 's.name', 's.code', 's.stock_as_of', 'i.sku')
            ->select([
                'i.ean', 'i.sku', 's.name as supplier', 's.code as supplier_code', 's.stock_as_of',
                DB::raw('coalesce(sum(st.quantity), 0) as quantity'),
                DB::raw('min(st.next_date) as next_date'),
                DB::raw('count(st.id) as stock_lines'),
            ])
            ->get();

        return collect($eans)->mapWithKeys(function (string $ean) use ($lines, $maxAge) {
            $suppliers = $lines->where('ean', $ean)->map(function ($line) use ($maxAge) {
                $asOf = $line->stock_as_of ? CarbonImmutable::parse($line->stock_as_of) : null;
                $fresh = $asOf !== null && $asOf->gte(now()->subDays($maxAge));

                return [
                    'supplier' => $line->supplier,
                    'supplier_code' => $line->supplier_code,
                    'sku' => $line->sku,
                    'quantity' => (int) $line->quantity,
                    'next_date' => $line->next_date ? substr($line->next_date, 0, 10) : null,
                    'stock_as_of' => $asOf?->toIso8601String(),
                    'counts' => $fresh && $line->stock_lines > 0,
                ];
            })->values();

            $counting = $suppliers->where('counts', true);
            $best = $counting->sortByDesc('quantity')->first();
            $bestQty = (int) ($best['quantity'] ?? 0);
            $ample = (int) config('backoffice.stock.ample_above');

            $status = match (true) {
                $counting->isEmpty() => 'unknown',
                $bestQty > $ample => 'ample',
                $bestQty > 0 => 'limited',
                default => 'backorder',
            };

            return [$ean => [
                'status' => $status,
                'label' => self::STATUS_LABELS[$status],
                'best_quantity' => $bestQty,
                'best_supplier' => $bestQty > 0 ? $best['supplier'] : null,
                'next_date' => $status === 'backorder' ? $counting->pluck('next_date')->filter()->sort()->first() : null,
                'suppliers' => $suppliers->all(),
            ]];
        });
    }
}
