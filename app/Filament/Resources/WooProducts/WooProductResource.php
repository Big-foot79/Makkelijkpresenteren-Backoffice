<?php

namespace App\Filament\Resources\WooProducts;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Filament\Resources\WooProducts\Pages\ListWooProducts;
use App\Models\WooProduct;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The link report: every product in the shop and whether it can be linked by EAN. */
class WooProductResource extends Resource
{
    protected static ?string $model = WooProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Webshop';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'webshopproduct';

    protected static ?string $pluralModelLabel = 'webshopproducten';

    protected static ?string $navigationLabel = 'Koppelrapport';

    protected static ?string $slug = 'koppelrapport';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->searchPlaceholder('Naam, SKU of EAN')
            ->columns([
                TextColumn::make('match_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => WooProduct::MATCH_STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'linked' => 'success',
                        'ok' => 'info',
                        'no_ean', 'duplicate_ean' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('name')
                    ->label('Product in de webshop')
                    ->description(fn (WooProduct $record) => collect([$record->brand, $record->sku])->filter()->implode(' · '))
                    ->limit(70)
                    ->tooltip(fn (WooProduct $record) => $record->name)
                    ->sortable()
                    ->searchable(['name', 'sku', 'ean']),
                TextColumn::make('ean')
                    ->label('EAN')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->description(fn (WooProduct $record) => match ($record->ean_source) {
                        'gtin' => 'GTIN-veld',
                        'attribute' => 'attribuut',
                        'meta' => 'metaveld',
                        default => null,
                    }),
                TextColumn::make('supplier_matches')
                    ->label('Bij leveranciers')
                    ->numeric()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray')
                    ->url(fn (WooProduct $record) => $record->ean && $record->supplier_matches
                        ? SupplierItemResource::getUrl('index', ['tableSearch' => $record->ean])
                        : null)
                    ->sortable(),
                TextColumn::make('regular_price')->label('Prijs')->money('EUR', locale: 'nl')->placeholder('—')->sortable(),
                TextColumn::make('stock_status')
                    ->label('Voorraad shop')
                    ->formatStateUsing(fn (?string $state) => ['instock' => 'Op voorraad', 'outofstock' => 'Uitverkocht', 'onbackorder' => 'Nabestelling'][$state] ?? $state)
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('manage_stock')->label('Telt zelf voorraad')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Publicatie')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')->label('Type')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('match_status')->label('Status')->options(WooProduct::MATCH_STATUSES),
                SelectFilter::make('ean_source')->label('EAN uit')->options(['gtin' => 'GTIN-veld', 'attribute' => 'Attribuut', 'meta' => 'Metaveld']),
                TernaryFilter::make('with_supplier')
                    ->label('Bij een leverancier')
                    ->queries(
                        true: fn (Builder $q) => $q->where('supplier_matches', '>', 0),
                        false: fn (Builder $q) => $q->where('supplier_matches', 0),
                    ),
                SelectFilter::make('status')->label('Publicatie')->options(['publish' => 'Gepubliceerd', 'draft' => 'Concept', 'private' => 'Privé', 'pending' => 'Ter beoordeling']),
            ])
            ->recordUrl(fn (WooProduct $record) => $record->product_id ? ProductResource::getUrl('view', ['record' => $record->product_id]) : null)
            ->recordActions([
                Action::make('open_shop')
                    ->hiddenLabel()
                    ->tooltip('Openen in WordPress')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (WooProduct $record) => $record->adminUrl(), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWooProducts::route('/'),
        ];
    }
}
