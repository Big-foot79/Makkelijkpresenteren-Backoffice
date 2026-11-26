<?php

namespace App\Filament\Resources\SupplierItems;

use App\Filament\Resources\SupplierItems\Pages\ListSupplierItems;
use App\Filament\Resources\SupplierItems\Pages\ViewSupplierItem;
use App\Filament\Resources\SupplierItems\Schemas\SupplierItemInfolist;
use App\Filament\Resources\SupplierItems\Tables\SupplierItemsTable;
use App\Models\SupplierItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Read-only search through the full supplier catalogues. Written only by the imports. */
class SupplierItemResource extends Resource
{
    protected static ?string $model = SupplierItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Leveranciers';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'leveranciersartikel';

    protected static ?string $pluralModelLabel = 'leveranciersartikelen';

    protected static ?string $navigationLabel = 'Artikelen';

    protected static ?string $recordTitleAttribute = 'description';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierItems::route('/'),
            'view' => ViewSupplierItem::route('/{record}'),
        ];
    }
}
