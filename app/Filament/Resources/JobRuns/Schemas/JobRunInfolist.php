<?php

namespace App\Filament\Resources\JobRuns\Schemas;

use App\Models\JobRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Run')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('label')->label('Run'),
                        TextEntry::make('type')->label('Type')->fontFamily('mono'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('summary')->label('Resultaat')->placeholder('—'),
                    ]),
                Section::make('Tijd')
                    ->schema([
                        TextEntry::make('started_at')->label('Gestart')->dateTime('D j M Y, H:i:s')->placeholder('—'),
                        TextEntry::make('finished_at')->label('Klaar')->dateTime('D j M Y, H:i:s')->placeholder('—'),
                        TextEntry::make('duration')->label('Duur')->state(fn (JobRun $record) => $record->duration())->placeholder('—'),
                        TextEntry::make('triggeredBy.name')->label('Gestart door')->placeholder('Planning'),
                    ]),
                Section::make('Log')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('log')
                            ->hiddenLabel()
                            ->placeholder('Geen logregels.')
                            ->fontFamily('mono')
                            ->formatStateUsing(fn (?string $state) => nl2br(e($state)))
                            ->html(),
                    ]),
            ]);
    }
}
