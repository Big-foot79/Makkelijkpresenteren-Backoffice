<?php

namespace App\Mcp\Tools;

use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Suppliers\StockAvailability;
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
    Search the supplier catalogues (TD Synnex, ALSO, Travion, Copaco) by EAN, supplier article number,
    manufacturer part number, brand or description. Returns per item the purchase price from the price
    list (special bid prices are not included), the advice price (TD Synnex only), the stock at that
    supplier and, per EAN, the availability as shown on the webshop: the highest quantity at a single
    supplier with a stock list of at most 5 days old (never summed across suppliers).
    TXT)]
class SearchSupplierItems extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'supplier' => ['nullable', 'string', 'exists:suppliers,code'],
            'include_delisted' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $search = trim($validated['query']);
        $ean = Parse::ean($search);

        $items = SupplierItem::query()
            ->select('supplier_items.*')
            ->withCurrentStock()
            ->with('supplier:id,name,code,stock_as_of,settings')
            ->when(! ($validated['include_delisted'] ?? false), fn (Builder $q) => $q->where('is_listed', true))
            ->when($validated['supplier'] ?? null, fn (Builder $q, string $code) => $q->whereIn('supplier_id', Supplier::where('code', $code)->select('id')))
            ->where(function (Builder $q) use ($search, $ean) {
                if ($ean) {
                    $q->where('ean', $ean);

                    return;
                }

                $q->where('sku', $search)
                    ->orWhere('mpn', $search)
                    ->orWhere('brand', 'like', $search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            })
            ->orderBy('description')
            ->limit($validated['limit'] ?? 20)
            ->get();

        $availability = app(StockAvailability::class)->forEans($items->pluck('ean')->filter()->unique()->values()->all());

        return Response::structured([
            'count' => $items->count(),
            'items' => $items->map(fn (SupplierItem $item) => [
                'supplier' => $item->supplier->name,
                'supplier_code' => $item->supplier->code,
                'sku' => $item->sku,
                'ean' => $item->ean,
                'mpn' => $item->mpn,
                'brand' => $item->brand,
                'description' => $item->description,
                'net_price' => $item->net_price !== null ? (float) $item->net_price : null,
                'surcharge' => $item->surcharge !== null ? (float) $item->surcharge : null,
                'msrp' => $item->msrp !== null ? (float) $item->msrp : null,
                'currency' => $item->currency,
                'status' => $item->status,
                'follower_sku' => $item->follower_sku,
                'is_listed' => $item->is_listed,
                'stock_quantity' => $item->stock_quantity !== null ? (int) $item->stock_quantity : null,
                'stock_as_of' => $item->supplier->stock_as_of?->toIso8601String(),
                'supplier_url' => $item->supplierUrl(),
            ])->all(),
            'availability_by_ean' => (object) $availability->map(fn (array $a) => [
                'status' => $a['status'],
                'label' => $a['label'],
                'best_quantity' => $a['best_quantity'],
                'best_supplier' => $a['best_supplier'],
                'next_date' => $a['next_date'],
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('EAN, supplier article number, manufacturer part number, brand or part of the description.')
                ->required(),
            'supplier' => $schema->string()
                ->enum(['tdsynnex', 'also', 'travion', 'copaco'])
                ->description('Only search this supplier.'),
            'include_delisted' => $schema->boolean()
                ->description('Also return items no longer in the supplier catalogue.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum number of items, default 20.'),
        ];
    }
}
