<?php

namespace App\Pricing;

use App\Models\BrandTerm;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a product really costs us, per EAN. Three candidates, the lowest wins:
 *  - bid:      a purchase/bid price from a current uploaded price list;
 *  - discount: the advice price minus the brand's usual discount (Merkafspraken);
 *  - list:     the lowest distributor price-list price.
 * The advice price comes from a current uploaded price list, or else from a distributor (TD Synnex).
 */
class PurchasePrice
{
    /** Results per EAN within one request or job (the service is scoped). */
    private array $memo = [];

    private ?Collection $terms = null;

    /** Forget cached results, e.g. after a price list was read in. */
    public function flush(): void
    {
        $this->memo = [];
        $this->terms = null;
    }

    /**
     * @param  list<string>  $eans
     * @return Collection<string, array{expected: ?float, source: ?string, label: ?string, supplier: ?string, list: ?array, bid: ?array, discount: ?array}>
     */
    public function forEans(array $eans): Collection
    {
        $eans = array_values(array_unique(array_filter($eans)));
        $missing = array_values(array_diff($eans, array_keys($this->memo)));

        if ($missing !== []) {
            $this->memo = $this->calculate($missing)->all() + $this->memo;
        }

        return collect($eans)->mapWithKeys(fn (string $ean) => [$ean => $this->memo[$ean]]);
    }

    private function calculate(array $eans): Collection
    {

        $supplierItems = DB::table('supplier_items as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->whereIn('i.ean', $eans)
            ->where('i.is_listed', true)
            ->get(['i.ean', 'i.sku', 'i.net_price', 'i.msrp', 'i.brand', 's.name as supplier', 's.id as supplier_id'])
            ->groupBy('ean');

        $listItems = DB::table('price_list_items as pi')
            ->join('price_lists as pl', 'pl.id', '=', 'pi.price_list_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pl.supplier_id')
            ->whereIn('pi.ean', $eans)
            ->where('pl.status', 'imported')
            ->where(fn ($q) => $q->whereNull('pl.valid_from')->orWhere('pl.valid_from', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('pl.valid_until')->orWhere('pl.valid_until', '>=', now()->toDateString()))
            ->orderByDesc('pl.imported_at')
            ->get(['pi.ean', 'pi.msrp', 'pi.purchase_price', 'pl.id as list_id', 'pl.name as list_name', 'pl.brand', 's.name as supplier'])
            ->groupBy('ean');

        $productBrands = Product::query()->whereIn('ean', $eans)->pluck('brand', 'ean');

        $terms = $this->terms ??= BrandTerm::query()->with('supplier:id,name')->get()->keyBy(fn (BrandTerm $t) => mb_strtolower($t->brand));

        return collect($eans)->mapWithKeys(function (string $ean) use ($supplierItems, $listItems, $productBrands, $terms) {
            $items = collect($supplierItems->get($ean, []));
            $lists = collect($listItems->get($ean, []));

            $cheapest = $items->whereNotNull('net_price')->sortBy(fn ($i) => (float) $i->net_price)->first();
            $list = $cheapest ? ['price' => (float) $cheapest->net_price, 'supplier' => $cheapest->supplier, 'sku' => $cheapest->sku] : null;

            $bidRow = $lists->whereNotNull('purchase_price')->sortBy(fn ($i) => (float) $i->purchase_price)->first();
            $bid = $bidRow ? ['price' => (float) $bidRow->purchase_price, 'price_list' => $bidRow->list_name, 'price_list_id' => $bidRow->list_id, 'supplier' => $bidRow->supplier] : null;

            $brand = $productBrands->get($ean)
                ?? $lists->pluck('brand')->filter()->first()
                ?? $items->pluck('brand')->filter()->first();
            $term = $brand ? $terms->get(mb_strtolower(trim($brand))) : null;

            $msrpFromList = $lists->whereNotNull('msrp')->first();
            $msrp = $msrpFromList
                ? ['price' => (float) $msrpFromList->msrp, 'from' => $msrpFromList->list_name]
                : (($row = $items->whereNotNull('msrp')->sortByDesc(fn ($i) => (float) $i->msrp)->first()) ? ['price' => (float) $row->msrp, 'from' => $row->supplier] : null);

            $discount = $term && $msrp && (float) $term->discount_percent > 0 ? [
                'price' => round($msrp['price'] * (1 - (float) $term->discount_percent / 100), 2),
                'msrp' => $msrp['price'],
                'msrp_from' => $msrp['from'],
                'percent' => (float) $term->discount_percent,
                'brand' => $term->brand,
                'supplier' => $term->supplier?->name,
            ] : null;

            $candidates = array_filter([
                'bid' => $bid ? $bid['price'] : null,
                'discount' => $discount ? $discount['price'] : null,
                'list' => $list ? $list['price'] : null,
            ], fn ($p) => $p !== null);

            asort($candidates);
            $source = array_key_first($candidates);

            return [$ean => [
                'expected' => $source ? $candidates[$source] : null,
                'source' => $source,
                'label' => match ($source) {
                    'bid' => 'Bidprijs uit '.$bid['price_list'],
                    'discount' => sprintf('Adviesprijs € %s − %s%% (%s)%s', number_format($discount['msrp'], 2, ',', '.'), rtrim(rtrim(number_format($discount['percent'], 2, ',', ''), '0'), ','), $discount['brand'], $discount['supplier'] ? ' via '.$discount['supplier'] : ''),
                    'list' => 'Inkoop '.$list['supplier'],
                    default => null,
                },
                'supplier' => match ($source) {
                    'bid' => $bid['supplier'],
                    'discount' => $discount['supplier'],
                    'list' => $list['supplier'],
                    default => null,
                },
                'list' => $list,
                'bid' => $bid,
                'discount' => $discount,
            ]];
        });
    }

    public function forEan(?string $ean): ?array
    {
        return $ean ? $this->forEans([$ean])->get($ean) : null;
    }

    /** Margin as a share of the selling price (excl. VAT), in percent. */
    public static function marginPercent(?float $sellingPrice, ?float $purchase): ?float
    {
        return $sellingPrice > 0 && $purchase !== null ? round(($sellingPrice - $purchase) / $sellingPrice * 100, 1) : null;
    }
}
