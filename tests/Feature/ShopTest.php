<?php

namespace Tests\Feature;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ShopConnections\Pages\EditShopConnection;
use App\Filament\Resources\ShopConnections\ShopConnectionResource;
use App\Filament\Resources\WooProducts\Pages\ListWooProducts;
use App\Filament\Resources\WooProducts\WooProductResource;
use App\Jobs\LinkShopProducts;
use App\Jobs\ProcessWooWebhook;
use App\Jobs\RefreshShopProducts;
use App\Jobs\SyncShopProducts;
use App\Mcp\Servers\BackofficeServer;
use App\Mcp\Tools\SearchProducts;
use App\Models\Change;
use App\Models\JobRun;
use App\Models\Product;
use App\Models\ShopConnection;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\SupplierStock;
use App\Models\Task;
use App\Models\User;
use App\Models\WooOrder;
use App\Models\WooProduct;
use App\Shop\ShopSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** WooCommerce link: fetch and classify, link, push price/availability, webhooks. All data is fictional. */
class ShopTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://shop.test/wp-json/wc/v3/';

    private const SECRET = 'test-webhook-secret';

    private ShopConnection $shop;

    /** @var list<array> */
    private array $batches = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopConnection::current();
        $this->shop->update([
            'url' => 'https://shop.test',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
            'webhook_secret' => self::SECRET,
        ]);

        // A distributor that carries the first product, with 8 in stock (= "ruim").
        $td = Supplier::where('code', 'tdsynnex')->sole();
        $run = JobRun::create(['type' => 'test', 'label' => 'test', 'status' => JobRunStatus::Succeeded]);
        SupplierItem::create(['supplier_id' => $td->id, 'sku' => 'TD-1', 'ean' => '8712345678906', 'net_price' => 400, 'description' => 'Scherm 65 inch']);
        SupplierStock::create(['supplier_id' => $td->id, 'job_run_id' => $run->id, 'sku' => 'TD-1', 'quantity' => 8]);
        $td->update(['current_stock_run_id' => $run->id, 'stock_as_of' => now()]);
    }

    private function wooProducts(): array
    {
        return [
            $this->woo(101, 'SCR-65', 'Scherm 65 inch', '499.00', ['global_unique_id' => '8712345678906']),
            $this->woo(102, 'CAM-1', 'Conferentiecamera', '899.00', ['attributes' => [['name' => 'EAN nummer', 'options' => ['4006381333931']]]]),
            $this->woo(103, 'KABEL', 'HDMI-kabel', '19.95'),
            $this->woo(104, 'DUP-A', 'Dubbel A', '10.00', ['global_unique_id' => '5901234123457']),
            $this->woo(105, 'DUP-B', 'Dubbel B', '11.00', ['meta_data' => [['id' => 1, 'key' => '_ean', 'value' => '5901234123457']]]),
            $this->woo(106, 'VAR', 'Beugel in maten', '', ['type' => 'variable']),
        ];
    }

    private function woo(int $id, string $sku, string $name, string $price, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'type' => 'simple', 'status' => 'publish', 'sku' => $sku, 'name' => $name,
            'regular_price' => $price, 'sale_price' => '', 'stock_status' => 'instock', 'manage_stock' => false,
            'permalink' => "https://shop.test/product/{$sku}", 'global_unique_id' => '', 'attributes' => [],
            'meta_data' => [], 'date_modified_gmt' => '2026-11-27T08:00:00',
        ], $extra);
    }

    /** Fakes the shop: the product list, and a batch endpoint that echoes the update back. */
    private function fakeShop(?array $products = null): void
    {
        $products ??= $this->wooProducts();

        Http::fake(function (Request $request) use (&$products) {
            if (str_contains($request->url(), 'products/batch')) {
                $this->batches[] = $request->data();
                $updated = [];

                foreach ($request->data()['update'] as $update) {
                    $product = collect($products)->firstWhere('id', $update['id']);
                    $meta = collect($product['meta_data'])->keyBy('key');
                    foreach ($update['meta_data'] ?? [] as $m) {
                        $meta[$m['key']] = $m;
                    }
                    $updated[] = array_merge($product, collect($update)->except('meta_data')->all(), ['meta_data' => $meta->values()->all()]);
                }

                return Http::response(['update' => $updated]);
            }

            if (str_contains($request->url(), 'system_status')) {
                return Http::response(['environment' => ['version' => '9.8.5', 'wp_version' => '6.8'], 'settings' => ['store_name' => 'Testshop', 'currency' => 'EUR']]);
            }

            return Http::response(str_contains($request->url(), 'page=1') ? $products : [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($products)]);
        });
    }

    private function refreshAndLink(): void
    {
        $this->fakeShop();
        (new RefreshShopProducts)->handle();
        (new LinkShopProducts)->handle();
    }

    public function test_fetch_classifies_every_shop_product_without_writing(): void
    {
        $this->fakeShop();

        (new RefreshShopProducts)->handle();

        $status = WooProduct::pluck('match_status', 'sku');
        $this->assertSame('ok', $status['SCR-65']);
        $this->assertSame('ok', $status['CAM-1']);            // EAN from the attribute
        $this->assertSame('no_ean', $status['KABEL']);
        $this->assertSame('duplicate_ean', $status['DUP-A']); // same EAN as DUP-B (meta field)
        $this->assertSame('duplicate_ean', $status['DUP-B']);
        $this->assertSame('variable', $status['VAR']);

        $this->assertSame(1, WooProduct::where('sku', 'SCR-65')->value('supplier_matches'));
        $this->assertSame('attribute', WooProduct::where('sku', 'CAM-1')->value('ean_source'));
        $this->assertSame(6, $this->shop->fresh()->last_report['total']);
        $this->assertSame(1, $this->shop->fresh()->last_report['ok_with_supplier']);
        $this->assertSame(0, Product::count());

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ck_test:cs_test')));
    }

    public function test_linking_creates_products_with_the_shop_price_as_starting_point(): void
    {
        $this->refreshAndLink();

        $this->assertSame(2, Product::count());
        $product = Product::where('ean', '8712345678906')->sole();
        $this->assertSame(101, $product->woo_id);
        $this->assertSame('499.00', $product->webshop_price);
        $this->assertSame('linked', $product->wooProduct->match_status);
        $this->assertSame(0, WooProduct::where('match_status', 'ok')->count());
    }

    public function test_sync_is_a_dry_run_while_the_connection_is_not_active(): void
    {
        $this->refreshAndLink();

        (new SyncShopProducts)->handle(app(ShopSync::class));

        $this->assertSame([], $this->batches);
        $run = JobRun::where('type', 'shop.sync.dry')->sole();
        $this->assertStringContainsString('2 zouden worden bijgewerkt', $run->summary);
        $this->assertSame('ample', Product::where('sku', 'SCR-65')->value('availability'));
    }

    public function test_sync_sends_only_what_differs_and_then_nothing(): void
    {
        $this->refreshAndLink();
        $this->shop->update(['is_active' => true]);
        Product::where('sku', 'SCR-65')->sole()->update(['webshop_price' => 479]);

        (new SyncShopProducts)->handle(app(ShopSync::class));

        $this->assertCount(1, $this->batches);
        $updates = collect($this->batches[0]['update'])->keyBy('id');

        // In stock at a supplier (8): price, "in stock" (already) and the label.
        $this->assertSame('479.00', $updates[101]['regular_price']);
        $this->assertArrayNotHasKey('stock_status', $updates[101]);
        $this->assertContains(['key' => '_mp_stock_label', 'value' => 'Ruim op voorraad'], $updates[101]['meta_data']);

        // No supplier has the camera: availability unknown, so the shop's stock status stays as it is.
        $this->assertArrayNotHasKey('stock_status', $updates[102]);
        $this->assertArrayNotHasKey('regular_price', $updates[102]);

        $this->assertNotNull(Product::where('sku', 'SCR-65')->value('woo_synced_at'));
        $this->assertSame('479.00', WooProduct::where('woo_id', 101)->value('regular_price'));

        // Nothing changed since: no second batch.
        (new SyncShopProducts)->handle(app(ShopSync::class));
        $this->assertCount(1, $this->batches);
    }

    public function test_out_of_stock_everywhere_becomes_backorder_in_the_shop(): void
    {
        $this->refreshAndLink();
        $this->shop->update(['is_active' => true]);
        SupplierStock::query()->update(['quantity' => 0, 'next_date' => '2026-12-10']);

        (new SyncShopProducts)->handle(app(ShopSync::class));

        $update = collect($this->batches[0]['update'])->firstWhere('id', 101);
        $this->assertSame('onbackorder', $update['stock_status']);
        $this->assertContains(['key' => '_mp_stock_next_date', 'value' => '2026-12-10'], $update['meta_data']);
    }

    private function webhook(string $topic, array $payload, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/woocommerce', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, (string) $secret, true)),
        ], $body);
    }

    public function test_webhooks_must_be_signed(): void
    {
        Queue::fake();

        $this->webhook('product.updated', ['id' => 101], 'wrong')->assertUnauthorized();
        $this->post('/webhooks/woocommerce', ['webhook_id' => 5])->assertOk(); // the ping on creation
        $this->webhook('product.updated', ['id' => 101])->assertStatus(202);

        Queue::assertPushed(ProcessWooWebhook::class, 1);
    }

    public function test_a_price_changed_in_woocommerce_becomes_a_conflict_but_our_own_echo_does_not(): void
    {
        $this->refreshAndLink();
        $this->shop->update(['is_active' => true]);
        (new SyncShopProducts)->handle(app(ShopSync::class));

        $product = Product::where('sku', 'SCR-65')->sole();
        $synced = collect($this->batches[0]['update'])->firstWhere('id', 101);
        $echo = $this->woo(101, 'SCR-65', 'Scherm 65 inch', '499.00', [
            'global_unique_id' => '8712345678906',
            'meta_data' => $synced['meta_data'],
        ]);

        // Our own update coming back: no task.
        $this->webhook('product.updated', $echo)->assertStatus(202);
        $this->assertSame(0, Task::where('kind', TaskKind::SyncConflict)->count());

        // Someone changes the price in WordPress.
        $this->webhook('product.updated', ['regular_price' => '449.00'] + $echo)->assertStatus(202);
        $task = Task::where('kind', TaskKind::SyncConflict)->sole();
        $this->assertTrue($task->subject->is($product));
        $this->assertSame('449.00', $task->data['fields']['regular_price']['shop']);
        $this->assertStringContainsString('backoffice 499.00, webshop 449.00', $task->description);

        // Until someone decides, the sync leaves this product alone.
        $this->batches = [];
        Product::whereKey($product->id)->update(['webshop_price' => 489]);
        (new SyncShopProducts)->handle(app(ShopSync::class));
        $this->assertSame([], $this->batches);
    }

    public function test_orders_keep_business_data_and_campaign_attribution_only(): void
    {
        $this->webhook('order.created', [
            'id' => 9001, 'number' => '9001', 'status' => 'processing', 'currency' => 'EUR', 'total' => '1210.00',
            'date_created_gmt' => '2026-11-27T09:30:00',
            'billing' => ['company' => 'Voorbeeld BV', 'email' => 'inkoop@voorbeeld.test', 'first_name' => 'Jan'],
            'line_items' => [['product_id' => 101, 'sku' => 'SCR-65', 'name' => 'Scherm 65 inch', 'quantity' => 2, 'total' => '998.00']],
            'meta_data' => [
                ['key' => '_wc_order_attribution_source_type', 'value' => 'utm'],
                ['key' => '_wc_order_attribution_utm_source', 'value' => 'google'],
                ['key' => '_wc_order_attribution_utm_medium', 'value' => 'cpc'],
                ['key' => '_wc_order_attribution_session_entry', 'value' => 'https://shop.test/product/scr-65/?gclid=abc123&utm_source=google'],
            ],
        ])->assertStatus(202);

        $order = WooOrder::where('woo_id', 9001)->sole();
        $this->assertSame('abc123', $order->gclid);
        $this->assertSame('google', $order->attribution['source']);
        $this->assertSame('https://shop.test/product/scr-65/', $order->attribution['landing_page']);
        $this->assertSame(2, $order->lines[0]['quantity']);
        $this->assertStringNotContainsString('inkoop@voorbeeld.test', json_encode($order->toArray()));
        $this->assertStringNotContainsString('Jan', json_encode($order->toArray()));
    }

    public function test_screens_render_and_a_price_change_goes_to_the_shop(): void
    {
        $this->refreshAndLink();
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $product = Product::where('sku', 'SCR-65')->sole();

        $this->get(ProductResource::getUrl())->assertOk()->assertSee('Scherm 65 inch')->assertSee('400,00');
        $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk()->assertSee('TD Synnex');
        $this->get(WooProductResource::getUrl())->assertOk()->assertSee('Geen EAN')->assertSee('HDMI-kabel');
        $this->get(ShopConnectionResource::getUrl('edit', ['record' => $this->shop]))->assertOk()->assertSee('webhooks/woocommerce')->assertDontSee('cs_test');

        Livewire::test(EditShopConnection::class, ['record' => $this->shop->getRouteKey()])
            ->callAction('test')
            ->assertNotified('Verbinding gelukt: Testshop');
        $this->assertSame('9.8.5', $this->shop->fresh()->woo_version);

        $this->shop->update(['is_active' => true]);
        Queue::fake();
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['webshop_price' => 469])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(SyncShopProducts::class, fn ($job) => $job->productIds === [$product->id]);
        $this->assertEquals(469, $product->changeHistory()->where('event', 'updated')->first()->new_values['webshop_price']);
    }

    public function test_staff_cannot_open_the_shop_connection(): void
    {
        User::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));

        $this->get(ShopConnectionResource::getUrl('edit', ['record' => $this->shop]))->assertForbidden();
        $this->get(ProductResource::getUrl())->assertOk();
    }

    public function test_api_keys_are_encrypted_and_never_in_the_change_history(): void
    {
        $raw = DB::table('shop_connections')->value('consumer_secret');

        $this->assertNotSame('cs_test', $raw);
        $this->assertStringNotContainsString('cs_test', Change::query()->get()->toJson());
        $this->assertStringNotContainsString(self::SECRET, Change::query()->get()->toJson());
    }

    public function test_mcp_product_search_shows_margin_and_availability(): void
    {
        $this->refreshAndLink();
        (new SyncShopProducts)->handle(app(ShopSync::class));
        User::factory()->create();
        $agent = User::factory()->create(['role' => UserRole::Agent]);

        BackofficeServer::actingAs($agent)
            ->tool(SearchProducts::class, ['query' => '8712345678906'])
            ->assertOk()
            ->assertStructuredContentPath('count', 1)
            ->assertStructuredContentPath('products.0.webshop_price', 499)
            ->assertStructuredContentPath('products.0.expected_purchase', 400)
            ->assertStructuredContentPath('products.0.purchase_source', 'list')
            ->assertStructuredContentPath('products.0.margin_percent', 19.8)
            ->assertStructuredContentPath('products.0.availability', 'ample');
    }

    public function test_a_shop_product_without_ean_gets_a_suggestion_via_its_sku_and_can_be_linked(): void
    {
        // The HDMI cable (SKU "KABEL") has no EAN in the shop; a supplier has part number "kabel" with an EAN.
        SupplierItem::create(['supplier_id' => Supplier::where('code', 'copaco')->sole()->id, 'sku' => 'CP-7', 'mpn' => 'kabel', 'ean' => '4006381333948', 'is_listed' => true]);

        $this->refreshAndLink();

        $woo = WooProduct::where('sku', 'KABEL')->sole();
        $this->assertSame('ean_suggested', $woo->match_status);
        $this->assertSame('4006381333948', $woo->suggested_ean);
        $this->assertSame('Copaco CP-7', $woo->suggested_from);

        $this->actingAs(User::factory()->create());
        Livewire::test(ListWooProducts::class)->callTableAction('accept_ean', $woo);

        $product = Product::where('woo_id', 103)->sole();
        $this->assertSame('4006381333948', $product->ean);
        $this->assertSame('linked', $woo->fresh()->match_status);

        // With writing on, the EAN goes into the shop's GTIN field; products that have it there already are left alone.
        $this->shop->update(['is_active' => true, 'woo_version' => '9.8.5']);
        (new SyncShopProducts)->handle(app(ShopSync::class));
        $updates = collect($this->batches[0]['update'])->keyBy('id');
        $this->assertSame('4006381333948', $updates[103]['global_unique_id']);
        $this->assertSame('4006381333931', $updates[102]['global_unique_id']); // was only in an attribute
        $this->assertArrayNotHasKey('global_unique_id', $updates[101]);      // already in the GTIN field
    }
}
