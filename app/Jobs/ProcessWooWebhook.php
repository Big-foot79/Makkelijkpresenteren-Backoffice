<?php

namespace App\Jobs;

use App\Models\WooOrder;
use App\Models\WooProduct;
use App\Shop\ShopSync;
use App\Shop\WooProductData;
use App\Support\Actor;
use App\Support\Parse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

/**
 * Handles one signed WooCommerce webhook, after the controller has checked the signature.
 * Products: refresh the mirror and flag changes made in the shop itself. Orders: keep the business
 * data and the campaign attribution (no personal data).
 */
class ProcessWooWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $topic, public array $payload) {}

    public function handle(ShopSync $sync): void
    {
        Actor::via('webhook', fn () => match (true) {
            in_array($this->topic, ['product.created', 'product.updated', 'product.restored'], true) => $this->product($sync),
            $this->topic === 'product.deleted' => $this->productDeleted(),
            in_array($this->topic, ['order.created', 'order.updated'], true) => $this->order(),
            default => null,
        });
    }

    private function product(ShopSync $sync): void
    {
        if (! isset($this->payload['id'])) {
            return;
        }

        $mirror = (new WooProductData($this->payload))->mirror();
        $woo = WooProduct::query()->firstOrNew(['woo_id' => $mirror['woo_id']]);
        $woo->fill($mirror)->save();

        // New or unlinked products are classified in the nightly check; linked ones are checked now.
        if ($woo->product_id) {
            $sync->detectConflicts([$woo->load('product')]);
        }
    }

    private function productDeleted(): void
    {
        $woo = WooProduct::query()->where('woo_id', (int) ($this->payload['id'] ?? 0))->first();

        if ($woo) {
            $woo->product?->update(['woo_id' => null, 'woo_synced_state' => null]);
            $woo->delete();
        }
    }

    private function order(): void
    {
        $o = $this->payload;

        if (! isset($o['id'])) {
            return;
        }

        $meta = collect($o['meta_data'] ?? [])->pluck('value', 'key');
        $entry = (string) ($meta['_wc_order_attribution_session_entry'] ?? '');
        parse_str((string) parse_url($entry, PHP_URL_QUERY), $query);

        $attribution = array_filter([
            'source_type' => $meta['_wc_order_attribution_source_type'] ?? null,
            'source' => $meta['_wc_order_attribution_utm_source'] ?? null,
            'medium' => $meta['_wc_order_attribution_utm_medium'] ?? null,
            'campaign' => $meta['_wc_order_attribution_utm_campaign'] ?? null,
            'content' => $meta['_wc_order_attribution_utm_content'] ?? null,
            'term' => $meta['_wc_order_attribution_utm_term'] ?? null,
            'referrer' => $meta['_wc_order_attribution_referrer'] ?? null,
            'landing_page' => $entry ? strtok($entry, '?') : null,
            'device' => $meta['_wc_order_attribution_device_type'] ?? null,
        ], fn ($v) => filled($v));

        $gclid = $meta['_gclid'] ?? $meta['gclid'] ?? $query['gclid'] ?? null;

        WooOrder::query()->updateOrCreate(['woo_id' => (int) $o['id']], [
            'number' => $o['number'] ?? (string) $o['id'],
            'status' => $o['status'] ?? null,
            'currency' => $o['currency'] ?? 'EUR',
            'total' => Parse::decimal($o['total'] ?? null),
            'company' => Parse::text(Arr::get($o, 'billing.company')),
            'lines' => collect($o['line_items'] ?? [])->map(fn ($line) => [
                'woo_product_id' => $line['product_id'] ?? null,
                'sku' => $line['sku'] ?? null,
                'name' => $line['name'] ?? null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'total' => Parse::decimal($line['total'] ?? null),
            ])->values()->all(),
            'attribution' => $attribution ?: null,
            'gclid' => is_string($gclid) && $gclid !== '' ? $gclid : null,
            'ordered_at' => isset($o['date_created_gmt']) ? CarbonImmutable::parse($o['date_created_gmt'], 'UTC') : null,
        ]);
    }
}
