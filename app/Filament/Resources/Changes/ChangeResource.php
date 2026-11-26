<?php

namespace App\Filament\Resources\Changes;

use App\Filament\Resources\Changes\Pages\ListChanges;
use App\Filament\Resources\Changes\Tables\ChangesTable;
use App\Models\Change;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Read-only change history: who (person, AI agent, job, system) changed what, and via which channel. */
class ChangeResource extends Resource
{
    protected static ?string $model = Change::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Systeem';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'wijziging';

    protected static ?string $pluralModelLabel = 'wijzigingshistorie';

    protected static ?string $navigationLabel = 'Wijzigingshistorie';

    public static function table(Table $table): Table
    {
        return ChangesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChanges::route('/'),
        ];
    }
}
