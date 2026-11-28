<?php

namespace Tests\Feature;

use App\Enums\JobRunStatus;
use App\Models\JobRun;
use App\Models\PriceList;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\SupplierStock;
use App\Suppliers\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const EAN = '8712345678906';

    /** Gives a supplier one item with this EAN and a current stock list of the given age. */
    private function stockAt(string $code, int $quantity, int $ageInDays = 0, ?string $nextDate = null): void
    {
        $supplier = Supplier::where('code', $code)->sole();
        $run = JobRun::create(['type' => 'test', 'label' => 'test', 'status' => JobRunStatus::Succeeded]);

        SupplierItem::create(['supplier_id' => $supplier->id, 'sku' => $code.'-1', 'ean' => self::EAN]);
        SupplierStock::create([
            'supplier_id' => $supplier->id, 'job_run_id' => $run->id, 'sku' => $code.'-1',
            'quantity' => $quantity, 'next_date' => $nextDate,
        ]);

        $supplier->update(['current_stock_run_id' => $run->id, 'stock_as_of' => now()->subDays($ageInDays)]);
    }

    private function availability(): array
    {
        return app(StockAvailability::class)->forEans([self::EAN])[self::EAN];
    }

    public function test_highest_quantity_at_one_supplier_decides_and_is_never_summed(): void
    {
        $this->stockAt('tdsynnex', 4);
        $this->stockAt('also', 3);

        $result = $this->availability();

        // 4 + 3 would be "ruim"; the highest single supplier (4) is "beperkt".
        $this->assertSame('limited', $result['status']);
        $this->assertSame(4, $result['best_quantity']);
        $this->assertSame('TD Synnex', $result['best_supplier']);
    }

    public function test_more_than_five_is_ample(): void
    {
        $this->stockAt('copaco', 6);

        $this->assertSame('ample', $this->availability()['status']);
    }

    public function test_stock_lists_older_than_five_days_do_not_count(): void
    {
        $this->stockAt('tdsynnex', 50, ageInDays: 6);
        $this->stockAt('also', 2, ageInDays: 1);

        $result = $this->availability();

        $this->assertSame('limited', $result['status']);
        $this->assertSame(2, $result['best_quantity']);
        $this->assertFalse(collect($result['suppliers'])->firstWhere('supplier_code', 'tdsynnex')['counts']);
    }

    public function test_backorder_shows_the_earliest_expected_date(): void
    {
        $this->stockAt('tdsynnex', 0, nextDate: '2026-12-20');
        $this->stockAt('also', 0, nextDate: '2026-12-12');

        $result = $this->availability();

        $this->assertSame('backorder', $result['status']);
        $this->assertSame('2026-12-12', $result['next_date']);
    }

    public function test_unknown_when_no_supplier_has_a_recent_list(): void
    {
        $this->stockAt('travion', 10, ageInDays: 9);

        $this->assertSame('unknown', $this->availability()['status']);
    }

    public function test_a_distributor_without_stock_data_counts_as_available_when_its_list_says_so(): void
    {
        $this->stockAt('tdsynnex', 0, nextDate: now()->addWeek()->toDateString());
        $list = PriceList::create(['name' => 'Midwich', 'file_path' => 'x.csv', 'status' => 'imported', 'assume_available' => true]);
        $list->items()->create(['ean' => self::EAN, 'purchase_price' => 100]);

        $result = $this->availability();
        $this->assertSame('available', $result['status']);
        $this->assertSame('Leverbaar', $result['label']);
        $this->assertSame('Midwich', $result['best_supplier']);

        // Real stock elsewhere still wins, and without the setting nothing changes.
        $list->update(['assume_available' => false]);
        $this->assertSame('backorder', $this->availability()['status']);
    }

    public function test_assumed_available_also_works_without_any_supplier_article(): void
    {
        $list = PriceList::create(['name' => 'Midwich', 'file_path' => 'x.csv', 'status' => 'imported', 'assume_available' => true]);
        $list->items()->create(['ean' => self::EAN, 'purchase_price' => 100]);

        $this->assertSame('available', $this->availability()['status']);
    }
}
