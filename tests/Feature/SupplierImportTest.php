<?php

namespace Tests\Feature;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Jobs\ImportSupplierFeed;
use App\Models\JobRun;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\SupplierStock;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SupplierFixtures;
use Tests\TestCase;

class SupplierImportTest extends TestCase
{
    use RefreshDatabase, SupplierFixtures;

    protected function tearDown(): void
    {
        $this->tearDownSupplierFixtures();
        parent::tearDown();
    }

    private function import(Supplier $supplier, string $kind): JobRun
    {
        try {
            (new ImportSupplierFeed($supplier->id, $kind))->handle();
        } catch (RuntimeException) {
            // A failed run is recorded; the tests inspect it.
        }

        return JobRun::latest('id')->first();
    }

    // ---------------------------------------------------------------- TD Synnex

    private function tdMaterial(array $overrides = []): string
    {
        $rows = $overrides ?: [
            // Matnr, ..., short descr (ANSI é), ..., MPN, brand local, brand global, prodhier, kit, EAN (12 digits)
            [0 => '1000001', 1 => '18', 2 => "Draadloos presentatiesysteem caf\xE9", 3 => 'Lange omschrijving', 4 => 'WPS-100',
                5 => 'Voorbeeldmerk NL', 6 => 'VOORBEELDMERK', 7 => 'AV01', 8 => 'N', 9 => '871234567890',
                19 => '1,250', 20 => '1.499,00', 21 => 'EUR'],
            [0 => '1000002', 2 => 'Scherm 55"', 4 => 'SCR-55', 6 => 'SCHERMCO', 9 => '8712345678906',
                20 => '899.00', 29 => '02', 30 => '1000003'],
        ];

        return implode("\r\n", array_map(fn ($r) => $this->line($r, 31), $rows))."\r\n";
    }

    public function test_td_synnex_catalog_reads_ansi_material_and_customer_prices(): void
    {
        $supplier = $this->supplierWithFiles('tdsynnex', [
            '123456_A_20261120.zip' => ['old.txt' => ''],
            '123456_A_20261126.zip' => [
                '123456_MaterialFile_20261126.txt' => $this->tdMaterial(),
                '123456_CustSpecific_20261126.txt' => "0012345;1000001;1.020,50;EUR;3,95\r\n0012345;9999999;10,00;EUR;\r\n",
                '123456_CustSpecificQty_20261126.txt' => "0012345;1000001;5;999,00\r\n",
            ],
        ]);
        touch($supplier->root.'/123456_A_20261120.zip', now()->subDays(6)->timestamp);

        $run = $this->import($supplier, 'catalog');

        $this->assertSame(JobRunStatus::Succeeded, $run->status, (string) $run->log);
        $this->assertStringContainsString('2 artikelen, 1 prijzen', $run->summary);

        $item = SupplierItem::where('sku', '1000001')->sole();
        $this->assertSame('Draadloos presentatiesysteem café', $item->description);
        $this->assertSame('0871234567890', $item->ean);
        $this->assertSame('VOORBEELDMERK', $item->brand);
        $this->assertSame('1499.00', $item->msrp);
        $this->assertSame('1020.50', $item->net_price);
        $this->assertSame('3.95', $item->surcharge);
        $this->assertSame('1.250', $item->weight_kg);

        $second = SupplierItem::where('sku', '1000002')->sole();
        $this->assertSame('Scherm 55"', $second->description);
        $this->assertSame('eol_with_follower', $second->status);
        $this->assertSame('1000003', $second->follower_sku);
        $this->assertNull($second->net_price);

        $this->assertFalse(SupplierItem::where('sku', '9999999')->exists(), 'Price-only skus must not create items');
    }

    public function test_items_missing_from_a_new_catalogue_are_delisted(): void
    {
        $supplier = $this->supplierWithFiles('tdsynnex', [
            '123456_A_20261126.zip' => ['123456_MaterialFile_20261126.txt' => $this->tdMaterial()],
        ]);
        $this->import($supplier, 'catalog');

        $this->putRemoteFile($supplier->root, '123456_A_20261127.zip', [
            '123456_MaterialFile_20261127.txt' => $this->tdMaterial([[0 => '1000001', 9 => '8712345678906'], [0 => '1000004']]),
        ], now()->addMinute()->timestamp);
        $this->travel(1)->days();

        $this->import($supplier, 'catalog');

        $this->assertTrue(SupplierItem::where('sku', '1000001')->value('is_listed'));
        $this->assertFalse(SupplierItem::where('sku', '1000002')->value('is_listed'));
        $this->assertTrue(SupplierItem::where('sku', '1000004')->value('is_listed'));
    }

