<?php

namespace App\Suppliers\Feeds;

use App\Models\JobRun;
use App\Models\Supplier;
use App\Suppliers\EanResolver;
use App\Suppliers\Files\RemoteFiles;
use App\Suppliers\Writers\ItemWriter;
use App\Suppliers\Writers\StockWriter;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Throwable;

/**
 * How one supplier's files are read. Each feed offers one or more kinds:
 * - catalog: articles and prices
 * - stock: stock list
 * - full: one file with articles, prices and stock (Travion, Copaco)
 */
abstract class Feed
{
    public const KIND_LABELS = [
        'catalog' => 'catalogus',
        'stock' => 'voorraad',
        'full' => 'catalogus en voorraad',
    ];

    public function __construct(protected readonly Supplier $supplier) {}

    /** @return list<string> */
    abstract public function kinds(): array;

    /** Default schedule per kind (cron, Europe/Amsterdam); overridable in the supplier settings. */
    abstract protected function defaultSchedule(): array;

    abstract protected function importKind(string $kind, JobRun $run, RemoteFiles $files): string;

    public function import(string $kind, JobRun $run): string
    {
        if (! in_array($kind, $this->kinds(), true)) {
            throw new InvalidArgumentException("{$this->supplier->name} kent geen import '{$kind}'.");
        }

        $summary = $this->importKind($kind, $run, new RemoteFiles($this->supplier));

        // Articles without EAN (or with a fake one that was dropped): look it up by part number.
        if ($kind !== 'stock') {
            $resolved = app(EanResolver::class)->forSupplier($this->supplier);

            if ($resolved > 0) {
                $run->info("{$resolved} EAN's aangevuld via het fabrikantnummer");
                $summary .= ", {$resolved} EAN's via fabrikantnummer";
            }
        }

        return $summary;
    }

    public function schedule(string $kind): ?string
    {
        return $this->supplier->setting('cron_'.$kind, $this->defaultSchedule()[$kind] ?? null);
    }

    protected function items(JobRun $run): ItemWriter
    {
        return new ItemWriter($this->supplier, $run);
    }

    /** Runs the callback with a stock writer and throws its rows away if anything fails. */
    protected function withStock(JobRun $run, CarbonInterface $asOf, callable $callback): string
    {
        $writer = new StockWriter($this->supplier, $run, $asOf);

        try {
            $callback($writer);

            return $writer->finish();
        } catch (Throwable $e) {
            $writer->discard();

            throw $e;
        }
    }

    protected function logFile(JobRun $run, array $file): void
    {
        $run->info(sprintf(
            'Bestand %s (%s kB, %s)',
            basename($file['path']),
            number_format($file['size'] / 1024, 0, ',', '.'),
            $file['modified']->format('d-m-Y H:i'),
        ));
    }
}
