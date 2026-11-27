<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Models\Product;
use App\Shop\ShopSync;
use App\Suppliers\StockAvailability;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return collect([$this->record->brand, $this->record->sku ?? $this->record->ean])->filter()->implode(' ') ?: $this->record->name;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Product')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')->label('Naam')->columnSpanFull(),
                        TextEntry::make('brand')->label('Merk')->placeholder('—'),
                        TextEntry::make('sku')->label('SKU')->fontFamily('mono')->copyable()->placeholder('—'),
                        TextEntry::make('ean')
                            ->label('EAN')
                            ->fontFamily('mono')
                            ->copyable()
                            ->placeholder('—')
                            ->url(fn (Product $record) => $record->ean ? SupplierItemResource::getUrl('index', ['tableSearch' => $record->ean]) : null)
                            ->helperText('Klik voor alle leveranciersartikelen met deze EAN.'),
                        TextEntry::make('status')->label('Publicatie')->formatStateUsing(fn (?string $state) => Product::STATUSES[$state] ?? $state),
                    ]),
                Section::make('Prijs')
                    ->schema([
                        TextEntry::make('webshop_price')->label('Webshopprijs (excl. btw)')->money('EUR', locale: 'nl')->placeholder('—'),
                        TextEntry::make('sale_price')->label('Actieprijs')->money('EUR', locale: 'nl')->placeholder('geen'),
                        TextEntry::make('lowest')
                            ->label('Laagste inkoop')
                            ->state(function (Product $record) {
                                $item = $record->lowestPurchase();

                                return $item ? '€ '.number_format((float) $item->net_price, 2, ',', '.').' bij '.$item->supplier->name : null;
                            })
                            ->placeholder('—'),
                        TextEntry::make('margin')
                            ->label('Marge')
                            ->state(function (Product $record) {
                                $cost = $record->lowestPurchase()?->net_price;
                                $price = $record->sale_price ?? $record->webshop_price;

                                return $cost && $price > 0
                                    ? '€ '.number_format($price - $cost, 2, ',', '.').' ('.number_format(($price - $cost) / $price * 100, 1, ',', '.').'%)'
                                    : null;
                            })
                            ->placeholder('—'),
                    ]),
                Section::make('Beschikbaarheid')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('availability')
                            ->label('In de webshop')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => StockAvailability::STATUS_LABELS[$state] ?? 'Nog niet berekend')
                            ->color(fn (?string $state) => ProductResource::AVAILABILITY_COLORS[$state] ?? 'gray'),
                        TextEntry::make('suppliers')
                            ->label('Per leverancier')
                            ->state(fn (Product $record) => self::supplierLines($record))
                            ->html(),
                    ]),
                Section::make('Webshop')
                    ->schema([
                        TextEntry::make('woo_id')
                            ->label('WooCommerce-id')
                            ->placeholder('niet gekoppeld')
                            ->url(fn (Product $record) => $record->wooProduct?->adminUrl(), shouldOpenInNewTab: true),
                        TextEntry::make('shop_now')
                            ->label('Nu in de webshop')
                            ->state(fn (Product $record) => $record->wooProduct
                                ? collect(app(ShopSync::class)->mirrorState($record->wooProduct))
                                    ->only(['regular_price', 'sale_price', 'stock_status', '_mp_stock_label'])
                                    ->map(fn ($v, $k) => (ShopSync::FIELD_LABELS[$k] ?? $k).': '.($v === '' || $v === null ? 'leeg' : $v))
                                    ->implode("\n")
                                : null)
                            ->placeholder('—')
                            ->extraAttributes(['class' => 'whitespace-pre-line']),
                        TextEntry::make('woo_synced_at')->label('Laatst bijgewerkt')->since()->placeholder('nog niet'),
                    ]),
            ]);
    }

    private static function supplierLines(Product $record): ?HtmlString
    {
        if (! $record->ean) {
            return null;
        }

        $a = app(StockAvailability::class)->forEans([$record->ean])->get($record->ean);
        $reasons = ['no_list' => 'nog geen voorraadlijst', 'stale' => 'voorraadlijst te oud', 'not_in_list' => 'niet in voorraadlijst'];

        $lines = collect($a['suppliers'] ?? [])->map(fn (array $s) => e($s['supplier']).' ('.e($s['sku']).'): '
            .($s['counts'] ? '<b>'.$s['quantity'].'</b>'.($s['next_date'] ? ', verwacht '.e($s['next_date']) : '') : '<span class="text-gray-500">'.($reasons[$s['reason']] ?? '—').'</span>'));

        return $lines->isEmpty() ? new HtmlString('Geen leverancier voert deze EAN') : new HtmlString($lines->implode('<br>'));
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Prijs aanpassen'),
            Action::make('open_shop')
                ->label('In de webshop')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (Product $record) => filled($record->wooProduct?->permalink))
                ->url(fn (Product $record) => $record->wooProduct?->permalink, shouldOpenInNewTab: true),
        ];
    }
}
