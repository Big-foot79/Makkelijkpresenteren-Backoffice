<?php

namespace App\Filament\Resources\JobRuns;

use App\Filament\Resources\JobRuns\Pages\ListJobRuns;
use App\Filament\Resources\JobRuns\Pages\ViewJobRun;
use App\Filament\Resources\JobRuns\Schemas\JobRunInfolist;
use App\Filament\Resources\JobRuns\Tables\JobRunsTable;
use App\Models\JobRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Read-only overview of every background run. Runs are written by JobRun::track(). */
class JobRunResource extends Resource
{
    protected static ?string $model = JobRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Werk';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'run';

    protected static ?string $pluralModelLabel = 'runs';

    protected static ?string $navigationLabel = 'Runs';

    protected static ?string $recordTitleAttribute = 'label';

    public static function infolist(Schema $schema): Schema
    {
        return JobRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobRuns::route('/'),
            'view' => ViewJobRun::route('/{record}'),
        ];
    }
}
