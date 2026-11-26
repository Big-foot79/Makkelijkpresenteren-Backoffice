<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a task exists. New kinds are added per phase (bids, purchasing, ...).
 */
enum TaskKind: string implements HasLabel
{
    /** Something was changed directly in WooCommerce and differs from the backoffice. */
    case SyncConflict = 'sync_conflict';

    /** A background job failed and needs a look or a retry. */
    case JobFailed = 'job_failed';

    /** An imported or synced item could not be matched (e.g. unknown or duplicate EAN). */
    case NoMatch = 'no_match';

    /** A price differs from what was expected (bid, invoice, competitor). */
    case PriceDeviation = 'price_deviation';

    /** A proposal (e.g. from an AI agent) that waits for human approval. */
    case Approval = 'approval';

    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SyncConflict => 'Sync-conflict',
            self::JobFailed => 'Mislukte taak',
            self::NoMatch => 'Geen match',
            self::PriceDeviation => 'Prijsafwijking',
            self::Approval => 'Ter goedkeuring',
            self::Other => 'Overig',
        };
    }
}
