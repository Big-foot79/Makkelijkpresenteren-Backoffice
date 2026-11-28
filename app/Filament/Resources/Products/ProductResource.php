<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Pricing\PurchasePrice;
use App\Suppliers\StockAvailability;
use App\Support\Parse;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** The backoffice's own products: what the shop sells, with price, purchase price and availability. */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Webshop';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'producten';

    protected static ?string $recordTitleAttribute = 'name';

    public const AVAILABILITY_COLORS = [
        'ample' => 'success',
        'limited' => 'warning',
        'available' => 'success',
        'backorder' => 'danger',
        'unknown' => 'gray',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Product')
                    ->description('Naam, merk, SKU, teksten en afbeeldingen pas je voorlopig aan in WooCommerce. De backoffice gaat die beheren met de verrijking (fase 4).')
                    ->schema([
                        TextInput::make('name')->label('Naam')->disabled()->dehydrated(false),
                        TextInput::make('brand')->label('Merk')->disabled()->dehydrated(false),
                        TextInput::make('sku')->label('SKU')->disabled()->dehydrated(false),
                    ]),
                Section::make('Prijs in de webshop')
                    ->description('Exclusief btw, zoals de webshop prijzen toont. Na opslaan gaat de prijs naar de webshop.')
                    ->schema([
                        TextInput::make('webshop_price')->label('Prijs')->numeric()->prefix('€')->minValue(0),
                        TextInput::make('sale_price')
                            ->label('Actieprijs')
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->lt('webshop_price')
                            ->helperText('Leeg = geen actie.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->select('products.*')->addSelect([
                'lowest_purchase' => DB::table('supplier_items')
                    ->selectRaw('min(net_price)')
                    ->whereColumn('supplier_items.ean', 'products.ean')
                    ->where('is_listed', true),
            ]))
            ->searchPlaceholder('Naam, SKU, EAN of merk')
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->description(fn (Product $record) => collect([$record->brand, $record->sku])->filter()->implode(' · '))
                    ->limit(70)
                    ->tooltip(fn (Product $record) => $record->name)
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search) {
                        $ean = Parse::ean($search);
                        $query->where(fn (Builder $q) => $q->where('products.name', 'like', "%{$search}%")
                            ->orWhere('products.sku', $search)
                            ->orWhere('products.brand', 'like', "{$search}%")
                            ->when($ean, fn (Builder $q) => $q->orWhere('products.ean', $ean)));
                    }),
                TextColumn::make('ean')->label('EAN')->fontFamily('mono')->placeholder('—')->toggleable(),
                TextColumn::make('webshop_price')->label('Prijs')->money('EUR', locale: 'nl')->placeholder('—')->sortable(),
                TextColumn::make('lowest_purchase')
                    ->label('Inkoop lijst')
                    ->money('EUR', locale: 'nl')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expected_purchase')
                    ->label('Verwachte inkoop')
                    ->state(fn (Product $record) => self::purchase($record)['expected'] ?? null)
                    ->money('EUR', locale: 'nl')
                    ->placeholder('—')
                    ->description(fn (Product $record) => ['bid' => 'prijslijst', 'discount' => 'merkkorting', 'list' => 'distributeur'][self::purchase($record)['source'] ?? ''] ?? null)
                    ->tooltip(fn (Product $record) => self::purchase($record)['label'] ?? null),
                TextColumn::make('margin')
                    ->label('Marge')
                    ->state(fn (Product $record) => PurchasePrice::marginPercent(
                        $record->sale_price !== null ? (float) $record->sale_price : ($record->webshop_price !== null ? (float) $record->webshop_price : null),
                        self::purchase($record)['expected'] ?? null,
                    ))
                    ->formatStateUsing(fn (?float $state) => $state === null ? null : number_format($state, 1, ',', '.').'%')
                    ->placeholder('—')
                    ->color(fn (?float $state) => $state === null ? 'gray' : ($state < 5 ? 'danger' : ($state < 12 ? 'warning' : 'success'))),
                TextColumn::make('availability')
                    ->label('Beschikbaarheid')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => StockAvailability::STATUS_LABELS[$state] ?? '—')
                    ->color(fn (?string $state) => self::AVAILABILITY_COLORS[$state] ?? 'gray')
                    ->description(fn (Product $record) => $record->availability === 'backorder' && $record->availability_date
                        ? 'verwacht '.$record->availability_date->format('d-m-Y') : null),
                TextColumn::make('status')
                    ->label('Publicatie')
                    ->formatStateUsing(fn (?string $state) => Product::STATUSES[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('woo_synced_at')->label('Naar webshop')->since()->placeholder('nog niet')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('availability')->label('Beschikbaarheid')->options(StockAvailability::STATUS_LABELS),
                SelectFilter::make('status')->label('Publicatie')->options(Product::STATUSES),
                SelectFilter::make('brand')
                    ->label('Merk')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => Product::query()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand', 'brand')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    /** Expected purchase price for a product (cached per request by the scoped service). */
    public static function purchase(Product $record): ?array
    {
        return app(PurchasePrice::class)->forEan($record->ean);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
