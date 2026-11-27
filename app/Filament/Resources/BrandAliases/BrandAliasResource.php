<?php

namespace App\Filament\Resources\BrandAliases;

use App\Filament\Resources\BrandAliases\Pages\ManageBrandAliases;
use App\Models\BrandAlias;
use App\Models\FavoriteBrand;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Fixed links between brand spellings, for what the automatic clean-up does not catch. */
class BrandAliasResource extends Resource
{
    protected static ?string $model = BrandAlias::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Leveranciers';

    protected static ?int $navigationSort = 45;

    protected static ?string $modelLabel = 'merknaam';

    protected static ?string $pluralModelLabel = 'merknamen';

    protected static ?string $navigationLabel = 'Merknamen';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('alias')
                ->label('Zoals een leverancier het schrijft')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => DB::table('supplier_items')->whereNotNull('brand_raw')
                    ->where('brand_raw', 'like', $search.'%')->distinct()->orderBy('brand_raw')->limit(50)->pluck('brand_raw', 'brand_raw')->all())
                ->getOptionLabelUsing(fn ($value) => $value)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, $state) => $rule->where('alias', mb_strtolower((string) $state)))
                ->helperText('Bijv. "Hewlett Packard". Hoofdletters maken niet uit.'),
            Select::make('brand')
                ->label('Hoort bij merk')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => FavoriteBrand::knownBrands($search) + [$search => $search])
                ->getOptionLabelUsing(fn ($value) => $value)
                ->options(fn () => FavoriteBrand::knownBrands())
                ->helperText('Bijv. "HP". Bestaande artikelen en producten worden direct aangepast.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('alias')
            ->paginated(false)
            ->description('Achtervoegsels als Electronics, Inc., B.V., GmbH, Europe en Benelux worden al automatisch weggelaten (LG Electronics = LG). Hier staan de koppelingen die dat niet vangt.')
            ->columns([
                TextColumn::make('alias')->label('Schrijfwijze leverancier')->searchable(),
                TextColumn::make('brand')->label('Merk')->searchable(),
                TextColumn::make('articles')
                    ->label('Artikelen')
                    ->state(fn (BrandAlias $record) => DB::table('supplier_items')->whereRaw('lower(brand_raw) = ?', [$record->alias])->count())
                    ->numeric(thousandsSeparator: '.'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBrandAliases::route('/')];
    }
}
