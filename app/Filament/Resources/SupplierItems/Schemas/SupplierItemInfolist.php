<?php

namespace App\Filament\Resources\SupplierItems\Schemas;

use App\Filament\Resources\SupplierItems\SupplierItemResource;
use App\Models\SupplierItem;
use App\Models\SupplierStock;
use App\Suppliers\StockAvailability;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class SupplierItemInfolist
{
    /** TD Synnex warehouse codes (DataPack Pro manual, StockFile). */
    private const LOCATIONS = [
        '1818' => 'lokaal magazijn (1 dag)',
        '1212' => 'lokaal magazijn BE (1 dag)',
        '1854' => 'centraal magazijn (2–7 dagen)',
        '1254' => 'centraal magazijn BE (2–7 dagen)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Artikel')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('description')->label('Omschrijving')->columnSpanFull(),
                        TextEntry::make('supplier.name')->label('Leverancier'),
                        TextEntry::make('sku')
                            ->label('Artikelnummer leverancier')
                            ->fontFamily('mono')
                            ->copyable()
                            ->suffixAction(fn (SupplierItem $record) => $record->supplierUrl()
                                ? Action::make('open_supplier')
                                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                    ->tooltip('Bekijk bij '.$record->supplier->name)
                                    ->url($record->supplierUrl(), shouldOpenInNewTab: true)
                                : null),
                        TextEntry::make('ean')->label('EAN')->fontFamily('mono')->copyable()->placeholder('—')
                            ->helperText(fn (SupplierItem $record) => $record->ean_source === 'mpn' ? 'Opgezocht via het fabrikantnummer; de leverancier levert geen (echte) EAN.' : null),
                        TextEntry::make('mpn')->label('Fabrikantnummer')->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('brand')->label('Merk')->placeholder('—'),
                        TextEntry::make('category')->label('Categorie')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => SupplierItem::STATUSES[$state] ?? $state)
                            ->placeholder('Leverbaar'),
                        TextEntry::make('follower_sku')
                            ->label('Opvolger')
                            ->placeholder('—')
                            ->url(fn (SupplierItem $record) => ($follower = self::follower($record))
                                ? SupplierItemResource::getUrl('view', ['record' => $follower])
                                : null)
                            ->formatStateUsing(fn (?string $state, SupplierItem $record) => ($follower = self::follower($record))
                                ? $state.' · '.$follower->description
                                : $state),
                        TextEntry::make('long_description')
                            ->label('Lange omschrijving')
                            ->columnSpanFull()
                            ->visible(fn (SupplierItem $record) => filled($record->long_description)
                                && trim($record->long_description) !== trim((string) $record->description)),
                    ]),
                Section::make('Prijs')
                    ->schema([
                        TextEntry::make('net_price')->label('Inkoopprijs (prijslijst)')->money('EUR', locale: 'nl')->placeholder('—'),
                        TextEntry::make('surcharge')->label('Toeslag')->money('EUR', locale: 'nl')->placeholder('—'),
                        TextEntry::make('msrp')->label('Adviesprijs')->money('EUR', locale: 'nl')->placeholder('—'),
                        TextEntry::make('price_updated_at')->label('Prijs bijgewerkt')->since()->placeholder('—'),
                        TextEntry::make('last_seen_at')->label('Laatst in catalogus')->since(),
                    ]),
                Section::make('Voorraad bij deze leverancier')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('stock_lines')
                            ->hiddenLabel()
                            ->state(fn (SupplierItem $record) => self::stockLines($record))
                            ->html(),
                    ]),
                Section::make('Beschikbaarheid alle leveranciers')
                    ->description('Volgens de regel voor de site: hoogste aantal bij één leverancier, lijsten tot '.config('backoffice.stock.max_age_days').' dagen oud.')
                    ->visible(fn (SupplierItem $record) => filled($record->ean))
                    ->schema([
                        TextEntry::make('availability')
                            ->hiddenLabel()
                            ->state(fn (SupplierItem $record) => self::availability($record))
                            ->html(),
                    ]),
            ]);
    }

    private static function follower(SupplierItem $record): ?SupplierItem
    {
        if (blank($record->follower_sku)) {
            return null;
        }

        static $cache = [];

        return $cache[$record->id] ??= SupplierItem::where('supplier_id', $record->supplier_id)
            ->where('sku', $record->follower_sku)
            ->first();
    }

    private static function stockLines(SupplierItem $record): HtmlString
    {
        $runId = $record->supplier?->current_stock_run_id;
        $lines = $runId
            ? SupplierStock::where('job_run_id', $runId)->where('sku', $record->sku)->orderBy('location')->get()
            : collect();

        if ($lines->isEmpty()) {
            return new HtmlString('<span class="text-gray-500">Niet in de huidige voorraadlijst.</span>');
        }

        $rows = $lines->map(fn (SupplierStock $s) => sprintf(
            '<li><strong>%d</strong> stuks%s%s%s</li>',
            $s->quantity,
            $s->location ? ' · '.e(self::LOCATIONS[$s->location] ?? 'magazijn '.$s->location) : '',
            $s->next_date ? ' · verwacht '.$s->next_date->format('d-m-Y') : '',
            $s->note ? ' · '.e(['allocation' => 'op allocatie', 'overdue' => 'levertijd overschreden'][$s->note] ?? $s->note) : '',
        ))->implode('');

        $asOf = $record->supplier->stock_as_of?->format('d-m-Y H:i');

        return new HtmlString("<ul>{$rows}</ul><p class=\"text-sm text-gray-500 mt-2\">Stand {$asOf}</p>");
    }

    private static function availability(SupplierItem $record): HtmlString
    {
        $result = app(StockAvailability::class)->forEans([$record->ean])[$record->ean];

        $suppliers = collect($result['suppliers'])->map(fn (array $s) => sprintf(
            '<li>%s: %d%s</li>',
            e($s['supplier']),
            $s['quantity'],
            match ($s['reason']) {
                'no_list' => ' <span class="text-gray-500">(nog geen voorraadlijst ingelezen)</span>',
                'stale' => ' <span class="text-gray-500">(lijst te oud, telt niet)</span>',
                'not_in_list' => ' <span class="text-gray-500">(niet in de voorraadlijst)</span>',
                default => '',
            },
        ))->implode('');

        $next = $result['next_date'] ? ' · verwacht '.date('d-m-Y', strtotime($result['next_date'])) : '';

        return new HtmlString("<p><strong>{$result['label']}</strong>{$next}</p><ul class=\"mt-2\">{$suppliers}</ul>");
    }
}
