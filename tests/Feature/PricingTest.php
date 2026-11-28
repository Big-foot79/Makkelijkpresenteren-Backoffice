<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\BrandTerms\BrandTermResource;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\ImportPriceList;
use App\Models\BrandTerm;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\User;
use App\Pricing\PriceListImporter;
use App\Pricing\PurchasePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Expected purchase price (bid, brand discount on the advice price, list) and price list uploads. Fictional data. */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    private const EAN = '4948570123457';

    private Supplier $td;

    protected function setUp(): void
    {
        parent::setUp();

        $this->td = Supplier::where('code', 'tdsynnex')->sole();
        SupplierItem::create(['supplier_id' => $this->td->id, 'sku' => 'TD-9', 'ean' => self::EAN, 'mpn' => 'LH5560-B1', 'brand' => 'IIYAMA', 'net_price' => 800, 'msrp' => 1000, 'description' => 'Scherm 55 inch']);
        Product::create(['ean' => self::EAN, 'name' => 'iiyama 55 inch', 'brand' => 'iiyama', 'webshop_price' => 1100]);
    }

    private function price(): array
    {
        app(PurchasePrice::class)->flush();

        return app(PurchasePrice::class)->forEan(self::EAN);
    }

    public function test_without_agreements_the_distributor_price_counts(): void
    {
        $this->assertSame(800.0, $this->price()['expected']);
        $this->assertSame('list', $this->price()['source']);
    }

    public function test_brand_discount_on_the_advice_price_wins_when_lower(): void
    {
        BrandTerm::create(['brand' => 'Iiyama', 'discount_percent' => 25, 'supplier_id' => $this->td->id]);

        $price = $this->price();
        $this->assertSame(750.0, $price['expected']);
        $this->assertSame('discount', $price['source']);
        $this->assertSame('Adviesprijs € 1.000,00 − 25% (Iiyama) via TD Synnex', $price['label']);

        // A small discount that ends above the distributor price: the list price stays the cheapest.
        BrandTerm::query()->update(['discount_percent' => 10]);
        $this->assertSame('list', $this->price()['source']);
    }

    public function test_a_current_bid_list_wins_and_an_expired_one_does_not_count(): void
    {
        $list = PriceList::create(['name' => 'iiyama bids', 'brand' => 'iiyama', 'file_path' => 'x.xlsx', 'status' => 'imported', 'imported_at' => now(), 'valid_until' => now()->addMonth()]);
        $list->items()->create(['ean' => self::EAN, 'purchase_price' => 690, 'msrp' => 1050]);

        $this->assertSame(690.0, $this->price()['expected']);
        $this->assertSame('Bidprijs uit iiyama bids', $this->price()['label']);

        $list->update(['valid_until' => now()->subDay()]);
        $this->assertSame('list', $this->price()['source']);
    }

    public function test_the_advice_price_from_a_price_list_is_used_for_the_brand_discount(): void
    {
        BrandTerm::create(['brand' => 'iiyama', 'discount_percent' => 30]);
        $list = PriceList::create(['name' => 'iiyama adviesprijzen', 'file_path' => 'x.xlsx', 'status' => 'imported', 'imported_at' => now()]);
        $list->items()->create(['ean' => self::EAN, 'msrp' => 1200]);

        $this->assertSame(840.0, $this->price()['discount']['price']);
        $this->assertSame('iiyama adviesprijzen', $this->price()['discount']['msrp_from']);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('iiyama-bids.xlsx', file_get_contents($path));
    }

    public function test_an_excel_price_list_is_uploaded_mapped_and_read_in(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $file = $this->xlsx([
            ['iiyama bidprijzen Q4 – vertrouwelijk'],
            ['Artikelnummer', 'EAN code', 'Omschrijving', 'Adviesprijs', 'Bidprijs'],
            ['LH5560-B1', 4948570123457.0, 'ProLite 55 inch', 1000, 640.5],   // EAN as a number
            ['LH5560-B1', '', 'ProLite 55 inch (zonder EAN)', 1000, 650],     // EAN via the part number
            ['XUB2490', '', 'Onbekend scherm', 250, 180],                      // no EAN anywhere
            ['', '', 'Tussenkopje', '', ''],                                   // skipped
        ]);

        Livewire::test(CreatePriceList::class)
            ->fillForm(['name' => 'iiyama Q4', 'brand' => 'IIYAMA', 'supplier_id' => $this->td->id, 'file_path' => $file])
            ->call('create')
            ->assertHasNoFormErrors();

        // The title line above the headers is skipped automatically.
        $list = PriceList::sole();
        $this->assertSame(2, $list->header_row);
        $this->assertSame(['ean' => 1, 'mpn' => 0, 'description' => 2, 'msrp' => 3, 'purchase_price' => 4], $list->fresh()->columns);

        Livewire::test(EditPriceList::class, ['record' => $list->getRouteKey()])
            ->assertSee('ProLite 55 inch')          // preview
            ->callAction('import');

        // The queue runs synchronously in tests.
        $list->refresh();
        $this->assertSame('imported', $list->status);
        $this->assertSame(3, $list->rows_imported);
        $this->assertSame(1, $list->rows_without_ean);
        $this->assertSame(2, $list->items()->where('ean', self::EAN)->count());
        $this->assertSame('mpn', $list->items()->where('description', 'like', '%zonder EAN%')->value('ean_source'));
        $this->assertSame(640.5, $this->price()['expected']);
    }

    public function test_a_semicolon_csv_in_windows_1252_is_read(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('price-lists/sony.csv', mb_convert_encoding("Part No;EAN;Description;List price;Net price\nFW-55BZ;4948570123457;Bravia 55\" – €;1.000,00;712,40\n", 'Windows-1252', 'UTF-8'));
        $list = PriceList::create(['name' => 'Sony', 'file_path' => 'price-lists/sony.csv']);
        $list->update(['columns' => PriceListImporter::guess(PriceListImporter::headers($list))]);

        (new ImportPriceList($list->id))->handle(app(PriceListImporter::class));

        $item = $list->items()->sole();
        $this->assertSame('712.40', $item->purchase_price);
        $this->assertSame('1000.00', $item->msrp);
        $this->assertStringContainsString('€', $item->description);
    }

    public function test_a_list_without_usable_columns_fails_visibly(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('price-lists/leeg.csv', "a;b\n1;2\n");
        $list = PriceList::create(['name' => 'Leeg', 'file_path' => 'price-lists/leeg.csv', 'columns' => []]);

        try {
            (new ImportPriceList($list->id))->handle(app(PriceListImporter::class));
        } catch (\RuntimeException) {
        }

        $this->assertSame('failed', $list->fresh()->status);
    }

    public function test_screens_show_expected_purchase_and_margin(): void
    {
        BrandTerm::create(['brand' => 'iiyama', 'discount_percent' => 25, 'supplier_id' => $this->td->id]);
        $this->actingAs(User::factory()->create());

        $this->get(BrandTermResource::getUrl())->assertOk()->assertSee('25%')->assertSee('TD Synnex');
        $this->get(PriceListResource::getUrl())->assertOk();
        $this->get(ProductResource::getUrl())->assertOk()->assertSee('750,00')->assertSee('merkkorting')->assertSee('31,8%');
        $this->get(ProductResource::getUrl('view', ['record' => Product::sole()]))->assertOk()->assertSee('Merkkorting')->assertSee('Lijst');
    }

    public function test_only_admins_change_brand_agreements(): void
    {
        User::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->assertFalse($staff->can('create', BrandTerm::class));
        $this->assertTrue($staff->can('create', PriceList::class));
    }

    public function test_a_midwich_style_list_finds_eans_via_the_model_in_the_name_and_the_shop_sku(): void
    {
        Storage::fake('local');
        Product::create(['ean' => '6938818316287', 'sku' => 'MVC860-C5-000', 'name' => 'Yealink MVC860', 'brand' => 'Yealink']);
        Storage::disk('local')->put('price-lists/midwich.csv', implode("\n", [
            'SKU,MPN,Naam,Artikelnummer,Prijs',
            'YEAL-1106984,1106984,"Yealink MVC860-C5-000",1YEA1106984,3424.520',
            'BRIG-HD226,HD226,"BrightSign HD226",1HD226,401.750',
            'YEAL-1106987,1106987,"Yealink MVC640-C4-000",1YEA1106987,POA',
        ]));
        $list = PriceList::create(['name' => 'Midwich', 'file_path' => 'price-lists/midwich.csv']);
        $list->update(['columns' => PriceListImporter::guess(PriceListImporter::headers($list))]);

        $this->assertSame(1, $list->fresh()->columns['mpn']);
        $this->assertSame(2, $list->fresh()->columns['description']);
        $this->assertSame(4, $list->fresh()->columns['purchase_price']);

        (new ImportPriceList($list->id))->handle(app(PriceListImporter::class));

        $list->refresh();
        $this->assertSame(2, $list->rows_imported);               // POA has no price: skipped
        $item = $list->items()->where('mpn', '1106984')->sole();
        $this->assertSame('6938818316287', $item->ean);
        $this->assertSame('3424.52', $item->purchase_price);
    }
}
