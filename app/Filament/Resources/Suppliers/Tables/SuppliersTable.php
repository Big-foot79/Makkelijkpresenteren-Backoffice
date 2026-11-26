<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Filament\Resources\Suppliers\SupplierActions;
use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['items as listed_items_count' => fn (Builder $q) => $q->where('is_listed', true)]))
            ->columns([
                TextColumn::make('name')
                    ->label('Leverancier')
                    ->description(fn (Supplier $record) => strtoupper($record->protocol).($record->host ? ' · '.$record->host : '')),
                IconColumn::make('is_active')
                    ->label('Actief')
                    ->boolean(),
                TextColumn::make('stock_as_of')
                    ->label('Voorraadlijst')
                    ->since()
                    ->placeholder('Nog niet ingelezen')
                    ->badge()
                    ->color(fn (Supplier $record) => $record->stockIsStale() ? 'danger' : 'success')
                    ->tooltip(fn (Supplier $record) => $record->stockIsStale()
                        ? 'Ouder dan '.config('backoffice.stock.max_age_days').' dagen: telt niet mee voor de voorraad op de site'
                        : $record->stock_as_of?->format('d-m-Y H:i')),
                TextColumn::make('catalog_imported_at')
                    ->label('Catalogus')
                    ->since()
                    ->placeholder('Nog niet ingelezen'),
                TextColumn::make('listed_items_count')
                    ->label('Artikelen')
                    ->numeric(thousandsSeparator: '.'),
            ])
            ->recordActions([
                SupplierActions::imports(),
                EditAction::make(),
            ]);
    }
}
