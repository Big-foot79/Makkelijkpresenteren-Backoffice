<?php

namespace App\Shop;

use App\Models\ShopConnection;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the WooCommerce REST API (v3), authenticated with a consumer key and secret over
 * HTTPS. Reading always works; writing refuses unless the connection is active.
 */
class WooClient
{
    public const BATCH_SIZE = 100; // WooCommerce's limit per batch request

    public function __construct(private readonly ShopConnection $connection)
    {
        if (! $connection->hasCredentials()) {
            throw new RuntimeException('De webshopverbinding heeft nog geen API-sleutel. Vul die in onder Systeem → Webshop.');
        }
    }

    public static function make(?ShopConnection $connection = null): self
    {
        $connection ??= ShopConnection::current();

        if (! $connection) {
            throw new RuntimeException('Er is nog geen webshopverbinding ingesteld.');
        }

        return new self($connection);
    }

    public function connection(): ShopConnection
    {
        return $this->connection;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query)->json() ?? [];
    }

    /**
     * Every item of a list endpoint, page by page.
     *
     * @return Generator<int, array>
     */
    public function paginate(string $path, array $query = [], int $perPage = 100): Generator
    {
        $page = 1;

        do {
            $response = $this->send('get', $path, $query + ['per_page' => $perPage, 'page' => $page]);
            $items = $response->json() ?? [];
            $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);

            foreach ($items as $item) {
                yield $item;
            }

            $page++;
        } while ($items !== [] && $page <= $totalPages);
    }

    /**
     * Update many products in batches of 100. Returns the per-item results keyed by Woo id,
     * with an 'error' entry for items WooCommerce refused.
     *
     * @param  list<array{id: int}>  $updates
     * @return array<int, array>
     */
    public function batchUpdateProducts(array $updates): array
    {
        $this->assertWritable();
        $results = [];

        foreach (array_chunk($updates, self::BATCH_SIZE) as $chunk) {
            $response = $this->send('post', 'products/batch', ['update' => $chunk]);

            foreach ($response->json('update') ?? [] as $item) {
                $results[(int) ($item['id'] ?? 0)] = $item;
            }
        }

        return $results;
    }

    /** Store name, WooCommerce version and whether the GTIN field exists, for the connection test. */
    public function check(): array
    {
        $status = $this->get('system_status');
        $products = $this->send('get', 'products', ['per_page' => 1, 'status' => 'any']);

        $version = $status['environment']['version'] ?? null;

        return [
            'store' => $status['settings']['store_name'] ?? $status['environment']['site_url'] ?? $this->connection->url,
            'woo_version' => $version,
            'wordpress_version' => $status['environment']['wp_version'] ?? null,
            'products' => (int) ($products->header('X-WP-Total') ?: 0),
            'gtin_field' => $version && version_compare($version, '9.2', '>='),
            'currency' => $status['settings']['currency'] ?? null,
            'prices_include_tax' => rescue(fn () => $this->get('settings/tax/woocommerce_prices_include_tax')['value'] ?? null, null, false),
        ];
    }

    private function assertWritable(): void
    {
        if (! $this->connection->canWrite()) {
            throw new RuntimeException('De webshopverbinding staat niet op actief: er wordt niets naar de webshop geschreven.');
        }
    }

    private function send(string $method, string $path, array $data = []): Response
    {
        $response = $this->request()->{$method}(ltrim($path, '/'), $data);

        if ($response->failed()) {
            $message = $response->json('message') ?? str($response->body())->limit(200)->toString();

            throw new RuntimeException("WooCommerce {$response->status()} op {$path}: {$message}");
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        $base = rtrim($this->connection->url, '/').'/wp-json/wc/v3/';

        if (! str_starts_with($base, 'https://') && ! app()->runningUnitTests()) {
            throw new RuntimeException('De webshop-URL moet met https:// beginnen; de sleutel gaat anders onversleuteld over het net.');
        }

        return Http::baseUrl($base)
            ->withBasicAuth($this->connection->consumer_key, $this->connection->consumer_secret)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('Makkelijkpresenteren-Backoffice')
            ->timeout(60)
            ->retry(3, 2000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true)), throw: false);
    }
}
