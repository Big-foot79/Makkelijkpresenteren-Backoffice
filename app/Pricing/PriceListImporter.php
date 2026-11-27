<?php

namespace App\Pricing;

use App\Models\JobRun;
use App\Models\PriceList;
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

    /** Rows without EAN: look the manufacturer part number up at the suppliers; use it when unambiguous. */
    private function resolveEansByMpn(array $rows, ?string $brand): array
    {
        $missing = array_values(array_unique(array_filter(array_map(
            fn ($r) => $r['ean'] === null && $r['mpn'] ? mb_strtolower($r['mpn']) : null, $rows
        ))));

        $found = [];

        foreach (array_chunk($missing, 500) as $chunk) {
            DB::table('supplier_items')
                ->whereNotNull('ean')
                ->whereIn(DB::raw('lower(mpn)'), $chunk)
                ->when($brand, fn ($q) => $q->whereRaw('lower(brand) = ?', [mb_strtolower($brand)]))
                ->get(['mpn', 'ean'])
                ->each(function ($item) use (&$found) {
                    $found[mb_strtolower($item->mpn)][$item->ean] = true;
                });
        }

        return array_map(function ($row) use ($found) {
            $eans = $row['ean'] === null && $row['mpn'] ? array_keys($found[mb_strtolower($row['mpn'])] ?? []) : [];

            if (count($eans) === 1) {
                $row['ean'] = (string) $eans[0];
                $row['ean_source'] = 'mpn';
            }

            return $row;
        }, $rows);
    }

    public static function path(PriceList $list): string
    {
        return Storage::disk('local')->path($list->file_path);
    }
}
