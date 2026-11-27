<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\ShopConnection;
use App\Shop\ShopCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads every product from WooCommerce into the mirror and makes the link report.
 * Only reads the shop. With $thenSync the price/availability sync follows (the nightly check).
 */
class RefreshShopProducts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public bool $thenSync = false) {}

    public function handle(): void
    {
        $connection = ShopConnection::current();

        JobRun::track(
            type: 'shop.refresh',
            label: 'Webshopproducten ophalen',
            work: fn (JobRun $run) => ShopCatalog::forCurrentShop()->refresh($run),
            subject: $connection,
        );

        if ($this->thenSync && $connection?->canWrite()) {
            SyncShopProducts::dispatch();
        }
    }
}
