<?php

namespace App\Filament\Resources\JobRuns\Pages;

use App\Filament\Resources\JobRuns\JobRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewJobRun extends ViewRecord
{
    protected static string $resource = JobRunResource::class;

    // Phase 2 adds a "Opnieuw proberen" action here, using the stored `input` of the run.
}
