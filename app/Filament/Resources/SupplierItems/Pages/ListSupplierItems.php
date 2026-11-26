<?php

namespace App\Filament\Resources\SupplierItems\Pages;

use App\Filament\Resources\FavoriteBrands\FavoriteBrandResource;
use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Models\FavoriteBrand;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierItems extends ListRecords
{
    protected static string $resource = SupplierItemResource::class;

    /** "Alle merken" plus one quick tab per favourite brand. */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Alle merken')];

        foreach (FavoriteBrand::orderBy('sort')->orderBy('name')->get() as $brand) {
            $tabs['brand-'.$brand->id] = Tab::make($brand->name)
                ->modifyQueryUsing(fn (Builder $query) => FavoriteBrand::applyTo($query, $brand->name));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('favorites')
                ->label('Favoriete merken beheren')
                ->icon(Heroicon::OutlinedStar)
                ->color('gray')
                ->url(FavoriteBrandResource::getUrl()),
        ];
    }
}
