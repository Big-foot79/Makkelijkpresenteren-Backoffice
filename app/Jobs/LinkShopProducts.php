<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\ShopConnection;
use App\Shop\ShopCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Links every shop product with a clean, unique EAN to a backoffice product. Writes nothing to the shop. */
class LinkShopProducts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(): void
    {
        JobRun::track(
            type: 'shop.link',
            label: 'Webshopproducten koppelen',
            work: fn (JobRun $run) => ShopCatalog::forCurrentShop()->link($run),
            subject: ShopConnection::current(),
        );
    }
}
