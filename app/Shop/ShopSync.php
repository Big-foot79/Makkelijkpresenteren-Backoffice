<?php

namespace App\Shop;

use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\JobRun;
use App\Models\Product;
use App\Models\ShopConnection;
use App\Models\Task;
use App\Models\WooProduct;
use App\Suppliers\StockAvailability;
use Illuminate\Support\Collection;

/**
 * Pushes what the backoffice decides to WooCommerce: price, sale price and availability.
 * Only fields that differ from what the shop has are sent, in batches of 100.
 * A product with an open sync conflict is left alone until someone decides.
 *
 * Availability in the shop:
 *   ample / limited -> stock_status "instock", backorder -> "onbackorder", unknown -> untouched.
 *   The label ("Ruim op voorraad", ...) and expected date go into meta fields the theme can show.
 */
class ShopSync
{
    public const STOCK_STATUS = [
        'ample' => 'instock',
        'limited' => 'instock',
        'backorder' => 'onbackorder',
    ];

    public const FIELD_LABELS = [
        'global_unique_id' => 'EAN (GTIN-veld)',
        'regular_price' => 'Prijs',
        'sale_price' => 'Actieprijs',
        'stock_status' => 'Voorraadstatus',
        '_mp_stock_status' => 'Beschikbaarheid',
        '_mp_stock_label' => 'Beschikbaarheidstekst',
        '_mp_stock_next_date' => 'Verwachte datum',
    ];

    public function __construct(private readonly StockAvailability $availability) {}

