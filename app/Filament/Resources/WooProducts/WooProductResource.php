<?php

namespace App\Filament\Resources\WooProducts;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Filament\Resources\WooProducts\Pages\ListWooProducts;
use App\Models\JobRun;
use App\Models\WooProduct;
use App\Shop\ShopCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
                        'ok', 'ean_suggested' => 'info',
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
                    ->state(fn (WooProduct $record) => $record->ean ?? $record->suggested_ean)
                    ->description(fn (WooProduct $record) => match (true) {
                        $record->ean === null && $record->suggested_ean !== null => 'voorstel via '.$record->suggested_from,
                        $record->ean_source === 'gtin' => 'GTIN-veld',
                        $record->ean_source === 'attribute' => 'attribuut',
                        $record->ean_source === 'meta' => 'metaveld',
                        $record->ean_source === 'suggested' => 'via SKU bij leverancier',
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
            ->toolbarActions([
                BulkAction::make('accept_eans')
                    ->label('EAN\'s overnemen en koppelen')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn () => auth()->user()?->isAdmin())
                    ->requiresConfirmation()
                    ->modalDescription('Alleen geselecteerde producten met status "EAN gevonden" worden gekoppeld; de rest wordt overgeslagen.')
                    ->action(fn (Collection $records) => self::accept($records->pluck('woo_id')->all()))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordUrl(fn (WooProduct $record) => $record->product_id ? ProductResource::getUrl('view', ['record' => $record->product_id]) : null)
            ->recordActions([
                Action::make('accept_ean')
                    ->label('EAN overnemen')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (WooProduct $record) => $record->match_status === 'ean_suggested' && auth()->user()?->isAdmin())
                    ->requiresConfirmation()
                    ->modalDescription(fn (WooProduct $record) => "EAN {$record->suggested_ean} ({$record->suggested_from}) wordt de EAN van {$record->name} en het product wordt gekoppeld. Met de volgende sync komt de EAN ook in het GTIN-veld van de webshop.")
                    ->action(fn (WooProduct $record) => self::accept([$record->woo_id])),
                Action::make('open_shop')
                    ->hiddenLabel()
                    ->tooltip('Openen in WordPress')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (WooProduct $record) => $record->adminUrl(), shouldOpenInNewTab: true),
            ]);
    }

    /** @param list<int> $wooIds */
    public static function accept(array $wooIds): void
    {
        $summary = JobRun::track('shop.accept_ean', 'EAN-voorstellen overnemen', fn (JobRun $run) => ShopCatalog::acceptSuggestions($wooIds, $run));

        Notification::make()->title($summary)->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWooProducts::route('/'),
        ];
    }
}
