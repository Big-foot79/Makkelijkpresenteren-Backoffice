<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;

/** The WooCommerce shop the backoffice feeds. There is one; staging first, production later. */
class ShopConnection extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    protected $hidden = ['consumer_key', 'consumer_secret', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_report' => 'array',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    public function hasCredentials(): bool
    {
        return filled($this->url) && filled($this->consumer_key) && filled($this->consumer_secret);
    }

    /** Writes to the shop only happen when the connection is active and complete. */
    public function canWrite(): bool
    {
        return $this->is_active && $this->hasCredentials();
    }

    public function webhookUrl(): string
    {
        return route('webhooks.woocommerce');
    }
}
