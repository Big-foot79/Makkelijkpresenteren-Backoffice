<?php

namespace App\Shop;

use App\Models\JobRun;
use App\Models\Product;
use App\Models\ShopConnection;
use App\Models\WooProduct;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads WooCommerce into the woo_products mirror, sorts every shop product into a match status
 * and, after approval, links the clean ones to backoffice products. Fetching only reads.
 */
class ShopCatalog
{
    public function __construct(private readonly WooClient $client) {}

    /** Fetch every product, classify, store the report and flag conflicts. */
    public function refresh(JobRun $run): string
    {
        $startedAt = now()->subSecond();
        $seen = 0;
        $buffer = [];

        foreach ($this->client->paginate('products', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']) as $raw) {
            $buffer[] = (new WooProductData($raw))->mirror();
            $seen++;

            if (count($buffer) === 500) {
                $this->store($buffer);
                $buffer = [];
                $run->info("{$seen} producten opgehaald");
            }
        }

        $this->store($buffer);
        $run->info("{$seen} producten opgehaald uit de webshop");

        $known = WooProduct::count();

        if ($seen === 0 && $known > 0) {
            throw new RuntimeException("De webshop gaf 0 producten terug terwijl er {$known} bekend zijn; niets aangepast.");
        }

        $this->forgetDeleted($startedAt, $run);
        $report = $this->classify();

        $conflicts = app(ShopSync::class)->detectConflicts(
            WooProduct::query()->whereNotNull('product_id')->with('product')->lazyById(500)
        );

        $connection = $this->client->connection();
        $connection->update(['last_report' => $report + ['conflicts' => $conflicts, 'fetched_at' => now()->toIso8601String()]]);

        foreach ($report['by_status'] as $status => $count) {
            $run->info((WooProduct::MATCH_STATUSES[$status] ?? $status).": {$count}");
        }

        return sprintf(
            '%d producten: %d te koppelen (%d met leveranciersartikel), %d gekoppeld, %d zonder EAN, %d dubbele EAN, %d conflicten',
            $report['total'], $report['by_status']['ok'] ?? 0, $report['ok_with_supplier'],
            $report['by_status']['linked'] ?? 0, $report['by_status']['no_ean'] ?? 0,
            $report['by_status']['duplicate_ean'] ?? 0, $conflicts,
        );
    }

    /** Link every product with status "ok" to a backoffice product (created when needed). */
    public function link(JobRun $run): string
    {
        $linked = 0;

        WooProduct::query()->where('match_status', 'ok')->whereNull('product_id')->lazyById(500)
            ->each(function (WooProduct $woo) use (&$linked) {
                DB::transaction(function () use ($woo, &$linked) {
                    $product = Product::query()->where('ean', $woo->ean)->whereNull('woo_id')->first()
                        ?? new Product(['ean' => $woo->ean]);

                    $product->fill([
                        'woo_id' => $woo->woo_id,
                        'sku' => $product->sku ?? $woo->sku,
                        'name' => $product->name ?? ($woo->name ?: $woo->sku ?: $woo->ean),
                        'brand' => $product->brand ?? $woo->brand,
                        'status' => $woo->status ?? 'draft',
                        'webshop_price' => $product->webshop_price ?? $woo->regular_price,
                        'sale_price' => $product->sale_price ?? $woo->sale_price,
                    ])->save();

                    $woo->update(['product_id' => $product->id, 'match_status' => 'linked']);
                    $linked++;
                });
            });

        $run->info("{$linked} producten gekoppeld");
        $report = $this->classify();
        $this->client->connection()->update(['last_report' => array_merge($this->client->connection()->last_report ?? [], $report)]);

        return "{$linked} producten gekoppeld; prijzen uit de webshop overgenomen als startpunt";
    }

    /** @param list<array> $rows */
    private function store(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $rows = array_map(fn (array $row) => ['managed_meta' => json_encode($row['managed_meta'])] + $row + [
            'created_at' => now(), 'updated_at' => now(),
        ], $rows);

        WooProduct::upsert($rows, ['woo_id'], array_values(array_diff(array_keys($rows[0]), ['woo_id', 'created_at'])));
    }

    /** Products that disappeared from the shop: drop the mirror and unlink the backoffice product. */
    private function forgetDeleted(\DateTimeInterface $startedAt, JobRun $run): void
    {
        $gone = WooProduct::query()->where(fn ($q) => $q->whereNull('fetched_at')->orWhere('fetched_at', '<', $startedAt))->get();

        foreach ($gone as $woo) {
            if ($woo->product) {
                $woo->product->update(['woo_id' => null, 'woo_synced_state' => null]);
                $run->warning("Product {$woo->product->name} (Woo {$woo->woo_id}) staat niet meer in de webshop; koppeling losgemaakt");
            }

            $woo->delete();
        }
    }

    /** @return array{total: int, by_status: array<string, int>, ok_with_supplier: int, manage_stock: int} */
    public function classify(): array
    {
        // How many supplier articles share each EAN.
        $supplierCounts = DB::table('supplier_items')
            ->where('is_listed', true)
            ->whereIn('ean', WooProduct::query()->whereNotNull('ean')->select('ean'))
            ->groupBy('ean')
            ->pluck(DB::raw('count(*)'), 'ean');

        // EANs used by more than one simple product in the shop.
        $duplicates = WooProduct::query()->whereNotNull('ean')->where('type', 'simple')
            ->groupBy('ean')->havingRaw('count(*) > 1')->pluck('ean')->flip();

        // EANs that already belong to a backoffice product linked to another Woo product.
        $taken = Product::query()->whereNotNull('woo_id')->whereNotNull('ean')->pluck('woo_id', 'ean');

        WooProduct::query()->lazyById(500)->each(function (WooProduct $woo) use ($supplierCounts, $duplicates, $taken) {
            $status = match (true) {
                $woo->product_id !== null => 'linked',
                $woo->type === 'variable' => 'variable',
                $woo->type !== 'simple' => 'other_type',
                $woo->ean === null => 'no_ean',
                isset($duplicates[$woo->ean]) => 'duplicate_ean',
                isset($taken[$woo->ean]) && (int) $taken[$woo->ean] !== $woo->woo_id => 'duplicate_ean',
                default => 'ok',
            };

            $matches = $woo->ean ? (int) ($supplierCounts[$woo->ean] ?? 0) : 0;

            if ($woo->match_status !== $status || $woo->supplier_matches !== $matches) {
                $woo->forceFill(['match_status' => $status, 'supplier_matches' => $matches])->saveQuietly();
            }
        });

        return [
            'total' => WooProduct::count(),
            'by_status' => WooProduct::query()->groupBy('match_status')->pluck(DB::raw('count(*)'), 'match_status')->map(fn ($n) => (int) $n)->all(),
            'ok_with_supplier' => WooProduct::query()->where('match_status', 'ok')->where('supplier_matches', '>', 0)->count(),
            'manage_stock' => WooProduct::query()->where('manage_stock', true)->count(),
        ];
    }

    public static function forCurrentShop(): self
    {
        return new self(WooClient::make(ShopConnection::current()));
    }
}
