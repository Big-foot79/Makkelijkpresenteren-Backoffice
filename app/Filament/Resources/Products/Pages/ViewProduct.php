<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Models\Product;
use App\Models\SupplierItem;
use App\Pricing\PurchasePrice;
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
                        TextEntry::make('expected')
                            ->label('Verwachte inkoop')
                            ->state(fn (Product $record) => ($p = ProductResource::purchase($record)) && $p['expected'] !== null
                                ? '€ '.number_format($p['expected'], 2, ',', '.') : null)
                            ->helperText(fn (Product $record) => ProductResource::purchase($record)['label'] ?? null)
                            ->placeholder('—'),
                        TextEntry::make('purchase_options')
                            ->label('Opties')
                            ->state(fn (Product $record) => self::purchaseOptions($record))
                            ->html()
                            ->placeholder('Geen inkoopprijs bekend'),
                        TextEntry::make('margin')
                            ->label('Marge')
                            ->state(function (Product $record) {
                                $cost = ProductResource::purchase($record)['expected'] ?? null;
                                $price = (float) ($record->sale_price ?? $record->webshop_price);

                                return $cost !== null && $price > 0
                                    ? '€ '.number_format($price - $cost, 2, ',', '.').' ('.number_format(PurchasePrice::marginPercent($price, $cost), 1, ',', '.').'%)'
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

    private static function purchaseOptions(Product $record): ?HtmlString
    {
        $p = ProductResource::purchase($record);

        if (! $p || $p['expected'] === null) {
            return null;
        }

        $euro = fn (float $v) => '€ '.number_format($v, 2, ',', '.');
        $lines = [];

        if ($p['bid']) {
            $lines['bid'] = 'Bid: '.$euro($p['bid']['price']).' ('.e($p['bid']['price_list']).')';
        }

        if ($p['discount']) {
            $d = $p['discount'];
            $lines['discount'] = 'Merkkorting: '.$euro($d['price']).' (adviesprijs '.$euro($d['msrp']).' van '.e($d['msrp_from']).' − '.$d['percent'].'%)';
        }

        if ($p['list']) {
            $lines['list'] = 'Lijst: '.$euro($p['list']['price']).' bij '.e($p['list']['supplier']);
        }

        return new HtmlString(collect($lines)->map(fn ($line, $key) => $key === $p['source'] ? "<b>{$line}</b>" : $line)->implode('<br>'));
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

        // Articles with this EAN that a supplier no longer lists do not count; say so instead of hiding them.
        $delisted = SupplierItem::query()->with('supplier:id,name')->where('ean', $record->ean)->where('is_listed', false)->get()
            ->map(fn (SupplierItem $i) => e($i->supplier->name).' ('.e($i->sku).'): <span class="text-gray-500">niet meer in catalogus</span>');
        $lines = $lines->concat($delisted);

        return $lines->isEmpty()
            ? new HtmlString('Geen leverancier voert deze EAN. Staat het artikel er wel, maar zonder of met een andere EAN? Dan koppelt het via het fabrikantnummer zodra dat gelijk is aan de SKU in de shop.')
            : new HtmlString($lines->implode('<br>'));
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
