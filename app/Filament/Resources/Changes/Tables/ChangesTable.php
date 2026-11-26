<?php

namespace App\Filament\Resources\Changes\Tables;

use App\Enums\ActorType;
use App\Models\Change;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChangesTable
{
    private const EVENTS = [
        'created' => 'Aangemaakt',
        'updated' => 'Gewijzigd',
        'deleted' => 'Verwijderd',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'jobRun:id,label']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Tijd')
                    ->dateTime('D j M, H:i:s')
                    ->sortable(),
                TextColumn::make('actor')
                    ->label('Door')
                    ->state(fn (Change $record) => $record->actorLabel())
                    ->description(fn (Change $record) => $record->actor_type->getLabel()),
                TextColumn::make('via')
                    ->label('Via')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('event')
                    ->label('Actie')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::EVENTS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('subject')
                    ->label('Object')
                    ->state(fn (Change $record) => class_basename($record->subject_type).' #'.$record->subject_id),
                TextColumn::make('new_values')
                    ->label('Wijziging')
                    ->state(fn (Change $record) => self::describe($record))
                    ->wrap()
                    ->limit(160),
            ])
            ->filters([
                SelectFilter::make('actor_type')
                    ->label('Door')
                    ->options(ActorType::class),
                SelectFilter::make('event')
                    ->label('Actie')
                    ->options(self::EVENTS),
            ]);
    }

    private static function describe(Change $record): string
    {
        $new = $record->new_values ?? [];
        $old = $record->old_values ?? [];

        if ($record->event !== 'updated') {
            return collect($record->event === 'deleted' ? $old : $new)
                ->except(['id'])
                ->map(fn ($v, $k) => $k.': '.self::value($v))
                ->implode(' · ');
        }

        return collect($new)
            ->map(fn ($v, $k) => $k.': '.self::value($old[$k] ?? null).' → '.self::value($v))
            ->implode(' · ');
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'ja' : 'nee',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => str((string) $value)->limit(60)->toString(),
        };
    }
}
