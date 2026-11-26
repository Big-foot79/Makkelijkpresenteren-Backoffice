<?php

namespace App\Filament\Resources\SupplierItems\Tables;

use App\Models\SupplierItem;
use App\Support\Parse;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('description')
            ->modifyQueryUsing(fn (Builder $query) => $query->select('supplier_items.*')->withCurrentStock()->with('supplier:id,name'))
            ->searchPlaceholder('EAN, artikelnummer, merk of omschrijving')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Leverancier')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('description')
                    ->label('Omschrijving')
                    ->description(fn (SupplierItem $record) => collect([$record->brand, $record->mpn])->filter()->implode(' · '))
                    ->limit(70)
                    ->tooltip(fn (SupplierItem $record) => $record->description)
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search) {
                        $ean = Parse::ean($search);

                        $query->where(function (Builder $q) use ($search, $ean) {
                            $q->where('supplier_items.sku', $search)
                                ->orWhere('supplier_items.mpn', $search)
                                ->orWhere('supplier_items.description', 'like', "%{$search}%")
                                ->orWhere('supplier_items.brand', 'like', "{$search}%");

                            if ($ean) {
                                $q->orWhere('supplier_items.ean', $ean);
                            }
                        });
                    }),
                TextColumn::make('sku')
                    ->label('Art.nr.')
                    ->fontFamily('mono')
                    ->toggleable(),
                TextColumn::make('ean')
                    ->label('EAN')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('net_price')
                    ->label('Inkoop')
                    ->money('EUR', locale: 'nl')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('msrp')
                    ->label('Adviesprijs')
                    ->money('EUR', locale: 'nl')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('stock_quantity')
                    ->label('Voorraad')
                    ->numeric(thousandsSeparator: '.')
                    ->placeholder('—')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (?string $state) => SupplierItem::STATUSES[$state] ?? $state)
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_listed')
                    ->label('In catalogus')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Leverancier')
                    ->relationship('supplier', 'name'),
                TernaryFilter::make('is_listed')
                    ->label('In catalogus')
                    ->default(true),
                Filter::make('with_ean')
                    ->label('Met EAN')
                    ->query(fn (Builder $query) => $query->whereNotNull('supplier_items.ean')),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(SupplierItem::STATUSES),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
