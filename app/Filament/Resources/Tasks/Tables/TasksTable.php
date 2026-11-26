<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('D j M, H:i')
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Soort')
                    ->badge(),
                TextColumn::make('title')
                    ->label('Taak')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('assignedTo.name')
                    ->label('Toegewezen aan')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('createdBy.name')
                    ->label('Door')
                    ->placeholder('Systeem')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(TaskStatus::class)
                    ->multiple()
                    ->default([TaskStatus::Open->value, TaskStatus::InProgress->value]),
                SelectFilter::make('kind')
                    ->label('Soort')
                    ->options(TaskKind::class)
                    ->multiple(),
                SelectFilter::make('assigned_to_user_id')
                    ->label('Toegewezen aan')
                    ->relationship('assignedTo', 'name', fn (Builder $query) => $query->where('role', '!=', 'agent')),
            ])
            ->recordActions([
                ViewAction::make(),
                TaskActions::complete(),
                TaskActions::dismiss(),
            ]);
    }
}
