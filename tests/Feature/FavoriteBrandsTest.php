<?php

namespace Tests\Feature;

use App\Filament\Resources\FavoriteBrands\FavoriteBrandResource;
use App\Filament\Resources\SupplierItems\Pages\ListSupplierItems;
use App\Filament\Resources\SupplierItems\Pages\ViewSupplierItem;
use App\Models\FavoriteBrand;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FavoriteBrandsTest extends TestCase
{
    use RefreshDatabase;

    private array $items;

    protected function setUp(): void
    {
        parent::setUp();

        $td = Supplier::where('code', 'tdsynnex')->sole();
        $copaco = Supplier::where('code', 'copaco')->sole();

        $this->items = [
            'td_iiyama' => SupplierItem::create(['supplier_id' => $td->id, 'sku' => '1', 'brand' => 'IIYAMA', 'description' => 'Scherm A']),
            'copaco_iiyama' => SupplierItem::create(['supplier_id' => $copaco->id, 'sku' => 'C1', 'brand' => 'Iiyama', 'description' => 'Scherm B']),
            'barco' => SupplierItem::create(['supplier_id' => $td->id, 'sku' => '2', 'brand' => 'BARCO', 'description' => 'ClickShare']),
        ];

        $this->actingAs(User::factory()->create());
    }

    public function test_each_favourite_brand_is_a_tab_matching_any_capitalisation(): void
    {
        $iiyama = FavoriteBrand::create(['name' => 'IIYAMA']);

        Livewire::test(ListSupplierItems::class)
            ->assertSee('Alle merken')
            ->assertSee('IIYAMA')
            ->set('activeTab', 'brand-'.$iiyama->id)
            ->assertCanSeeTableRecords([$this->items['td_iiyama'], $this->items['copaco_iiyama']])
            ->assertCanNotSeeTableRecords([$this->items['barco']]);
    }

    public function test_brand_filter_is_case_insensitive(): void
    {
        Livewire::test(ListSupplierItems::class)
            ->filterTable('brand', ['Iiyama'])
            ->assertCanSeeTableRecords([$this->items['td_iiyama'], $this->items['copaco_iiyama']])
            ->assertCanNotSeeTableRecords([$this->items['barco']]);
    }

    public function test_a_brand_can_be_made_favourite_from_an_article(): void
    {
        Livewire::test(ViewSupplierItem::class, ['record' => $this->items['barco']->getRouteKey()])
            ->callAction('favorite_brand');

        $this->assertTrue(FavoriteBrand::isFavorite('barco'));
        $this->get(FavoriteBrandResource::getUrl())->assertOk()->assertSee('BARCO');
    }
}
