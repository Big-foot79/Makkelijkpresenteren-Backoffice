<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\ShopConnection;
use App\Models\Supplier;
use App\Suppliers\Feeds\Feed;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports one kind (catalog, stock, full) for one supplier as a visible run.
 * Unique per supplier + kind, so a manual import never overlaps with the scheduled one.
 */
class ImportSupplierFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public int $supplierId, public string $kind) {}

    public function uniqueId(): string
    {
        return $this->supplierId.':'.$this->kind;
    }

    public function handle(): void
    {
        $supplier = Supplier::findOrFail($this->supplierId);

        JobRun::track(
            type: "import.{$supplier->code}.{$this->kind}",
            label: $supplier->name.' '.(Feed::KIND_LABELS[$this->kind] ?? $this->kind),
            work: fn (JobRun $run) => $supplier->feed()->import($this->kind, $run),
            subject: $supplier,
            input: ['supplier_id' => $supplier->id, 'kind' => $this->kind],
        );

        // New stock or prices: bring the shop up to date (one sync even when several imports finish).
        if (ShopConnection::current()?->canWrite()) {
            SyncShopProducts::dispatch();
        }
    }
}
