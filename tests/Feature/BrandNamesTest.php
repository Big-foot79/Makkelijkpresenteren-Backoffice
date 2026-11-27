<?php

namespace Tests\Feature;

use App\Filament\Resources\BrandAliases\BrandAliasResource;
use App\Models\BrandAlias;
use App\Models\FavoriteBrand;
use App\Models\JobRun;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\User;
use App\Suppliers\Writers\ItemWriter;
use App\Support\Brands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SupplierFixtures;
use Tests\TestCase;

class BrandNamesTest extends TestCase
{
    use RefreshDatabase;
    use SupplierFixtures;

    public function test_company_suffixes_are_dropped(): void
    {
        $this->assertSame('LG', Brands::canonical('LG Electronics'));
        $this->assertSame('LG', Brands::canonical('LG ELECTRONICS'));
        $this->assertSame('Samsung', Brands::canonical('Samsung Electronics Benelux B.V.'));
        $this->assertSame('Logitech', Brands::canonical('Logitech Europe S.A.'));
        $this->assertSame('Yealink', Brands::canonical('Yealink Co., Ltd.'));
        $this->assertSame('iiyama', Brands::canonical('iiyama'));
        $this->assertSame('Electronics', Brands::canonical('Electronics'));
        $this->assertNull(Brands::canonical('  '));
    }

    public function test_imports_store_one_brand_name_and_keep_the_supplier_spelling(): void
    {
        $supplier = Supplier::where('code', 'copaco')->sole();
        $writer = new ItemWriter($supplier, JobRun::create(['type' => 't', 'label' => 't', 'status' => 'running']));
        $writer->add(['sku' => 'X-1', 'brand' => 'LG Electronics', 'description' => 'Scherm']);
        $writer->finish();

        $item = SupplierItem::where('sku', 'X-1')->sole();
        $this->assertSame('LG', $item->brand);
        $this->assertSame('LG Electronics', $item->brand_raw);
    }

    public function test_a_fixed_link_is_applied_to_existing_articles_products_and_settings(): void
    {
        $supplier = $this->supplierWithFiles('tdsynnex', []);
        SupplierItem::create(['supplier_id' => $supplier->id, 'sku' => '1', 'brand' => 'Hewlett Packard', 'brand_raw' => 'Hewlett Packard']);
        Product::create(['name' => 'Laptop', 'brand' => 'Hewlett Packard']);
        FavoriteBrand::create(['name' => 'Hewlett Packard']);

        BrandAlias::create(['alias' => 'Hewlett Packard', 'brand' => 'HP']);

        $this->assertSame('hewlett packard', BrandAlias::sole()->alias);
        $this->assertSame('HP', SupplierItem::sole()->brand);
        $this->assertSame('HP', Product::sole()->brand);
        $this->assertSame('HP', FavoriteBrand::sole()->name);
    }

    public function test_screen_renders(): void
    {
        $this->actingAs(User::factory()->create());
        BrandAlias::create(['alias' => 'Hewlett Packard', 'brand' => 'HP']);

        $this->get(BrandAliasResource::getUrl())->assertOk()->assertSee('hewlett packard')->assertSee('LG Electronics = LG');
    }
}
