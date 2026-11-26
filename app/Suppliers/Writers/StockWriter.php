<?php

namespace App\Suppliers\Writers;

use App\Models\JobRun;
use App\Models\Supplier;
use App\Models\SupplierStock;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Writes a stock list for one run. Nothing changes for the shop until finish(): only then the
 * run becomes the supplier's current stock. A failed or suspiciously small import therefore
 * never wipes out the stock (lesson from the old delete-then-insert workflows).
 */
class StockWriter
{
    private const CHUNK = 2000;

    private array $buffer = [];

    private int $written = 0;

    private int $skipped = 0;

    public function __construct(
        private readonly Supplier $supplier,
        private readonly JobRun $run,
        private readonly CarbonInterface $asOf,
    ) {}

    public function add(array $line): void
    {
        $sku = trim((string) ($line['sku'] ?? ''));

        if ($sku === '') {
            $this->skipped++;

            return;
        }

        $this->buffer[] = [
            'supplier_id' => $this->supplier->id,
            'job_run_id' => $this->run->id,
            'sku' => mb_substr($sku, 0, 64),
            'location' => $line['location'] ?? null,
            'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
            'next_date' => $line['next_date'] ?? null,
            'next_quantity' => $line['next_quantity'] ?? null,
            'availability' => $line['availability'] ?? null,
            'note' => $line['note'] ?? null,
            'as_of' => $line['as_of'] ?? $this->asOf,
        ];

        if (count($this->buffer) >= self::CHUNK) {
            $this->flush();
        }
    }

    /** Makes this run the current stock, after a sanity check on the number of rows. */
    public function finish(): string
    {
        $this->flush();

        $previous = $this->supplier->current_stock_run_id
            ? SupplierStock::where('job_run_id', $this->supplier->current_stock_run_id)->count()
            : 0;

        $ratio = (float) config('backoffice.stock.min_rows_ratio');

        if ($this->written === 0 || ($previous > 0 && $this->written < $previous * $ratio)) {
            throw new RuntimeException(sprintf(
                'Voorraadlijst afgekeurd: %s regels, vorige lijst had er %s. De vorige voorraad blijft actief.',
                number_format($this->written, 0, ',', '.'),
                number_format($previous, 0, ',', '.'),
            ));
        }

        $keep = array_filter([$this->run->id, $this->supplier->current_stock_run_id]);

        $this->supplier->forceFill([
            'current_stock_run_id' => $this->run->id,
            'stock_as_of' => $this->asOf,
        ])->save();

        // Keep the new and the previous list; older lists go.
        SupplierStock::where('supplier_id', $this->supplier->id)
            ->whereNotIn('job_run_id', $keep)
            ->delete();

        $summary = sprintf('%s voorraadregels', number_format($this->written, 0, ',', '.'));

        if ($this->skipped) {
            $summary .= sprintf(', %s overgeslagen', number_format($this->skipped, 0, ',', '.'));
        }

        $this->run->info(sprintf('Voorraad: %s (stand %s)', $summary, $this->asOf->timezone('Europe/Amsterdam')->format('d-m-Y H:i')));

        return $summary;
    }

    /** Removes the rows of this run after a failure. */
    public function discard(): void
    {
        $this->buffer = [];
        SupplierStock::where('job_run_id', $this->run->id)->delete();
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        SupplierStock::insert($this->buffer);
        $this->written += count($this->buffer);
        $this->buffer = [];
    }
}
