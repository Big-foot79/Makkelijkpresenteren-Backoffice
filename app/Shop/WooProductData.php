<?php

namespace App\Shop;

use App\Support\Parse;
use Carbon\CarbonImmutable;

/**
 * Reads the fields the backoffice cares about from a WooCommerce product as the REST API returns it.
 * The EAN can live in three places, depending on how the shop was set up over the years:
 * the GTIN field (WooCommerce 9.2+), a product attribute, or a meta field from an EAN plugin.
 */
class WooProductData
{
    private const EAN_ATTRIBUTES = ['ean', 'ean nummer', 'ean-nummer', 'ean code', 'ean-code', 'gtin', 'barcode'];

    private const EAN_META = ['_global_unique_id', '_ean', 'ean', '_wpm_gtin_code', '_alg_ean', 'hwp_product_gtin', '_ts_gtin', '_barcode'];

    private const BRAND_ATTRIBUTES = ['merk', 'brand', 'fabrikant'];

    /** Meta fields the backoffice writes; see ShopSync. */
    public const MANAGED_META = ['_mp_stock_status', '_mp_stock_label', '_mp_stock_next_date'];

    public function __construct(public readonly array $raw) {}

    /** @return array{0: ?string, 1: ?string} EAN and where it was found */
    public function ean(): array
    {
        if ($ean = Parse::ean($this->raw['global_unique_id'] ?? null)) {
            return [$ean, 'gtin'];
        }

        foreach ($this->raw['attributes'] ?? [] as $attribute) {
            if (in_array(mb_strtolower(trim($attribute['name'] ?? '')), self::EAN_ATTRIBUTES, true)) {
                if ($ean = Parse::ean($attribute['options'][0] ?? null)) {
                    return [$ean, 'attribute'];
                }
            }
        }

        foreach ($this->raw['meta_data'] ?? [] as $meta) {
            if (in_array($meta['key'] ?? '', self::EAN_META, true) && is_scalar($meta['value'] ?? null)) {
                if ($ean = Parse::ean((string) $meta['value'])) {
                    return [$ean, 'meta'];
                }
            }
        }

        return [null, null];
    }

    public function brand(): ?string
    {
        // WooCommerce 9.6+ has a brands taxonomy of its own.
        if ($brand = $this->raw['brands'][0]['name'] ?? null) {
            return Parse::text(html_entity_decode($brand));
        }

        foreach ($this->raw['attributes'] ?? [] as $attribute) {
            if (in_array(mb_strtolower(trim($attribute['name'] ?? '')), self::BRAND_ATTRIBUTES, true)) {
                return Parse::text($attribute['options'][0] ?? null);
            }
        }

        return null;
    }

    public function meta(string $key): mixed
    {
        foreach ($this->raw['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? null) === $key) {
                return $meta['value'];
            }
        }

        return null;
    }

    /** The shop-side values of the fields the backoffice owns, in the same shape ShopSync sends them. */
    public function managedState(): array
    {
        $state = [
            'regular_price' => self::price($this->raw['regular_price'] ?? null),
            'sale_price' => self::price($this->raw['sale_price'] ?? null),
            'stock_status' => $this->raw['stock_status'] ?? null,
        ];

        foreach (self::MANAGED_META as $key) {
            $state[$key] = (string) ($this->meta($key) ?? '');
        }

        return $state;
    }

    /** Attributes for the woo_products mirror. */
    public function mirror(): array
    {
        [$ean, $source] = $this->ean();
        $modified = $this->raw['date_modified_gmt'] ?? null;

        return [
            'woo_id' => (int) $this->raw['id'],
            'type' => $this->raw['type'] ?? null,
            'status' => $this->raw['status'] ?? null,
            'sku' => Parse::text($this->raw['sku'] ?? null),
            'name' => Parse::text(html_entity_decode((string) ($this->raw['name'] ?? ''))),
            'brand' => $this->brand(),
            'ean' => $ean,
            'ean_source' => $source,
            'regular_price' => Parse::decimal($this->raw['regular_price'] ?? null),
            'sale_price' => Parse::decimal($this->raw['sale_price'] ?? null),
            'stock_status' => $this->raw['stock_status'] ?? null,
            'manage_stock' => (bool) ($this->raw['manage_stock'] ?? false),
            'permalink' => $this->raw['permalink'] ?? null,
            'managed_meta' => array_intersect_key($this->managedState(), array_flip(self::MANAGED_META)),
            'woo_modified_at' => $modified ? CarbonImmutable::parse($modified, 'UTC') : null,
            'fetched_at' => now(),
        ];
    }

    /** Prices as WooCommerce stores them: a string with a dot, or empty for "no price". */
    public static function price(mixed $value): string
    {
        $decimal = is_numeric($value) ? (float) $value : Parse::decimal(is_scalar($value) ? (string) $value : null);

        return $decimal === null ? '' : number_format($decimal, 2, '.', '');
    }
}
