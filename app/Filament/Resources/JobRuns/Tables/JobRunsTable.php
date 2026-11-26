<?php

namespace App\Filament\Resources\JobRuns\Tables;

use App\Enums\JobRunStatus;
use App\Models\JobRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JobRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Gestart')
                    ->dateTime('D j M, H:i:s')
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Run')
                    ->description(fn (JobRun $record) => $record->type)
                    ->searchable(['label', 'type']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('summary')
                    ->label('Resultaat')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(120),
                TextColumn::make('duration')
                    ->label('Duur')
                    ->state(fn (JobRun $record) => $record->duration())
                    ->placeholder('—'),
                TextColumn::make('triggeredBy.name')
                    ->label('Gestart door')
                    ->placeholder('Planning')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(JobRunStatus::class)
                    ->multiple(),
                Filter::make('last_24h')
                    ->label('Laatste 24 uur')
                    ->query(fn (Builder $query) => $query->where('created_at', '>=', now()->subDay())),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
