<?php

namespace App\Filament\Resources\BrandTerms;

use App\Filament\Resources\BrandTerms\Pages\ManageBrandTerms;
use App\Models\BrandTerm;
use App\Models\FavoriteBrand;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Per brand: the usual discount on the advice price. Used for the expected purchase price and margin. */
class BrandTermResource extends Resource
{
    protected static ?string $model = BrandTerm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Leveranciers';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'merkafspraak';

    protected static ?string $pluralModelLabel = 'merkafspraken';

    protected static ?string $navigationLabel = 'Merkafspraken';

    protected static ?string $recordTitleAttribute = 'brand';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand')
                ->label('Merk')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => FavoriteBrand::knownBrands($search))
                ->getOptionLabelUsing(fn ($value) => $value)
                ->options(fn () => FavoriteBrand::knownBrands())
                ->unique(ignoreRecord: true),
            TextInput::make('discount_percent')
                ->label('Gebruikelijke korting op de adviesprijs')
                ->numeric()
                ->suffix('%')
                ->minValue(0)
                ->maxValue(90)
                ->required()
                ->helperText('Verwachte inkoop = adviesprijs × (1 − korting). De adviesprijs komt uit een ingelezen prijslijst of van TD Synnex.'),
            Select::make('supplier_id')
                ->label('Inkoop via')
                ->relationship('supplier', 'name')
                ->placeholder('Elke distributeur')
                ->helperText('Bijv. Sony via TD Synnex.'),
            Select::make('bid_source')
                ->label('Bidprijzen komen')
                ->options(BrandTerm::BID_SOURCES)
                ->placeholder('Geen bids'),
            Textarea::make('note')->label('Notitie')->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('brand')
            ->paginated(false)
            ->columns([
                TextColumn::make('brand')->label('Merk')->searchable()->sortable(),
                TextColumn::make('discount_percent')
                    ->label('Korting op adviesprijs')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2, ',', ''), '0'), ',').'%')
                    ->sortable(),
                TextColumn::make('supplier.name')->label('Inkoop via')->placeholder('elke distributeur'),
                TextColumn::make('bid_source')
                    ->label('Bids')
                    ->formatStateUsing(fn (?string $state) => BrandTerm::BID_SOURCES[$state] ?? $state)
                    ->placeholder('—'),
                TextColumn::make('note')->label('Notitie')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrandTerms::route('/'),
        ];
    }
}
