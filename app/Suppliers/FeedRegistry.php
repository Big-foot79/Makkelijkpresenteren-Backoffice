<?php

namespace App\Suppliers;

use App\Models\Supplier;
use App\Suppliers\Feeds\AlsoFeed;
use App\Suppliers\Feeds\CopacoFeed;
use App\Suppliers\Feeds\Feed;
use App\Suppliers\Feeds\TdSynnexFeed;
use App\Suppliers\Feeds\TravionFeed;
use RuntimeException;

/** Supplier code => the class that knows how to read its files. Add new suppliers here. */
class FeedRegistry
{
    public const FEEDS = [
        'tdsynnex' => TdSynnexFeed::class,
        'also' => AlsoFeed::class,
        'travion' => TravionFeed::class,
        'copaco' => CopacoFeed::class,
    ];

    public function for(Supplier $supplier): Feed
    {
        $class = self::FEEDS[$supplier->code] ?? throw new RuntimeException("Geen importprofiel voor leverancier '{$supplier->code}'.");

        return new $class($supplier);
    }
}
