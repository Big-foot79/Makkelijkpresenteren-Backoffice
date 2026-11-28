<?php

namespace App\Pricing;

use App\Models\JobRun;
use App\Models\PriceList;
use App\Support\Brands;
use App\Support\Parse;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Reads an uploaded price list with the column mapping chosen on screen. */
class PriceListImporter
{
    /** Header words that point to a field; the first match wins. */
    private const GUESSES = [
        'ean' => ['ean', 'gtin', 'barcode', 'upc'],
        'mpn' => ['mpn', 'part number', 'partnumber', 'part no', 'artikelnummer', 'artikelnr', 'art.nr', 'model', 'product code', 'productcode', 'sku'],
        'description' => ['omschrijving', 'description', 'productnaam', 'product name', 'naam', 'name'],
        'msrp' => ['adviesprijs', 'advies', 'msrp', 'rrp', 'srp', 'catalogus', 'list price', 'listprice', 'retail', 'consumentenprijs', 'bruto'],
        'purchase_price' => ['bid', 'netto', 'net price', 'nettoprijs', 'inkoop', 'dealer', 'special', 'project', 'reseller', 'price', 'prijs'],
    ];

    /** @return list<string> */
    public static function headers(PriceList $list): array
    {
        return Spreadsheet::row(self::path($list), $list->sheet, $list->header_row);
    }

    /**
     * Find the header row: of the first rows, the one whose names match the most fields.
     * Price lists by mail often start with a title, a date or a confidentiality line.
     */
    public static function detectHeaderRow(PriceList $list): int
    {
        $best = [1, 0];

        foreach (Spreadsheet::rows(self::path($list), $list->sheet) as $number => $cells) {
            $score = count(self::guess($cells));

            if ($score > $best[1]) {
                $best = [$number, $score];
            }

            if ($number >= 25) {
                break;
            }
        }

        return $best[0];
    }

