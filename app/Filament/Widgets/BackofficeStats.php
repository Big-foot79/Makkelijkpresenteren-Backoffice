<?php

namespace App\Filament\Widgets;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Filament\Resources\JobRuns\JobRunResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\JobRun;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** What needs attention right now: open tasks, approvals and failed runs. */
class BackofficeStats extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $openTasks = Task::active()->count();
        $approvals = Task::active()->where('kind', TaskKind::Approval)->count();
        $runs = JobRun::where('created_at', '>=', now()->subDay());
        $failed = (clone $runs)->where('status', JobRunStatus::Failed)->count();

        return [
            Stat::make('Open taken', $openTasks)
                ->description($approvals > 0 ? "{$approvals} ter goedkeuring" : 'Niets ter goedkeuring')
                ->color($openTasks > 0 ? 'warning' : 'success')
                ->url(TaskResource::getUrl()),
            Stat::make('Runs laatste 24 uur', (clone $runs)->count())
                ->description((clone $runs)->where('status', JobRunStatus::Running)->count().' bezig')
                ->url(JobRunResource::getUrl()),
            Stat::make('Mislukte runs', $failed)
                ->description('Laatste 24 uur')
                ->color($failed > 0 ? 'danger' : 'success')
                ->url(JobRunResource::getUrl()),
        ];
    }
}
