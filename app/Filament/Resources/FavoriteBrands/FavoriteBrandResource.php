<?php

namespace App\Filament\Resources\FavoriteBrands;

use App\Filament\Resources\FavoriteBrands\Pages\ManageFavoriteBrands;
use App\Models\FavoriteBrand;
use App\Models\SupplierItem;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** The short list of brands that get a quick tab above the supplier articles. */
class FavoriteBrandResource extends Resource
{
    protected static ?string $model = FavoriteBrand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Leveranciers';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'favoriet merk';

    protected static ?string $pluralModelLabel = 'favoriete merken';

    protected static ?string $navigationLabel = 'Favoriete merken';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('name')
                ->label('Merk')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => FavoriteBrand::knownBrands($search))
                ->getOptionLabelUsing(fn ($value) => $value)
                ->options(fn () => FavoriteBrand::knownBrands())
                ->unique(ignoreRecord: true)
                ->helperText('Kies het merk zoals een leverancier het schrijft; hoofdletters maken niet uit (IIYAMA = Iiyama).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Merk'),
                TextColumn::make('items')
                    ->label('Artikelen bij leveranciers')
                    ->state(fn (FavoriteBrand $record) => FavoriteBrand::applyTo(SupplierItem::query()->where('is_listed', true), $record->name)->count())
                    ->numeric(thousandsSeparator: '.'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFavoriteBrands::route('/'),
        ];
    }
}
