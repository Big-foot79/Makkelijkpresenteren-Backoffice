<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The usual purchase agreement with a brand: a discount on its advice price, via a distributor. */
class BrandTerm extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    public const BID_SOURCES = [
        'mail' => 'Bidprijzen per mail',
        'tdsynnex' => 'TD Synnex projectprijzen',
        'portal' => 'Portaal van de fabrikant',
    ];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Case-insensitive, like the favourite brands: IIYAMA and Iiyama are one brand. */
    public static function forBrand(?string $brand): ?self
    {
        return $brand ? static::query()->whereRaw('lower(brand) = ?', [mb_strtolower(trim($brand))])->first() : null;
    }
}
