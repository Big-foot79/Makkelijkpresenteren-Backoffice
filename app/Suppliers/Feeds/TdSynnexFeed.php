<?php

namespace App\Suppliers\Feeds;

use App\Models\JobRun;
use App\Suppliers\Files\DelimitedFile;
use App\Suppliers\Files\RemoteFiles;
use App\Suppliers\Writers\StockWriter;
use App\Support\Parse;
use RuntimeException;

/**
 * TD Synnex DataPack Pro (see docs/LEVERANCIERS.md and the DataPack Pro manual).
 *
 * Files: <account>_A_yyyymmdd.zip (MaterialFile, CustSpecific, ...) and <account>_C_yyyymmdd.zip
 * (StockFile), daily around 08:00. ANSI text, semicolon, no header. Everything links on Matnr.
 */
class TdSynnexFeed extends Feed
{
    private const ENCODING = 'Windows-1252';

    /** OnHoldFlag (MaterialFile column 29) to our status. */
    private const ON_HOLD = [
        '01' => 'eol',
        '02' => 'eol_with_follower',
        '61' => 'eol',
        '39' => 'allocation',
        '40' => 'allocation',
        '50' => 'not_deliverable',
    ];

    public function kinds(): array
    {
        return ['catalog', 'stock'];
    }

    protected function defaultSchedule(): array
    {
        return ['catalog' => '15 9 * * *', 'stock' => '20 9 * * *'];
    }

    protected function importKind(string $kind, JobRun $run, RemoteFiles $files): string
    {
        return $kind === 'catalog' ? $this->catalog($run, $files) : $this->stock($run, $files);
    }

    private function catalog(JobRun $run, RemoteFiles $files): string
    {
        $file = $files->latest('/_A_\d{8}\.zip$/i');
        $this->logFile($run, $file);

        $zip = $files->download($file['path']);
        $material = $this->entry($files->unzip($zip, '/_MaterialFile_.*\.txt$/i'), 'MaterialFile');
        $prices = $files->unzip($zip, '/_CustSpecific_\d{8}\.txt$/i');

        $writer = $this->items($run);

        foreach ((new DelimitedFile($material, ';', self::ENCODING))->rows() as $r) {
            $writer->add([
                'sku' => $r[0] ?? null,
                'description' => Parse::text($r[2] ?? null, 500),
                'long_description' => Parse::text($r[3] ?? null),
                'mpn' => Parse::text($r[4] ?? null, 64),
                // Global manufacturer name is consistent across countries; the local one is the fallback.
                'brand' => Parse::text($r[6] ?? null, 100) ?? Parse::text($r[5] ?? null, 100),
                'category' => Parse::text($r[7] ?? null, 150),
                'is_kit' => strtoupper(trim($r[8] ?? '')) === 'Y',
                'ean' => Parse::ean($r[9] ?? null),
                'weight_kg' => Parse::decimal($r[19] ?? null),
                'msrp' => Parse::decimal($r[20] ?? null),
                'currency' => Parse::text($r[21] ?? null, 3) ?? 'EUR',
                'status' => self::ON_HOLD[trim($r[29] ?? '')] ?? null,
                'follower_sku' => Parse::text($r[30] ?? null, 64),
            ]);
        }

        $summary = $writer->finish();

        if ($prices === []) {
            $run->warning('Geen CustSpecific-bestand in het pakket; inkoopprijzen niet bijgewerkt.');

            return $summary.', geen prijzen';
        }

        $priceRows = [];
        foreach ((new DelimitedFile(reset($prices), ';', self::ENCODING))->rows() as $r) {
            $sku = trim($r[1] ?? '');
            $price = Parse::decimal($r[2] ?? null);

            if ($sku !== '' && $price !== null) {
                $priceRows[$sku] = [
                    'net_price' => $price,
                    'currency' => Parse::text($r[3] ?? null, 3) ?? 'EUR',
                    'surcharge' => Parse::decimal($r[4] ?? null),
                ];
            }
        }

        $updated = $writer->updatePrices($priceRows);
        $run->info(sprintf('Inkoopprijzen: %s bijgewerkt', number_format($updated, 0, ',', '.')));

        return $summary.sprintf(', %s prijzen', number_format($updated, 0, ',', '.'));
    }

    private function stock(JobRun $run, RemoteFiles $files): string
    {
        $file = $files->latest('/_C_\d{8}\.zip$/i');
        $this->logFile($run, $file);

        $zip = $files->download($file['path']);
        $path = $this->entry($files->unzip($zip, '/_StockFile_.*\.txt$/i'), 'StockFile');

        // TD Synnex marks "on allocation" and "delivery overdue" with fixed fake dates that change
        // every year, so they live in the supplier settings.
        $specialDates = array_filter([
            $this->supplier->setting('special_date_allocation', '17.11.2016') => 'allocation',
            $this->supplier->setting('special_date_overdue', '15.12.2016') => 'overdue',
        ]);

        return $this->withStock($run, $file['modified'], function (StockWriter $writer) use ($path, $specialDates) {
            $oldestRealDate = now('Europe/Amsterdam')->subMonths(3)->startOfDay();

            foreach ((new DelimitedFile($path, ';', self::ENCODING))->rows() as $r) {
                $rawDate = trim($r[2] ?? '');
                $note = $specialDates[$rawDate] ?? null;
                $date = $note ? null : Parse::date($rawDate, 'd.m.Y');

                // A "next delivery" date long in the past is not a real date either.
                if ($date && $date->lt($oldestRealDate)) {
                    [$date, $note] = [null, 'overdue'];
                }

                $writer->add([
                    'sku' => $r[0] ?? null,
                    'quantity' => Parse::int($r[1] ?? null, 0),
                    'next_date' => $date?->toDateString(),
                    'location' => Parse::text($r[4] ?? null, 20),
                    'availability' => Parse::text($r[5] ?? null, 20),
                    'note' => $note,
                ]);
            }
        });
    }

    /** @param  array<string, string>  $entries */
    private function entry(array $entries, string $what): string
    {
        if ($entries === []) {
            throw new RuntimeException("Geen {$what} gevonden in het ZIP-bestand van TD Synnex.");
        }

        return reset($entries);
    }
}
