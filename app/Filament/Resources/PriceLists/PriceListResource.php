<?php

namespace App\Filament\Resources\PriceLists;

use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Resources\PriceLists\RelationManagers\ItemsRelationManager;
use App\Models\FavoriteBrand;
use App\Models\PriceList;
use App\Pricing\PriceListImporter;
use App\Support\Spreadsheet;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/** Bid and advice price lists from brands (often by mail), uploaded as Excel or CSV. */
class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Leveranciers';

    protected static ?int $navigationSort = 35;

    protected static ?string $modelLabel = 'prijslijst';

    protected static ?string $pluralModelLabel = 'prijslijsten';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Prijslijst')
                    ->schema([
                        TextInput::make('name')->label('Naam')->required()->placeholder('iiyama bidprijzen Q4 2026'),
                        Select::make('brand')
                            ->label('Merk')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => FavoriteBrand::knownBrands($search))
                            ->getOptionLabelUsing(fn ($value) => $value)
                            ->options(fn () => FavoriteBrand::knownBrands())
                            ->helperText('Helpt bij het vinden van EAN\'s via het artikelnummer.'),
                        Select::make('supplier_id')
                            ->label('Inkoop via')
                            ->relationship('supplier', 'name')
                            ->placeholder('Rechtstreeks / niet van toepassing'),
                        DatePicker::make('valid_from')->label('Geldig vanaf')->native(false)->displayFormat('d-m-Y'),
                        DatePicker::make('valid_until')->label('Geldig tot en met')->native(false)->displayFormat('d-m-Y')->afterOrEqual('valid_from'),
                        Toggle::make('assume_available')
                            ->label('Ga uit van leverbaar')
                            ->helperText('Voor een distributeur zonder voorraadgegevens (zoals Midwich): wat op deze lijst staat met een prijs, toont de webshop als "Leverbaar" in plaats van nalevering of onbekend.'),
                    ]),
                Section::make('Bestand')
                    ->schema([
                        FileUpload::make('file_path')
                            ->label('Excel- of CSV-bestand')
                            ->disk('local')
                            ->directory('price-lists')
                            ->storeFileNamesIn('file_name')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.oasis.opendocument.spreadsheet',
                                'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                            ])
                            ->maxSize(51200)
                            ->required()
                            ->helperText('.xlsx, .ods of .csv. Een oud .xls-bestand eerst in Excel opslaan als .xlsx.'),
                    ]),
                Section::make('Kolommen')
                    ->description('Kies welke kolom wat is. Nodig: EAN of artikelnummer, en adviesprijs of inkoopprijs.')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visibleOn('edit')
                    ->schema([
                        Select::make('sheet')
                            ->label('Tabblad')
                            ->options(fn (?PriceList $record) => $record ? rescue(fn () => Spreadsheet::sheets(PriceListImporter::path($record)), [], false) : [])
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn (?PriceList $record, Get $get, Set $set) => self::reguess($record, $get, $set)),
                        TextInput::make('header_row')
                            ->label('Kopregel')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->live(debounce: 600)
                            ->afterStateUpdated(fn (?PriceList $record, Get $get, Set $set) => self::reguess($record, $get, $set))
                            ->helperText('Rijnummer met de kolomnamen. Bij wijzigen worden de kolommen opnieuw geraden.'),
                        ...collect(PriceList::FIELDS)->map(fn (string $label, string $field) => Select::make("columns.{$field}")
                            ->label($label)
                            ->placeholder('—')
                            ->options(fn (?PriceList $record, Get $get) => self::headerOptions($record, (int) $get('sheet'), (int) $get('header_row'))))
                            ->values()->all(),
                        TextEntry::make('preview')
                            ->label('Voorbeeld (eerste regels na de kopregel)')
                            ->columnSpanFull()
                            ->state(fn (?PriceList $record, Get $get) => self::preview($record, (int) $get('sheet'), (int) $get('header_row'), (array) $get('columns'))),
                    ]),
            ]);
    }

    private static function reguess(?PriceList $record, Get $get, Set $set): void
    {
        if (! $record || (int) $get('header_row') < 1) {
            return;
        }

        $headers = rescue(fn () => Spreadsheet::row(PriceListImporter::path($record), (int) $get('sheet'), (int) $get('header_row')), [], false);
        $set('columns', PriceListImporter::guess($headers));
    }

    /** @return array<int, string> column index => "C: Header" */
    private static function headerOptions(?PriceList $record, int $sheet, int $headerRow): array
    {
        if (! $record || $headerRow < 1) {
            return [];
        }

        $headers = rescue(fn () => Spreadsheet::row(PriceListImporter::path($record), $sheet, $headerRow), [], false);

        return collect($headers)
            ->map(fn (string $header, int $i) => self::letter($i).': '.($header !== '' ? $header : '(leeg)'))
            ->all();
    }

    private static function preview(?PriceList $record, int $sheet, int $headerRow, array $columns): HtmlString|string
    {
        $columns = array_filter($columns, fn ($c) => $c !== null && $c !== '');

        if (! $record || $columns === []) {
            return 'Kies eerst de kolommen.';
        }

        try {
            $rows = [];

            foreach (Spreadsheet::rows(PriceListImporter::path($record), $sheet) as $number => $cells) {
                if ($number <= $headerRow) {
                    continue;
                }

                $rows[] = collect($columns)->map(fn ($index) => e($cells[(int) $index] ?? ''))->all();

                if (count($rows) === 4) {
                    break;
                }
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        $head = collect($columns)->keys()->map(fn ($f) => '<th class="px-2 text-left">'.e(PriceList::FIELDS[$f] ?? $f).'</th>')->implode('');
        $body = collect($rows)->map(fn ($r) => '<tr>'.collect($r)->map(fn ($v) => '<td class="px-2 font-mono">'.$v.'</td>')->implode('').'</tr>')->implode('');

        return new HtmlString("<table class=\"text-sm\"><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>");
    }

    private static function letter(int $index): string
    {
        $letter = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Naam')->searchable()->description(fn (PriceList $record) => $record->file_name),
                TextColumn::make('brand')->label('Merk')->placeholder('—'),
                TextColumn::make('supplier.name')->label('Inkoop via')->placeholder('—'),
                TextColumn::make('valid_until')
                    ->label('Geldig')
                    ->state(fn (PriceList $record) => trim(($record->valid_from?->format('d-m-Y') ?? '').' t/m '.($record->valid_until?->format('d-m-Y') ?? 'onbepaald')))
                    ->color(fn (PriceList $record) => $record->valid_until?->isPast() ? 'danger' : null),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PriceList::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['imported' => 'success', 'failed' => 'danger'][$state] ?? 'warning'),
                TextColumn::make('rows_imported')
                    ->label('Regels')
                    ->numeric(thousandsSeparator: '.')
                    ->description(fn (PriceList $record) => $record->rows_without_ean ? $record->rows_without_ean.' zonder EAN' : null),
                TextColumn::make('imported_at')->label('Ingelezen')->since()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(PriceList::STATUSES),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceLists::route('/'),
            'create' => CreatePriceList::route('/create'),
            'edit' => EditPriceList::route('/{record}/edit'),
        ];
    }
}
