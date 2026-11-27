<?php

use App\Jobs\ImportSupplierFeed;
use App\Jobs\RefreshShopProducts;
use App\Models\ShopConnection;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
| Supplier imports: one scheduled job per active supplier and kind. The schedule comes from the
| supplier settings (cron_catalog, cron_stock, cron_full) or the feed's default.
*/
try {
    foreach (Supplier::where('is_active', true)->get() as $supplier) {
        $feed = $supplier->feed();

        foreach ($feed->kinds() as $kind) {
            if ($cron = $feed->schedule($kind)) {
                Schedule::job(new ImportSupplierFeed($supplier->id, $kind))
                    ->cron($cron)
                    ->timezone('Europe/Amsterdam')
                    ->name("import {$supplier->code} {$kind}");
            }
        }
    }
} catch (Throwable) {
    // No database yet (fresh install, before migrations): nothing to schedule.
}

Artisan::command('backoffice:prune-imports', function () {
    $keepDays = (int) config('backoffice.imports.keep_files_days');
    $disk = Storage::disk('local');
    $removed = 0;

    foreach ($disk->directories('imports') as $supplierDir) {
        foreach ($disk->directories($supplierDir) as $runDir) {
            // Folder names start with Ymd-His.
            $date = Carbon::createFromFormat('Ymd', substr(basename($runDir), 0, 8));

            if ($date && $date->lt(now()->subDays($keepDays)->startOfDay())) {
                $disk->deleteDirectory($runDir);
                $removed++;
            }
        }
    }

    $this->info("{$removed} oude importmappen verwijderd.");
})->purpose('Verwijder gedownloade leveranciersbestanden ouder dan de bewaartermijn');

Schedule::command('backoffice:prune-imports')->dailyAt('03:30')->timezone('Europe/Amsterdam');

/*
| Nightly check of the shop: read every product, refresh the link report, flag changes made in
| WooCommerce itself, then push price and availability (only when the connection is active).
*/
Schedule::job(new RefreshShopProducts(thenSync: true))
    ->dailyAt('02:15')
    ->timezone('Europe/Amsterdam')
    ->name('shop nightly check')
    ->when(fn () => rescue(fn () => (bool) ShopConnection::current()?->hasCredentials(), false, false));
