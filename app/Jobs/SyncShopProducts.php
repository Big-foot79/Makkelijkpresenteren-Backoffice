<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\ShopConnection;
use App\Shop\ShopSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes price and availability to WooCommerce for all linked products, or only the given ones.
 * Unique until it starts, so several imports finishing close together lead to one sync that
 * already sees all their data.
 */
class SyncShopProducts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /** @param list<int>|null $productIds */
    public function __construct(public ?array $productIds = null, public bool $dryRun = false) {}

    public function uniqueId(): string
    {
        return ($this->dryRun ? 'dry:' : '').($this->productIds === null ? 'all' : implode(',', $this->productIds));
    }

    public function handle(ShopSync $sync): void
    {
        $connection = ShopConnection::current();

        if (! $connection?->hasCredentials()) {
            return;
        }

        $dryRun = $this->dryRun || ! $connection->canWrite();

        JobRun::track(
            type: $dryRun ? 'shop.sync.dry' : 'shop.sync',
            label: ($dryRun ? 'Proefrun: ' : '').'Prijs en voorraad naar de webshop'.($this->productIds ? ' ('.count($this->productIds).' producten)' : ''),
            work: fn (JobRun $run) => $sync->run($run, $this->productIds, $dryRun),
            subject: $connection,
            input: array_filter(['products' => $this->productIds, 'dry_run' => $dryRun]),
        );
    }
}
