<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\PriceList;
use App\Pricing\PriceListImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportPriceList implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $priceListId) {}

    public function handle(PriceListImporter $importer): void
    {
        $list = PriceList::findOrFail($this->priceListId);

        try {
            JobRun::track(
                type: 'pricelist.import',
                label: 'Prijslijst inlezen: '.$list->name,
                work: fn (JobRun $run) => $importer->import($list, $run),
                subject: $list,
                input: ['price_list_id' => $list->id],
            );
        } catch (Throwable $e) {
            $list->update(['status' => 'failed']);

            throw $e;
        }
    }
}
