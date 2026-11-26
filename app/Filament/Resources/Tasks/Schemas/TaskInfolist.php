<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Filament\Resources\JobRuns\JobRunResource;
use App\Models\Task;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Taak')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')->label('Taak')->columnSpanFull(),
                        TextEntry::make('kind')->label('Soort')->badge(),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('description')->label('Omschrijving')->placeholder('—')->columnSpanFull(),
                        KeyValueEntry::make('data')
                            ->label('Gegevens')
                            ->visible(fn (Task $record) => filled($record->data))
                            ->columnSpanFull(),
                    ]),
                Section::make('Herkomst')
                    ->schema([
                        TextEntry::make('created_at')->label('Aangemaakt')->dateTime('D j M Y, H:i'),
                        TextEntry::make('createdBy.name')->label('Door')->placeholder('Systeem'),
                        TextEntry::make('jobRun.label')
                            ->label('Run')
                            ->placeholder('—')
                            ->url(fn (Task $record) => $record->job_run_id
                                ? JobRunResource::getUrl('view', ['record' => $record->job_run_id])
                                : null),
                        TextEntry::make('subject_type')
                            ->label('Over')
                            ->state(fn (Task $record) => $record->subject_type ? "{$record->subject_type} #{$record->subject_id}" : null)
                            ->placeholder('—'),
                        TextEntry::make('assignedTo.name')->label('Toegewezen aan')->placeholder('—'),
                    ]),
                Section::make('Afhandeling')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Task $record) => $record->resolved_at !== null)
                    ->schema([
                        TextEntry::make('resolved_at')->label('Afgehandeld')->dateTime('D j M Y, H:i'),
                        TextEntry::make('resolvedBy.name')->label('Door')->placeholder('Systeem'),
                        TextEntry::make('resolution')->label('Toelichting')->placeholder('—'),
                    ]),
            ]);
    }
}
