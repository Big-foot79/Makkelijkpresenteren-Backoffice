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
 * Catalogue: pricelist-2.txt.zip, one semicolon file without header (see docs/LEVERANCIERS.md).
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

        $writer = $this->items($run);

        foreach ((new DelimitedFile(reset($entries), ';'))->rows() as $r) {
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

        return $writer->finish();
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
