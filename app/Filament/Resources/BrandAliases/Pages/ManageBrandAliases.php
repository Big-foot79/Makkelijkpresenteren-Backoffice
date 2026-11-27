<?php

namespace App\Filament\Resources\BrandAliases\Pages;

use App\Filament\Resources\BrandAliases\BrandAliasResource;
use App\Jobs\ApplyBrandNames;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageBrandAliases extends ManageRecords
{
    protected static string $resource = BrandAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply')
                ->label('Opnieuw toepassen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function () {
                    ApplyBrandNames::dispatch();
                    Notification::make()->title('Merknamen worden toegepast')->success()->send();
                }),
            CreateAction::make()->label('Koppeling toevoegen'),
        ];
    }
}
