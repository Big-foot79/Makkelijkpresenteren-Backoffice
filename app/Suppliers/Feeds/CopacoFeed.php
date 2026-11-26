<?php

namespace App\Suppliers\Feeds;

use App\Models\JobRun;
use App\Suppliers\Files\DelimitedFile;
use App\Suppliers\Files\RemoteFiles;
use App\Suppliers\Writers\StockWriter;
use App\Support\Parse;

/**
 * Copaco: one comma-separated CSV (Copaco_prijslijst_<klant>.csv) with a 15-line header block,
 * holding articles, prices and stock. Copaco orders on its own article number or the manufacturer
 * number, never on EAN, so the sku must be kept for the order connection.
 */
class CopacoFeed extends Feed
{
    public function kinds(): array
    {
        return ['full'];
    }

    protected function defaultSchedule(): array
    {
        return ['full' => '30 5 * * *'];
    }

    protected function importKind(string $kind, JobRun $run, RemoteFiles $files): string
    {
        $file = $files->file($this->supplier->setting('file', 'Copaco_prijslijst_114116.csv'));
        $this->logFile($run, $file);

        $path = $files->download($file['path']);
        $skip = (int) $this->supplier->setting('skip_lines', 15);
        $items = $this->items($run);

        $stock = $this->withStock($run, $file['modified'], function (StockWriter $stock) use ($path, $skip, $items) {
            foreach ((new DelimitedFile($path, ',', quoted: true, skipLines: $skip))->rows() as $r) {
                $items->add([
                    'sku' => $r[0] ?? null,
                    'mpn' => Parse::text($r[1] ?? null, 64),
                    'brand' => Parse::text($r[2] ?? null, 100),
                    'description' => Parse::text($r[3] ?? null, 500),
                    'net_price' => Parse::decimal($r[5] ?? null),
                    'category' => Parse::text($r[7] ?? null, 150),
                    'ean' => Parse::ean($r[9] ?? null),
                    'currency' => 'EUR',
                ]);

                $stock->add([
                    'sku' => $r[0] ?? null,
                    'quantity' => Parse::int($r[6] ?? null, 0),
                    'availability' => Parse::text($r[10] ?? null, 20),
                ]);
            }
        });

        return $items->finish().', '.$stock;
    }
}
