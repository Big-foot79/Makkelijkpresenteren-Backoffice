<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Suppliers\EanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Articles without EAN get one via manufacturer part number + brand, only when unambiguous. Fictional data. */
class EanResolverTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $td;

    private Supplier $copaco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->td = Supplier::where('code', 'tdsynnex')->sole();
        $this->copaco = Supplier::where('code', 'copaco')->sole();
    }

    private function td(string $sku, string $mpn, ?string $brand = "Vogel's"): SupplierItem
    {
        return SupplierItem::create(['supplier_id' => $this->td->id, 'sku' => $sku, 'mpn' => $mpn, 'brand' => $brand, 'is_listed' => true]);
    }

    public function test_ean_comes_from_the_webshop_product_with_that_sku(): void
    {
        Product::create(['ean' => '8712285341234', 'sku' => 'VFM-F21', 'name' => 'Vloerstandaard', 'brand' => "Vogel's"]);
        $item = $this->td('10366309', 'VFM-F21', "VOGEL'S");

        $this->assertSame(1, app(EanResolver::class)->forSupplier($this->td));
        $this->assertSame('8712285341234', $item->fresh()->ean);
        $this->assertSame('mpn', $item->fresh()->ean_source);
    }

    public function test_ean_comes_from_another_supplier_with_the_same_part_number(): void
    {
        SupplierItem::create(['supplier_id' => $this->copaco->id, 'sku' => 'C-9', 'mpn' => 'vfm-f21', 'brand' => "Vogel's", 'ean' => '8712285341234', 'is_listed' => true]);
        $item = $this->td('10366309', 'VFM-F21');

        app(EanResolver::class)->forSupplier($this->td);

        $this->assertSame('8712285341234', $item->fresh()->ean);
    }

    public function test_nothing_is_filled_in_when_ambiguous_or_another_brand(): void
    {
        SupplierItem::create(['supplier_id' => $this->copaco->id, 'sku' => 'C-1', 'mpn' => 'X100', 'brand' => 'Merk A', 'ean' => '8712345678906', 'is_listed' => true]);
        SupplierItem::create(['supplier_id' => $this->copaco->id, 'sku' => 'C-2', 'mpn' => 'X100', 'brand' => 'Merk A', 'ean' => '5901234123457', 'is_listed' => true]);
        $ambiguous = $this->td('1', 'X100', 'Merk A');
        $otherBrand = $this->td('2', 'X100', 'Merk B');

        $this->assertSame(0, app(EanResolver::class)->forSupplier($this->td));
        $this->assertNull($ambiguous->fresh()->ean);
        $this->assertNull($otherBrand->fresh()->ean);
    }
}
