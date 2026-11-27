<?php

namespace App\Models;

use App\Jobs\ApplyBrandNames;
use App\Models\Concerns\RecordsChanges;
use App\Support\Brands;
use Illuminate\Database\Eloquent\Model;

/** A fixed link from a supplier's brand spelling to our brand name ("Hewlett Packard" -> "HP"). */
class BrandAlias extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(fn (self $alias) => $alias->alias = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $alias->alias))));

        // Existing articles and products follow right away (on the queue).
        static::saved(function () {
            Brands::flush();
            ApplyBrandNames::dispatch();
        });
        static::deleted(function () {
            Brands::flush();
            ApplyBrandNames::dispatch();
        });
    }
}
