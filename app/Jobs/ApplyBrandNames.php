<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Support\Brands;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Applies the brand name rules to every stored supplier article, product and brand setting. */
class ApplyBrandNames implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function handle(): void
    {
        JobRun::track('brands.apply', 'Merknamen toepassen', fn () => Brands::apply().' regels aangepast');
    }
}