    /**
     * @param  list<int>|null  $productIds  null = every linked product
     * @param  bool  $dryRun  only report what would change
     */
    public function run(JobRun $run, ?array $productIds = null, bool $dryRun = false, ?WooClient $client = null): string
    {
        $client ??= WooClient::make();
        $dryRun = $dryRun || ! $client->connection()->canWrite();

        if ($dryRun) {
            $run->info('Proefrun: er wordt niets naar de webshop geschreven.');
        }

        $blocked = Task::query()->active()->where('kind', TaskKind::SyncConflict)
            ->where('subject_type', (new Product)->getMorphClass())->pluck('subject_id')->flip();

        $stats = ['checked' => 0, 'unchanged' => 0, 'changed' => 0, 'sent' => 0, 'failed' => 0, 'skipped_conflict' => 0];
        $byAvailability = array_fill_keys(array_keys(StockAvailability::STATUS_LABELS), 0);
        $toBackorder = 0;
        $examples = [];

        Product::query()
            ->whereNotNull('woo_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds))
            ->with('wooProduct')
            ->chunkById(500, function (Collection $products) use ($run, $client, $dryRun, $blocked, &$stats, &$examples, &$byAvailability, &$toBackorder) {
                $this->refreshAvailability($products);
                $updates = [];

                foreach ($products as $product) {
                    $stats['checked']++;
                    $byAvailability[$product->availability ?? 'unknown']++;

                    if (isset($blocked[$product->id])) {
                        $stats['skipped_conflict']++;

                        continue;
                    }

                    $desired = $this->desiredState($product);
                    $current = $product->wooProduct ? $this->mirrorState($product->wooProduct) : [];
                    $diff = array_filter($desired, fn ($value, $key) => ($current[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

                    if ($diff === []) {
                        $stats['unchanged']++;

                        if ($product->woo_synced_state !== $desired && ! $dryRun) {
                            $product->update(['woo_synced_state' => $desired, 'woo_synced_at' => now()]);
                        }

                        continue;
                    }

                    $stats['changed']++;

                    if (($diff['stock_status'] ?? null) === 'onbackorder' && ($current['stock_status'] ?? null) === 'instock') {
                        $toBackorder++;
                    }

                    if (count($examples) < 15) {
                        $examples[] = ($product->sku ?? $product->ean).': '.collect($diff)
                            ->map(fn ($new, $key) => (self::FIELD_LABELS[$key] ?? $key).' '.self::show($current[$key] ?? null, $key).' → '.self::show($new, $key))
                            ->implode(', ');
                    }

                    $updates[$product->woo_id] = [$product, $desired, $this->payload($product, $diff)];
                }

                if ($dryRun || $updates === []) {
                    return;
                }

                $results = $client->batchUpdateProducts(array_column($updates, 2));

                foreach ($updates as $wooId => [$product, $desired]) {
                    $result = $results[$wooId] ?? null;

                    if ($result === null || isset($result['error'])) {
                        $stats['failed']++;
                        $run->warning("Woo {$wooId} ({$product->sku}): ".($result['error']['message'] ?? 'geen antwoord'));

                        continue;
                    }

                    $product->update(['woo_synced_state' => $desired, 'woo_synced_at' => now()]);
                    $mirror = (new WooProductData($result))->mirror();
                    $mirror['managed_meta'] = json_encode($mirror['managed_meta']);
                    unset($mirror['woo_id']);
                    WooProduct::query()->where('woo_id', $wooId)->update($mirror);
                    $stats['sent']++;
                }
            });

        $run->info('Beschikbaarheid: '.collect($byAvailability)
            ->map(fn ($n, $status) => StockAvailability::STATUS_LABELS[$status].' '.$n)->implode(', '));

        if ($toBackorder) {
            $run->info("{$toBackorder} producten gaan van 'op voorraad' naar 'nalevering' (geen voorraad bij een leverancier)");
        }

        $run->info('Voorbeelden:');

        foreach ($examples as $example) {
            $run->info($example);
        }

        if ($stats['skipped_conflict']) {
            $run->warning("{$stats['skipped_conflict']} producten overgeslagen vanwege een open sync-conflict");
        }

        return $dryRun
            ? sprintf('Proefrun: %d producten bekeken, %d zouden worden bijgewerkt, %d al gelijk', $stats['checked'], $stats['changed'], $stats['unchanged'])
            : sprintf('%d producten bekeken, %d bijgewerkt in de webshop, %d al gelijk%s', $stats['checked'], $stats['sent'], $stats['unchanged'], $stats['failed'] ? ", {$stats['failed']} mislukt" : '');
    }

    /** What the shop should show for this product, in WooCommerce's own format. */
    public function desiredState(Product $product): array
    {
        $state = [];

        // An empty price in the backoffice never wipes the shop price.
        if ($product->webshop_price !== null) {
            $state['regular_price'] = WooProductData::price($product->webshop_price);
            $state['sale_price'] = WooProductData::price($product->sale_price);
        }

        $availability = $product->availability ?? 'unknown';

        if (isset(self::STOCK_STATUS[$availability])) {
            $state['stock_status'] = self::STOCK_STATUS[$availability];
        }

        // The EAN goes into WooCommerce's own GTIN field (9.2+) when the shop does not have it there yet.
        if ($product->ean && $product->wooProduct?->ean_source !== 'gtin' && self::shopHasGtinField()) {
            $state['global_unique_id'] = $product->ean;
        }

        $state['_mp_stock_status'] = $availability;
        $state['_mp_stock_label'] = $availability === 'unknown' ? '' : (StockAvailability::STATUS_LABELS[$availability] ?? '');
        $state['_mp_stock_next_date'] = $product->availability_date?->toDateString() ?? '';

        return $state;
    }

    public function mirrorState(WooProduct $woo): array
    {
        return [
            'global_unique_id' => $woo->ean_source === 'gtin' ? $woo->ean : '',
            'regular_price' => WooProductData::price($woo->regular_price),
            'sale_price' => WooProductData::price($woo->sale_price),
            'stock_status' => $woo->stock_status,
        ] + array_map('strval', $woo->managed_meta ?? []);
    }

    /**
     * Fields that were changed in WooCommerce itself: different from what we last sent AND from what
     * we would send now (so our own update coming back as a webhook is never a conflict).
     *
     * @return array<string, array{backoffice: mixed, shop: mixed}>
     */
    public function conflicts(Product $product, WooProduct $woo): array
    {
        if (! $product->woo_synced_state) {
            return [];
        }

        $desired = $this->desiredState($product);
        $shop = $this->mirrorState($woo);
        $conflicts = [];

        foreach ($product->woo_synced_state as $key => $sent) {
            $now = $shop[$key] ?? null;

            if ($now !== $sent && $now !== ($desired[$key] ?? null)) {
                $conflicts[$key] = ['backoffice' => $desired[$key] ?? $sent, 'shop' => $now];
            }
        }

        return $conflicts;
    }

    /**
     * Open (or refresh) one sync-conflict task per product with conflicting fields.
     *
     * @param  iterable<WooProduct>  $wooProducts  with product loaded
     */
    public function detectConflicts(iterable $wooProducts): int
    {
        $count = 0;

        foreach ($wooProducts as $woo) {
            if (! $woo->product) {
                continue;
            }

            $conflicts = $this->conflicts($woo->product, $woo);

            if ($conflicts === []) {
                continue;
            }

            $count++;
            $task = Task::query()->active()->where('kind', TaskKind::SyncConflict)
                ->whereMorphedTo('subject', $woo->product)->first() ?? new Task([
                    'kind' => TaskKind::SyncConflict,
                    'status' => TaskStatus::Open,
                ]);

            $task->fill([
                'title' => 'Gewijzigd in de webshop: '.($woo->product->sku ?? $woo->product->ean).' '.str($woo->product->name)->limit(60),
                'description' => collect($conflicts)->map(fn ($c, $key) => (self::FIELD_LABELS[$key] ?? $key).': backoffice '.self::show($c['backoffice']).', webshop '.self::show($c['shop']))->implode("\n")
                    ."\n\nKies: de backofficewaarde terugzetten in de webshop, of de webshopprijs overnemen. Tot dan wordt dit product niet bijgewerkt.",
                'data' => ['woo_id' => $woo->woo_id, 'fields' => $conflicts],
            ]);
            $task->subject()->associate($woo->product);
            $task->save();
        }

        return $count;
    }

    /** Store the current availability on each product (used by the panel, MCP and the sync). */
    private function refreshAvailability(Collection $products): void
    {
        $availability = $this->availability->forEans($products->pluck('ean')->filter()->all());

        foreach ($products as $product) {
            $a = $product->ean ? $availability->get($product->ean) : null;
            $status = $a['status'] ?? 'unknown';
            $date = $a['next_date'] ?? null;

            if ($product->availability !== $status || $product->availability_date?->toDateString() !== $date) {
                $product->update(['availability' => $status, 'availability_date' => $date, 'availability_updated_at' => now()]);
            }
        }
    }

    private function payload(Product $product, array $diff): array
    {
        $payload = ['id' => $product->woo_id];
        $meta = [];

        foreach ($diff as $key => $value) {
            if (str_starts_with($key, '_mp_')) {
                $meta[] = ['key' => $key, 'value' => $value];
            } else {
                $payload[$key] = $value;
            }
        }

        // WooCommerce ignores stock_status when it counts stock itself; the backoffice decides now.
        if (isset($payload['stock_status']) && $product->wooProduct?->manage_stock) {
            $payload['manage_stock'] = false;
        }

        if ($meta !== []) {
            $payload['meta_data'] = $meta;
        }

        return $payload;
    }

    private const STOCK_STATUS_LABELS = ['instock' => 'op voorraad', 'onbackorder' => 'nalevering', 'outofstock' => 'uitverkocht'];

    private static function shopHasGtinField(): bool
    {
        $version = ShopConnection::current()?->woo_version;

        return $version === null || version_compare($version, '9.2', '>=');
    }

    private static function show(mixed $value, ?string $key = null): string
    {
        if ($value === null || $value === '') {
            return 'leeg';
        }

        return match ($key) {
            '_mp_stock_status' => mb_strtolower(StockAvailability::STATUS_LABELS[$value] ?? (string) $value),
            'stock_status' => self::STOCK_STATUS_LABELS[$value] ?? (string) $value,
            default => (string) $value,
        };
    }
}
