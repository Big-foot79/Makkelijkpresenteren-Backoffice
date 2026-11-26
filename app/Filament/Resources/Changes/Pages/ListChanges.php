<?php

namespace App\Filament\Resources\Changes\Pages;

use App\Filament\Resources\Changes\ChangeResource;
use Filament\Resources\Pages\ListRecords;

class ListChanges extends ListRecords
{
    protected static string $resource = ChangeResource::class;
}