    /**
     * Guess field => column index from the header names.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function guess(array $headers): array
    {
        $lower = array_map(fn ($h) => mb_strtolower(trim($h)), $headers);
        $taken = [];
        $columns = [];

        foreach (self::GUESSES as $field => $words) {
            foreach ($words as $word) {
                foreach ($lower as $index => $header) {
                    if ($header !== '' && ! isset($taken[$index]) && str_contains($header, $word)) {
                        $columns[$field] = $index;
                        $taken[$index] = true;

                        continue 3;
                    }
                }
            }
        }

        return $columns;
    }

    public function import(PriceList $list, JobRun $run): string
    {
        $columns = array_filter($list->columns ?? [], fn ($c) => $c !== null && $c !== '');

        if (! isset($columns['ean']) && ! isset($columns['mpn'])) {
            throw new RuntimeException('Kies een kolom voor EAN of artikelnummer fabrikant.');
        }

        if (! isset($columns['msrp']) && ! isset($columns['purchase_price'])) {
            throw new RuntimeException('Kies een kolom voor adviesprijs of inkoop-/bidprijs.');
        }

        $rows = [];
        $skipped = 0;

        foreach (Spreadsheet::rows(self::path($list), $list->sheet) as $number => $cells) {
            if ($number <= $list->header_row) {
                continue;
            }

            $get = fn (string $field) => isset($columns[$field]) ? ($cells[(int) $columns[$field]] ?? null) : null;

            $row = [
                'ean' => Parse::ean($get('ean')),
                'mpn' => Parse::text($get('mpn'), 100),
                'description' => Parse::description($get('description'), 255),
                'msrp' => Parse::decimal($get('msrp')),
                'purchase_price' => Parse::decimal($get('purchase_price')),
            ];

            if (($row['ean'] === null && $row['mpn'] === null) || ($row['msrp'] === null && $row['purchase_price'] === null)) {
                $skipped++;

                continue;
            }

            $row['ean_source'] = $row['ean'] ? 'list' : null;
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException("Geen bruikbare regels gevonden ({$skipped} overgeslagen). Klopt de kopregel en de kolomkeuze?");
        }

        $rows = $this->resolveEansByMpn($rows, $list->brand);
        $withoutEan = count(array_filter($rows, fn ($r) => $r['ean'] === null));
        $byMpn = count(array_filter($rows, fn ($r) => $r['ean_source'] === 'mpn'));

        DB::transaction(function () use ($list, $rows, $withoutEan) {
            $list->items()->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                $list->items()->insert(array_map(fn ($r) => $r + ['price_list_id' => $list->id], $chunk));
            }

            $list->update([
                'status' => 'imported',
                'rows_imported' => count($rows),
                'rows_without_ean' => $withoutEan,
                'imported_at' => now(),
            ]);
        });

        $run->info(count($rows)." regels ingelezen, {$skipped} overgeslagen (geen nummer of prijs)");
        $run->info("{$byMpn} EAN's gevonden via het artikelnummer bij de leveranciers");

        if ($withoutEan) {
            $run->warning("{$withoutEan} regels zonder EAN: die tellen pas mee als een leverancier het artikelnummer met EAN voert");
        }

        return sprintf('%d regels ingelezen, waarvan %d zonder EAN', count($rows), $withoutEan);
    }

    /**
     * Rows without EAN: look them up when exactly one EAN fits. Keys tried per row: the part number,
     * and the model in the description ("Yealink MVC860-C5-000" -> "MVC860-C5-000"; Midwich puts
     * an order number in the part number column and the model in the name). Looked up at the
     * suppliers (manufacturer part number) and in the webshop (product SKU).
     */
    private function resolveEansByMpn(array $rows, ?string $brand): array
    {
        $keysPerRow = [];

        foreach ($rows as $i => $row) {
            if ($row['ean'] !== null) {
                continue;
            }

            $keys = array_filter([$row['mpn'], self::modelFromDescription($row['description'], $brand)]);
            $keysPerRow[$i] = array_values(array_unique(array_map(fn ($k) => mb_strtolower(trim($k)), $keys)));
        }

        $allKeys = array_values(array_unique(array_merge([], ...array_values($keysPerRow))));
        $found = [];

        foreach (array_chunk($allKeys, 500) as $chunk) {
            DB::table('supplier_items')
                ->whereNotNull('ean')
                ->where('is_listed', true)
                ->whereIn(DB::raw('lower(mpn)'), $chunk)
                ->when($brand, fn ($q) => $q->whereRaw('lower(brand) = ?', [mb_strtolower((string) Brands::canonical($brand))]))
                ->get(['mpn as k', 'ean'])
                ->concat(DB::table('products')->whereNotNull('ean')->whereIn(DB::raw('lower(sku)'), $chunk)->get(['sku as k', 'ean']))
                ->each(function ($hit) use (&$found) {
                    $found[mb_strtolower($hit->k)][$hit->ean] = true;
                });
        }

        foreach ($keysPerRow as $i => $keys) {
            $eans = [];

            foreach ($keys as $key) {
                // Short keys ("100", "A1") match too easily.
                if (mb_strlen($key) >= 4) {
                    $eans += $found[$key] ?? [];
                }
            }

            if (count($eans) === 1) {
                $rows[$i]['ean'] = (string) array_key_first($eans);
                $rows[$i]['ean_source'] = 'mpn';
            }
        }

        return $rows;
    }

    /** The model in a description like "Yealink MVC860-C5-000": everything after the brand (or the first word). */
    private static function modelFromDescription(?string $description, ?string $brand): ?string
    {
        $description = trim((string) $description);

        $model = $brand && stripos($description, $brand.' ') === 0
            ? substr($description, strlen($brand) + 1)
            : (str_contains($description, ' ') ? substr($description, strpos($description, ' ') + 1) : null);

        return $model !== null && preg_match('/\d/', $model) ? trim($model) : null;
    }

    public static function path(PriceList $list): string
    {
        return Storage::disk('local')->path($list->file_path);
    }
}
