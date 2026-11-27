<?php

namespace App\Suppliers\Feeds;

use App\Models\JobRun;
use App\Suppliers\Files\DelimitedFile;
use App\Suppliers\Files\RemoteFiles;
use App\Suppliers\Writers\StockWriter;
use App\Support\Parse;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * ALSO (SFTP). Stock: stock.txt, tab-separated with header, refreshed several times a day.
 * Catalogue: a ZIP with one text file. ALSO lets us choose the columns of pricelist-1 and -2:
 * - with a header row (ProductID, EAN, NetPrice, NetRetailPrice, ...): read by column name;
 * - without a header (the old n8n layout of pricelist-2): read by position.
 * Which file is used is the setting catalog_file (see docs/LEVERANCIERS.md).
 */
class AlsoFeed extends Feed
{
    public function kinds(): array
    {
        return ['catalog', 'stock'];
    }

    protected function defaultSchedule(): array
    {
        return ['catalog' => '15 6 * * *', 'stock' => '30 7 * * *'];
    }

    protected function importKind(string $kind, JobRun $run, RemoteFiles $files): string
    {
        return $kind === 'catalog' ? $this->catalog($run, $files) : $this->stock($run, $files);
    }

    private function catalog(JobRun $run, RemoteFiles $files): string
    {
        $file = $files->file($this->supplier->setting('catalog_file', 'pricelist-2.txt.zip'));
        $this->logFile($run, $file);

        $entries = $files->unzip($files->download($file['path']), '/\.txt$/i');

        if ($entries === []) {
            throw new RuntimeException('Geen tekstbestand gevonden in de prijslijst van ALSO.');
        }

        $path = reset($entries);
        $firstLine = (string) fgets(fopen($path, 'r'));
        $writer = $this->items($run);

        if (stripos($firstLine, 'ProductID') !== false) {
            $run->info('Prijslijst met kopregel: kolommen op naam gelezen');
            $this->catalogByName($path, $firstLine, $writer, $run);
        } else {
            $this->catalogByPosition($path, $writer);
        }

        return $writer->finish();
    }

    /** The layout we chose ourselves at ALSO, with a header row. */
    private function catalogByName(string $path, string $firstLine, $writer, JobRun $run): void
    {
        $counts = [';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t"), ',' => substr_count($firstLine, ',')];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $sample = (string) file_get_contents($path, length: 65536);
        $sample = substr($sample, 0, strrpos($sample, "\n") ?: strlen($sample));
        $encoding = mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';

        $missing = array_diff(['productid', 'ean', 'netprice'], array_map(fn ($h) => strtolower(trim($h, " \t\n\r\"\xEF\xBB\xBF")), str_getcsv($firstLine, $delimiter)));

        if ($missing !== []) {
            throw new RuntimeException('Kolommen ontbreken in de prijslijst van ALSO: '.implode(', ', $missing));
        }

        $file = new DelimitedFile($path, $delimiter, $encoding, quoted: str_contains($firstLine, '"'), header: true);

        foreach ($file->rows() as $r) {
            $r = array_change_key_case(array_combine(
                array_map(fn ($k) => trim((string) $k, " \t\"\xEF\xBB\xBF"), array_keys($r)),
                array_values($r),
            ), CASE_LOWER);

            $get = function (string ...$names) use ($r) {
                foreach ($names as $name) {
                    if (isset($r[$name]) && trim((string) $r[$name]) !== '') {
                        return $r[$name];
                    }
                }

                return null;
            };

            $writer->add([
                'sku' => Parse::text($get('productid')),
                'ean' => Parse::ean($get('ean')),
                'mpn' => Parse::text($get('manufacturerpartnumber'), 64),
                // ALSO spells this column "ManfacturerName"; accept both.
                'brand' => Parse::text($get('manfacturername', 'manufacturername'), 100),
                'description' => Parse::description($get('shortdescription'), 500),
                'net_price' => Parse::decimal($get('netprice')),
                'msrp' => Parse::decimal($get('netretailprice')),
                'surcharge' => Parse::decimal($get('recyclingfee')),
                'category' => Parse::text(collect([$get('categorytext1'), $get('categorytext2'), $get('categorytext3')])
                    ->map(fn ($c) => trim((string) $c))->filter()->implode(' > '), 150),
                'status' => self::endOfLife($get('endoflife')) ? 'eol' : null,
                'warranty_months' => Parse::int($get('warrantymonths')),
                'currency' => 'EUR',
            ]);
        }
    }

    /** The old layout without header (pricelist-2 as n8n read it). */
    private function catalogByPosition(string $path, $writer): void
    {
        foreach ((new DelimitedFile($path, ';'))->rows() as $r) {
            if (count($r) < 16) {
                $writer->add([]); // counted as skipped

                continue;
            }

            $writer->add([
                'sku' => $r[0],
                'ean' => Parse::ean($r[1]),
                'description' => Parse::description($r[5], 500),
                'net_price' => Parse::decimal($r[7]) ?? Parse::decimal($r[15]),
                'mpn' => Parse::text($r[8], 64),
                'brand' => Parse::text($r[9], 100),
                'warranty_months' => Parse::int($r[13]),
                'currency' => 'EUR',
            ]);
        }
    }

    /** EndOfLife as a flag (Y, J, X, 1, true) or as a date that has passed. */
    private static function endOfLife(mixed $value): bool
    {
        $v = strtolower(trim((string) $value));

        if ($v === '' || in_array($v, ['n', 'nee', 'no', '0', 'false'], true)) {
            return false;
        }

        if (in_array($v, ['y', 'yes', 'j', 'ja', 'x', '1', 'true'], true)) {
            return true;
        }

        foreach (['Ymd', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'd/m/Y'] as $format) {
            if ($date = Parse::date($v, $format)) {
                return $date->lte(now());
            }
        }

        return false;
    }

    private function stock(JobRun $run, RemoteFiles $files): string
    {
        $file = $files->file($this->supplier->setting('stock_file', 'stock.txt'));
        $this->logFile($run, $file);

        $path = $files->download($file['path']);

        return $this->withStock($run, $file['modified'], function (StockWriter $writer) use ($path) {
            foreach ((new DelimitedFile($path, "\t", header: true))->rows() as $r) {
                $next = Parse::date($r['AvailableNextDate'] ?? null, 'Ymd');

                $writer->add([
                    'sku' => $r['ProductID'] ?? null,
                    'quantity' => Parse::int($r['AvailableQuantity'] ?? null, 0),
                    'next_date' => $next?->toDateString(),
                    'next_quantity' => $next ? Parse::int($r['AvailableNextQuantity'] ?? null) : null,
                    'as_of' => $this->asOf($r['AvailabilityDate'] ?? null, $r['AvailabilityTime'] ?? null),
                ]);
            }
        });
    }

    /** Date + time as delivered by ALSO, in Dutch local time (summer and winter). */
    private function asOf(?string $date, ?string $time): ?CarbonImmutable
    {
        $date = trim((string) $date);
        $time = str_pad(trim((string) $time), 6, '0', STR_PAD_LEFT);

        if (strlen($date) !== 8) {
            return null;
        }

        return Parse::date($date.$time, 'YmdHis');
    }
}
