<?php

namespace App\Filament\Resources\FavoriteBrands\Pages;

use App\Filament\Resources\FavoriteBrands\FavoriteBrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFavoriteBrands extends ManageRecords
{
    protected static string $resource = FavoriteBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Merk toevoegen'),
        ];
    }
}
