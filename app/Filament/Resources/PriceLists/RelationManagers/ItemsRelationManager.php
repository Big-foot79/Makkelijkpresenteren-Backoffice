<?php

namespace App\Filament\Resources\PriceLists\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Ingelezen regels';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('description')
            ->columns([
                TextColumn::make('ean')
                    ->label('EAN')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->description(fn ($record) => $record->ean_source === 'mpn' ? 'via artikelnummer' : null)
                    ->searchable(),
                TextColumn::make('mpn')->label('Artikelnummer')->fontFamily('mono')->placeholder('—')->searchable(),
                TextColumn::make('description')->label('Omschrijving')->limit(60)->placeholder('—')->searchable(),
                TextColumn::make('msrp')->label('Adviesprijs')->money('EUR', locale: 'nl')->placeholder('—')->sortable(),
                TextColumn::make('purchase_price')->label('Inkoop/bid')->money('EUR', locale: 'nl')->placeholder('—')->sortable(),
            ])
            ->filters([
                Filter::make('without_ean')->label('Zonder EAN')->query(fn (Builder $q) => $q->whereNull('ean')),
            ]);
    }
}
