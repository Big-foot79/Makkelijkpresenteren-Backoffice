<?php

namespace App\Filament\Resources\SupplierItems\Pages;

use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Models\SupplierItem;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierItem extends ViewRecord
{
    protected static string $resource = SupplierItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_supplier')
                ->label(fn (SupplierItem $record) => 'Bekijk bij '.$record->supplier->name)
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (SupplierItem $record) => filled($record->supplierUrl()))
                ->url(fn (SupplierItem $record) => $record->supplierUrl(), shouldOpenInNewTab: true),
        ];
    }
}
