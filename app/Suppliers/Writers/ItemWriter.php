<?php

namespace App\Suppliers\Writers;

use App\Models\JobRun;
use App\Models\Supplier;
use App\Models\SupplierItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes catalogue rows in chunks (upsert on supplier + sku). After a complete catalogue import,
 * items that were not in the file are marked as no longer listed, unless the file was
 * suspiciously small compared to what we had.
 */
class ItemWriter
{
    private const CHUNK = 1000;

    /** Columns an import may set; anything else is ignored. */
    private const COLUMNS = [
        'ean', 'mpn', 'brand', 'description', 'long_description', 'category', 'weight_kg',
        'msrp', 'net_price', 'surcharge', 'currency', 'warranty_months', 'is_kit', 'status', 'follower_sku',
    ];

    private array $buffer = [];

    private array $updateColumns = [];

    private int $written = 0;

    private int $skipped = 0;

    private int $placeholderEans = 0;

    private readonly CarbonImmutable $startedAt;

    public function __construct(private readonly Supplier $supplier, private readonly JobRun $run)
    {
        $this->startedAt = CarbonImmutable::now()->startOfSecond();
    }

    /** Adds one item. Rows without a sku are counted as skipped. */
    public function add(array $item): void
    {
        $sku = trim((string) ($item['sku'] ?? ''));

        if ($sku === '') {
            $this->skipped++;

            return;
        }

        $values = array_intersect_key($item, array_flip(self::COLUMNS));

        // Placeholder EANs built from the supplier's own article number (TD Synnex: 3265140 ->
        // 3265140000007) never match a real product; keep them out.
        if (! empty($values['ean']) && ctype_digit($sku) && strlen($sku) >= 5
            && str_starts_with(ltrim($values['ean'], '0'), ltrim($sku, '0'))) {
            $values['ean'] = null;
            $this->placeholderEans++;
        }
        $this->updateColumns += array_flip(array_keys($values));

        $this->buffer[$sku] = $values + [
            'supplier_id' => $this->supplier->id,
            'sku' => mb_substr($sku, 0, 64),
            'is_listed' => true,
            'first_seen_at' => $this->startedAt,
            'last_seen_at' => $this->startedAt,
            'created_at' => $this->startedAt,
            'updated_at' => $this->startedAt,
        ];

        if (array_key_exists('net_price', $values)) {
            $this->buffer[$sku]['price_updated_at'] = $this->startedAt;
        }

        if (count($this->buffer) >= self::CHUNK) {
            $this->flush();
        }
    }

    /**
     * Updates prices of items that already exist (e.g. TD Synnex CustSpecific, which has no
     * descriptions). Unknown skus are ignored instead of creating empty items.
     *
     * @param  array<string, array{net_price: ?float, surcharge?: ?float, currency?: string}>  $prices  sku => values
     */
    public function updatePrices(array $prices): int
    {
        $updated = 0;

        foreach (array_chunk($prices, self::CHUNK, true) as $chunk) {
            $existing = SupplierItem::where('supplier_id', $this->supplier->id)
                ->whereIn('sku', array_map('strval', array_keys($chunk)))
                ->pluck('sku')
                ->all();

            $rows = [];
            foreach ($existing as $sku) {
                $rows[] = [
                    'supplier_id' => $this->supplier->id,
                    'sku' => $sku,
                    'net_price' => $chunk[$sku]['net_price'],
                    'surcharge' => $chunk[$sku]['surcharge'] ?? null,
                    'currency' => $chunk[$sku]['currency'] ?? 'EUR',
                    'price_updated_at' => $this->startedAt,
                    'updated_at' => $this->startedAt,
                ];
            }

            if ($rows) {
                SupplierItem::upsert($rows, ['supplier_id', 'sku'], ['net_price', 'surcharge', 'currency', 'price_updated_at', 'updated_at']);
                $updated += count($rows);
            }
        }

        return $updated;
    }

    /**
     * Writes what is left and, for a complete catalogue, marks items missing from the file as
     * no longer listed. Returns a one-line summary for the run.
     */
    public function finish(bool $completeCatalogue = true): string
    {
        $this->flush();

        $delisted = 0;
        if ($completeCatalogue) {
            $listedBefore = SupplierItem::where('supplier_id', $this->supplier->id)
                ->where('is_listed', true)
                ->where('last_seen_at', '<', $this->startedAt)
                ->count() + $this->written;

            $ratio = (float) config('backoffice.stock.min_rows_ratio');

            if ($listedBefore > 0 && $this->written < $listedBefore * $ratio) {
                $this->run->warning(sprintf(
                    'Catalogus bevat maar %s van de %s artikelen; niets als vervallen gemarkeerd.',
                    number_format($this->written, 0, ',', '.'),
                    number_format($listedBefore, 0, ',', '.'),
                ));
            } else {
                $delisted = SupplierItem::where('supplier_id', $this->supplier->id)
                    ->where('is_listed', true)
                    ->where('last_seen_at', '<', $this->startedAt)
                    ->update(['is_listed' => false, 'updated_at' => now()]);
            }

            $this->supplier->forceFill(['catalog_imported_at' => now()])->save();
        }

        $summary = sprintf('%s artikelen', number_format($this->written, 0, ',', '.'));

        if ($delisted) {
            $summary .= sprintf(', %s vervallen', number_format($delisted, 0, ',', '.'));
        }

        if ($this->skipped) {
            $summary .= sprintf(', %s regels overgeslagen', number_format($this->skipped, 0, ',', '.'));
        }

        if ($this->placeholderEans) {
            $this->run->info(sprintf('%s nep-EANs (afgeleid van het artikelnummer) leeg gelaten', number_format($this->placeholderEans, 0, ',', '.')));
        }

        $this->run->info('Catalogus: '.$summary);

        return $summary;
    }

    public function written(): int
    {
        return $this->written;
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        // Make every row have the same keys, as a multi-row insert requires.
        $keys = array_keys(array_merge(...array_values(array_map(fn ($row) => array_fill_keys(array_keys($row), true), $this->buffer))));
        $rows = array_map(fn ($row) => array_merge(array_fill_keys($keys, null), $row), array_values($this->buffer));

        $update = array_merge(array_keys($this->updateColumns), ['is_listed', 'last_seen_at', 'updated_at']);
        if (in_array('price_updated_at', $keys, true)) {
            $update[] = 'price_updated_at';
        }

        DB::transaction(fn () => SupplierItem::upsert($rows, ['supplier_id', 'sku'], $update));

        $this->written += count($rows);
        $this->buffer = [];
    }
}
