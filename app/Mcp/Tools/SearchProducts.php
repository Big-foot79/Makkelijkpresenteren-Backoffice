<?php

namespace App\Mcp\Tools;

use App\Models\Product;
use App\Shop\ShopSync;
use App\Support\Parse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Search the webshop products the backoffice manages, by EAN, SKU, brand or name. Returns per product
    the webshop price (excl. VAT), sale price, the lowest purchase price among the suppliers with the
    same EAN (price list, no bids), the margin, the availability shown on the site and what WooCommerce
    shows right now. Use search-supplier-items for articles that are not (yet) in the shop.
    TXT)]
class SearchProducts extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'availability' => ['nullable', 'in:ample,limited,backorder,unknown'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $search = trim($validated['query']);
        $ean = Parse::ean($search);
        $sync = app(ShopSync::class);

        $products = Product::query()
            ->with('wooProduct')
            ->when($validated['availability'] ?? null, fn (Builder $q, string $a) => $q->where('availability', $a))
            ->where(function (Builder $q) use ($search, $ean) {
                if ($ean) {
                    $q->where('ean', $ean);

                    return;
                }

                $q->where('sku', $search)
                    ->orWhere('brand', 'like', $search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->limit($validated['limit'] ?? 20)
            ->get();

        return Response::structured([
            'count' => $products->count(),
            'products' => $products->map(function (Product $p) use ($sync) {
                $cheapest = $p->lowestPurchase();
                $price = $p->sale_price ?? $p->webshop_price;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'brand' => $p->brand,
                    'sku' => $p->sku,
                    'ean' => $p->ean,
                    'status' => $p->status,
                    'webshop_price' => $p->webshop_price !== null ? (float) $p->webshop_price : null,
                    'sale_price' => $p->sale_price !== null ? (float) $p->sale_price : null,
                    'lowest_purchase' => $cheapest ? [
                        'net_price' => (float) $cheapest->net_price,
                        'supplier' => $cheapest->supplier->name,
                        'sku' => $cheapest->sku,
                    ] : null,
                    'margin_percent' => $cheapest && $price > 0 ? round(($price - $cheapest->net_price) / $price * 100, 1) : null,
                    'availability' => $p->availability,
                    'availability_label' => $p->availabilityLabel(),
                    'expected_date' => $p->availability_date?->toDateString(),
                    'woo_id' => $p->woo_id,
                    'shop_url' => $p->wooProduct?->permalink,
                    'shop_now' => $p->wooProduct ? $sync->mirrorState($p->wooProduct) : null,
                    'synced_at' => $p->woo_synced_at?->toIso8601String(),
                ];
            })->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('EAN, SKU, brand or part of the product name.')->required(),
            'availability' => $schema->string()->enum(['ample', 'limited', 'backorder', 'unknown'])->description('Only products with this availability.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum number of products, default 20.'),
        ];
    }
}
