<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Resources\PriceLists\PriceListResource;
use App\Pricing\PriceListImporter;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceList extends CreateRecord
{
    protected static string $resource = PriceListResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['uploaded_by_user_id' => auth()->id()];
    }

    /** Find the header row and guess the columns, so the next screen is usually just "Inlezen". */
    protected function afterCreate(): void
    {
        rescue(function () {
            $this->record->update(['header_row' => PriceListImporter::detectHeaderRow($this->record)]);
            $this->record->update(['columns' => PriceListImporter::guess(PriceListImporter::headers($this->record))]);
        }, report: false);
    }

    protected function getRedirectUrl(): string
    {
        return PriceListResource::getUrl('edit', ['record' => $this->record]);
    }
}