    public function test_td_synnex_stock_handles_locations_and_special_dates(): void
    {
        $supplier = $this->supplierWithFiles('tdsynnex', [
            '123456_C_20261126.zip' => ['123456_StockFile_20261126.txt' => implode("\r\n", [
                '1000001;12;;WPS-100;1818;1',
                '1000001;30;;WPS-100;1854;1',
                '1000002;0;17.11.2016;SCR-55;1818;9',
                '1000003;0;'.now()->addDays(10)->format('d.m.Y').';X;1818;4',
                '1000004;0;01.02.2020;Y;1818;4',
            ])],
        ]);

        $run = $this->import($supplier, 'stock');

        $this->assertSame(JobRunStatus::Succeeded, $run->status, (string) $run->log);
        $this->assertSame($run->id, $supplier->fresh()->current_stock_run_id);
        $this->assertNotNull($supplier->fresh()->stock_as_of);

        $this->assertSame(42, (int) SupplierStock::where('sku', '1000001')->sum('quantity'));
        $this->assertSame(['1818', '1854'], SupplierStock::where('sku', '1000001')->orderBy('location')->pluck('location')->all());

        $allocation = SupplierStock::where('sku', '1000002')->sole();
        $this->assertSame('allocation', $allocation->note);
        $this->assertNull($allocation->next_date);

        $this->assertSame(now()->addDays(10)->toDateString(), SupplierStock::where('sku', '1000003')->sole()->next_date->toDateString());
        $this->assertSame('overdue', SupplierStock::where('sku', '1000004')->sole()->note);
    }

    public function test_a_much_smaller_stock_list_is_rejected_and_the_previous_one_stays(): void
    {
        $lines = collect(range(1, 10))->map(fn ($i) => "10000{$i};5;;X;1818;1")->implode("\r\n");
        $supplier = $this->supplierWithFiles('tdsynnex', ['123456_C_20261125.zip' => ['123456_StockFile_20261125.txt' => $lines]]);
        $good = $this->import($supplier, 'stock');

        $this->putRemoteFile($supplier->root, '123456_C_20261126.zip', [
            '123456_StockFile_20261126.txt' => "100001;5;;X;1818;1\r\n",
        ], now()->addMinute()->timestamp);

        $bad = $this->import($supplier, 'stock');

        $this->assertSame(JobRunStatus::Failed, $bad->status);
        $this->assertSame($good->id, $supplier->fresh()->current_stock_run_id);
        $this->assertSame(0, SupplierStock::where('job_run_id', $bad->id)->count());
        $this->assertSame(10, SupplierStock::where('job_run_id', $good->id)->count());
        $this->assertTrue(Task::where('kind', TaskKind::JobFailed)->where('job_run_id', $bad->id)->exists());
    }

    public function test_only_the_current_and_previous_stock_lists_are_kept(): void
    {
        $supplier = $this->supplierWithFiles('tdsynnex', []);
        $runs = [];

        foreach ([24, 25, 26] as $i => $day) {
            $this->putRemoteFile($supplier->root, "123456_C_202611{$day}.zip", [
                "123456_StockFile_202611{$day}.txt" => "1000001;{$day};;X;1818;1\r\n",
            ], now()->addMinutes($i)->timestamp);
            $runs[] = $this->import($supplier, 'stock')->id;
        }

        $this->assertSame([$runs[1], $runs[2]], SupplierStock::orderBy('job_run_id')->pluck('job_run_id')->all());
        $this->assertSame($runs[2], $supplier->fresh()->current_stock_run_id);
    }

    public function test_a_missing_file_fails_the_run_with_a_clear_message(): void
    {
        $supplier = $this->supplierWithFiles('tdsynnex', []);

        $run = $this->import($supplier, 'stock');

        $this->assertSame(JobRunStatus::Failed, $run->status);
        $this->assertStringContainsString('Geen bestand gevonden', $run->summary);
    }

    // ---------------------------------------------------------------- ALSO

