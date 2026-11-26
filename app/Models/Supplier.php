<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use App\Suppliers\FeedRegistry;
use App\Suppliers\Feeds\Feed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A distributor with its file connection and import settings.
 * How its files are read lives in a Feed class per supplier code (App\Suppliers\Feeds).
 */
class Supplier extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    protected $hidden = ['password', 'private_key', 'passphrase'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'encrypted',
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'settings' => 'array',
            'stock_as_of' => 'datetime',
            'catalog_imported_at' => 'datetime',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function feed(): Feed
    {
        return app(FeedRegistry::class)->for($this);
    }

    /** Age of the current stock list in days, or null when there is none. */
    public function stockAgeInDays(): ?float
    {
        return $this->stock_as_of?->diffInHours(now()) / 24;
    }

    public function stockIsStale(): bool
    {
        $age = $this->stockAgeInDays();

        return $age === null || $age > config('backoffice.stock.max_age_days');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierItem::class);
    }

    public function stock(): HasMany
    {
        return $this->hasMany(SupplierStock::class);
    }

    public function currentStockRun(): BelongsTo
    {
        return $this->belongsTo(JobRun::class, 'current_stock_run_id');
    }
}
