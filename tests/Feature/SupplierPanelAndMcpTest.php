<?php

namespace Tests\Feature;

use App\Enums\JobRunStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Jobs\ImportSupplierFeed;
use App\Mcp\Servers\BackofficeServer;
use App\Mcp\Tools\BackofficeStatus;
use App\Mcp\Tools\SearchSupplierItems;
use App\Models\JobRun;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\SupplierStock;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierPanelAndMcpTest extends TestCase
{
    use RefreshDatabase;

    private function seedItem(): SupplierItem
    {
        $supplier = Supplier::where('code', 'tdsynnex')->sole();
        $run = JobRun::create(['type' => 'import.tdsynnex.stock', 'label' => 'TD Synnex voorraad', 'status' => JobRunStatus::Succeeded]);
        $supplier->update(['current_stock_run_id' => $run->id, 'stock_as_of' => now()->subHours(3), 'is_active' => true]);

        SupplierStock::create(['supplier_id' => $supplier->id, 'job_run_id' => $run->id, 'sku' => '1000001', 'quantity' => 12, 'location' => '1818']);

        return SupplierItem::create([
            'supplier_id' => $supplier->id, 'sku' => '1000001', 'ean' => '8712345678906', 'mpn' => 'WPS-100',
            'brand' => 'VOORBEELDMERK', 'description' => 'Draadloos presentatiesysteem', 'net_price' => 1020.50, 'msrp' => 1499,
        ]);
    }

    public function test_supplier_pages_render(): void
    {
        $item = $this->seedItem();
        $this->actingAs(User::factory()->create());

        $this->get(SupplierResource::getUrl())->assertOk()->assertSee('TD Synnex')->assertSee('Copaco');
        $this->get(SupplierResource::getUrl('edit', ['record' => Supplier::where('code', 'also')->sole()]))->assertOk()->assertSee('stock_file');
        $this->get(SupplierItemResource::getUrl())->assertOk()->assertSee('Draadloos presentatiesysteem');
        $this->get(SupplierItemResource::getUrl('view', ['record' => $item]))->assertOk()
            ->assertSee('lokaal magazijn (1 dag)')
            ->assertSee('VOORBEELDMERK 1000001')
            ->assertSee('Ruim op voorraad');
    }

    public function test_staff_can_see_suppliers_but_not_edit_connections(): void
    {
        User::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));

        $this->get(SupplierResource::getUrl())->assertOk();
        $this->get(SupplierResource::getUrl('edit', ['record' => Supplier::first()]))->assertForbidden();
    }

    public function test_import_button_queues_the_job(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $travion = Supplier::where('code', 'travion')->sole();

        Livewire::test(ListSuppliers::class)
            ->callAction(TestAction::make('import_full')->table($travion));

        Queue::assertPushed(ImportSupplierFeed::class, fn ($job) => $job->supplierId === $travion->id && $job->kind === 'full');
    }

    public function test_mcp_search_by_ean_returns_prices_stock_and_availability(): void
    {
        $this->seedItem();
        User::factory()->create();
        $agent = User::factory()->create(['role' => UserRole::Agent]);

        BackofficeServer::actingAs($agent)
            ->tool(SearchSupplierItems::class, ['query' => '8712345678906'])
            ->assertOk()
            ->assertStructuredContentPath('count', 1)
            ->assertStructuredContentPath('items.0.net_price', 1020.5)
            ->assertStructuredContentPath('items.0.stock_quantity', 12)
            ->assertStructuredContentPath('availability_by_ean.8712345678906.status', 'ample');

        BackofficeServer::actingAs($agent)
            ->tool(SearchSupplierItems::class, ['query' => 'presentatie'])
            ->assertOk()
            ->assertSee('WPS-100');

        BackofficeServer::actingAs($agent)
            ->tool(BackofficeStatus::class)
            ->assertOk()
            ->assertSee('TD Synnex');
    }

    public function test_active_suppliers_are_scheduled_with_their_cron(): void
    {
        Supplier::where('code', 'tdsynnex')->update(['is_active' => true]);
        Supplier::where('code', 'copaco')->update(['is_active' => true, 'settings' => json_encode(['cron_full' => '45 6 * * *'])]);

        // Re-run the schedule definitions now that suppliers are active.
        require base_path('routes/console.php');

        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [$e->description => $e->expression]);

        $this->assertSame('20 9 * * *', $events['import tdsynnex stock']);
        $this->assertSame('15 9 * * *', $events['import tdsynnex catalog']);
        $this->assertSame('45 6 * * *', $events['import copaco full']);
        $this->assertArrayNotHasKey('import also stock', $events->all());
    }
}
