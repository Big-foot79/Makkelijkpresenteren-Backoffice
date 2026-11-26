<?php

namespace App\Suppliers\Feeds;

use App\Models\JobRun;
use App\Suppliers\Files\DelimitedFile;
use App\Suppliers\Files\RemoteFiles;
use App\Suppliers\Writers\StockWriter;
use App\Support\Parse;

/**
 * Travion: one semicolon file with header in /pricefile/, holding articles, prices and stock.
 * Travion delivers no expected delivery date and no advice price.
 */
class TravionFeed extends Feed
{
    public function kinds(): array
    {
        return ['full'];
    }

    protected function defaultSchedule(): array
    {
        return ['full' => '15 5 * * *'];
    }

    protected function importKind(string $kind, JobRun $run, RemoteFiles $files): string
    {
        $file = $files->latest($this->supplier->setting('file_pattern', '/\.(csv|txt)$/i'));
        $this->logFile($run, $file);

        $path = $files->download($file['path']);
        $items = $this->items($run);

        $stock = $this->withStock($run, $file['modified'], function (StockWriter $stock) use ($path, $items) {
            foreach ((new DelimitedFile($path, ';', header: true))->rows() as $r) {
                $items->add([
                    'sku' => $r['ARTICLENR'] ?? null,
                    'description' => Parse::text($r['DESCRIPTION'] ?? null, 500),
                    'ean' => Parse::ean($r['EANNR'] ?? null),
                    'mpn' => Parse::text($r['OEMNR'] ?? null, 64),
                    'brand' => Parse::text($r['BRAND'] ?? null, 100),
                    'category' => Parse::text($r['UNSPSCDESCRIPTION'] ?? null, 150),
                    'net_price' => Parse::decimal($r['PRICE'] ?? null),
                    'currency' => Parse::text($r['CURRENCY'] ?? null, 3) ?? 'EUR',
                ]);

                $stock->add([
                    'sku' => $r['ARTICLENR'] ?? null,
                    'quantity' => Parse::int($r['STOCK'] ?? null, 0),
                ]);
            }
        });

        return $items->finish().', '.$stock;
    }
}
