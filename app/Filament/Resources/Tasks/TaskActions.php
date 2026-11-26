<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/** The two ways to close a task, shared by the table and the view page. */
class TaskActions
{
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Afronden')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Task $record) => in_array($record->status, TaskStatus::active(), true))
            ->schema([
                Textarea::make('resolution')->label('Toelichting (optioneel)')->rows(2),
            ])
            ->action(fn (Task $record, array $data) => $record->resolve(TaskStatus::Done, $data['resolution'] ?? null))
            ->successNotificationTitle('Taak afgerond');
    }

    public static function dismiss(): Action
    {
        return Action::make('dismiss')
            ->label('Negeren')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Task $record) => in_array($record->status, TaskStatus::active(), true))
            ->schema([
                Textarea::make('resolution')->label('Waarom negeren?')->rows(2)->required(),
            ])
            ->action(fn (Task $record, array $data) => $record->resolve(TaskStatus::Dismissed, $data['resolution']))
            ->successNotificationTitle('Taak genegeerd');
    }
}
