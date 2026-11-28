<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An uploaded price list (bid prices, advice prices) from a brand or distributor. */
class PriceList extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    protected $attributes = [
        'sheet' => 0,
        'header_row' => 1,
        'status' => 'uploaded',
    ];

    /** The fields a column can be mapped to. */
    public const FIELDS = [
        'ean' => 'EAN',
        'mpn' => 'Artikelnummer fabrikant',
        'description' => 'Omschrijving',
        'msrp' => 'Adviesprijs',
        'purchase_price' => 'Inkoop- of bidprijs',
    ];

    public const STATUSES = [
        'uploaded' => 'Nog inlezen',
        'imported' => 'Ingelezen',
        'failed' => 'Mislukt',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'assume_available' => 'boolean',
            'columns' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Imported and valid today. */
    public function scopeCurrent(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('status', 'imported')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
    }

    public function isCurrent(): bool
    {
        return $this->status === 'imported'
            && (! $this->valid_from || $this->valid_from->lte(today()))
            && (! $this->valid_until || $this->valid_until->gte(today()));
    }
}