    public function test_also_stock_and_catalog(): void
    {
        $stock = implode("\n", [
            "ProductID\tAvailableQuantity\tAvailableNextDate\tAvailableNextQuantity\tAvailabilityDate\tAvailabilityTime",
            "A-100\t7\t\t\t20260715\t190850",
            "A-200\t0\t20261210\t25\t20260715\t190850",
        ]);
        $catalogLine = fn (array $c) => $this->line($c, 16);

        $supplier = $this->supplierWithFiles('also', [
            'stock.txt' => $stock,
            'pricelist-2.txt.zip' => ['pricelist.txt' => implode("\n", [
                $catalogLine([0 => 'A-100', 1 => '8712345678906', 5 => 'Videobar', 7 => '1234,56', 8 => 'VB-1', 9 => 'Merk A', 13 => '24']),
                $catalogLine([0 => 'A-200', 1 => '', 5 => 'Kabel', 7 => '', 8 => 'K-1', 9 => 'Merk B', 15 => '9,99']),
                'kapotte;regel',
            ])],
        ]);

        $stockRun = $this->import($supplier, 'stock');
        $this->assertSame(JobRunStatus::Succeeded, $stockRun->status, (string) $stockRun->log);

        $line = SupplierStock::where('sku', 'A-100')->sole();
        $this->assertSame(7, $line->quantity);
        $this->assertSame('2026-07-15 19:08:50', $line->as_of->format('Y-m-d H:i:s'));

        $backorder = SupplierStock::where('sku', 'A-200')->sole();
        $this->assertSame('2026-12-10', $backorder->next_date->toDateString());
        $this->assertSame(25, $backorder->next_quantity);

        $catalogRun = $this->import($supplier, 'catalog');
        $this->assertSame(JobRunStatus::Succeeded, $catalogRun->status, (string) $catalogRun->log);
        $this->assertStringContainsString('1 regels overgeslagen', $catalogRun->summary);

        $item = SupplierItem::where('sku', 'A-100')->sole();
        $this->assertSame('1234.56', $item->net_price);
        $this->assertSame(24, $item->warranty_months);
        $this->assertSame('9.99', SupplierItem::where('sku', 'A-200')->value('net_price'));
    }

    // ---------------------------------------------------------------- Travion

    public function test_travion_reads_articles_prices_and_stock_from_one_file(): void
    {
        $supplier = $this->supplierWithFiles('travion', [
            'pricefile_20261126.csv' => implode("\n", [
                'ARTICLENR;DESCRIPTION;EANNR;OEMNR;BRAND;UNSPSCDESCRIPTION;PRICE;CURRENCY;STOCK',
                'T-1;Muurbeugel 32-65";8712345678906;MB-1;Beugelmerk;Beugels;49,95;EUR;120',
                'T-2;Kabel HDMI 2m;;HD-2;Kabelmerk;Kabels;4,50;EUR;0',
            ]),
        ]);

        $run = $this->import($supplier, 'full');

        $this->assertSame(JobRunStatus::Succeeded, $run->status, (string) $run->log);
        $this->assertSame('Muurbeugel 32-65"', SupplierItem::where('sku', 'T-1')->value('description'));
        $this->assertSame('49.95', SupplierItem::where('sku', 'T-1')->value('net_price'));
        $this->assertSame(120, SupplierStock::where('sku', 'T-1')->value('quantity'));
        $this->assertNull(SupplierStock::where('sku', 'T-2')->value('next_date'));
    }

    // ---------------------------------------------------------------- Copaco

    public function test_copaco_skips_the_header_block_and_reads_quoted_csv(): void
    {
        $header = implode("\n", array_fill(0, 15, 'Copaco prijslijst,kopregel'));
        $rows = implode("\n", [
            'C-1,PRJ-1,Projectormerk,"Projector 4.000 lumen, laser",x,"1.299,00",3,Projectoren,x,8712345678906,1',
            'C-2,SCR-1,Schermmerk,"Projectiescherm 100""",x,"89,00",0,Schermen,x,,4',
        ]);

        $supplier = $this->supplierWithFiles('copaco', ['Copaco_prijslijst_114116.csv' => $header."\n".$rows]);

        $run = $this->import($supplier, 'full');

        $this->assertSame(JobRunStatus::Succeeded, $run->status, (string) $run->log);
        $this->assertSame(2, SupplierItem::where('supplier_id', $supplier->id)->count());

        $projector = SupplierItem::where('sku', 'C-1')->sole();
        $this->assertSame('Projector 4.000 lumen, laser', $projector->description);
        $this->assertSame('1299.00', $projector->net_price);
        $this->assertSame('PRJ-1', $projector->mpn);
        $this->assertSame('Projectiescherm 100"', SupplierItem::where('sku', 'C-2')->value('description'));
        $this->assertSame('4', SupplierStock::where('sku', 'C-2')->value('availability'));
    }
}
