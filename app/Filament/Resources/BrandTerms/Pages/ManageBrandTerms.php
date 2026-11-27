<?php

namespace App\Filament\Resources\BrandTerms\Pages;

use App\Filament\Resources\BrandTerms\BrandTermResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBrandTerms extends ManageRecords
{
    protected static string $resource = BrandTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Merkafspraak toevoegen'),
        ];
    }
}
