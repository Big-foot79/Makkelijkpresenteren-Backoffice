<?php

namespace App\Filament\Resources\SupplierItems\Pages;

use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Models\FavoriteBrand;
use App\Models\SupplierItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierItem extends ViewRecord
{
    protected static string $resource = SupplierItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('favorite_brand')
                ->label(fn (SupplierItem $record) => FavoriteBrand::isFavorite($record->brand)
                    ? $record->brand.' is favoriet'
                    : $record->brand.' als favoriet merk')
                ->icon(fn (SupplierItem $record) => FavoriteBrand::isFavorite($record->brand) ? Heroicon::Star : Heroicon::OutlinedStar)
                ->color('gray')
                ->visible(fn (SupplierItem $record) => filled($record->brand))
                ->disabled(fn (SupplierItem $record) => FavoriteBrand::isFavorite($record->brand))
                ->action(function (SupplierItem $record) {
                    FavoriteBrand::create(['name' => $record->brand, 'sort' => (int) FavoriteBrand::max('sort') + 1]);

                    Notification::make()->title($record->brand.' staat nu als tabblad boven de artikellijst')->success()->send();
                }),
            Action::make('open_supplier')
                ->label(fn (SupplierItem $record) => 'Bekijk bij '.$record->supplier->name)
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (SupplierItem $record) => filled($record->supplierUrl()))
                ->url(fn (SupplierItem $record) => $record->supplierUrl(), shouldOpenInNewTab: true),
        ];
    }
}
