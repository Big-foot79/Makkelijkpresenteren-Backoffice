<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskActions;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TaskActions::restoreBackoffice(),
            TaskActions::acceptShop(),
            TaskActions::complete(),
            TaskActions::dismiss(),
        ];
    }
}
