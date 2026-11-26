<?php

namespace App\Mcp\Tools;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Models\JobRun;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Overview of the backoffice right now: open tasks per kind and job runs of the last 24 hours (total, failed, still running). Use this first to see what needs attention.')]
class BackofficeStatus extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $since = now()->subDay();

        $tasksByKind = Task::active()
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $openTasks = collect(TaskKind::cases())
            ->mapWithKeys(fn (TaskKind $kind) => [$kind->value => (int) ($tasksByKind[$kind->value] ?? 0)])
            ->filter()
            ->all();

        $runs = JobRun::where('created_at', '>=', $since);

        return Response::structured([
            'generated_at' => now()->toIso8601String(),
            'open_tasks_total' => array_sum($openTasks),
            'open_tasks_by_kind' => (object) $openTasks,
            'job_runs_last_24h' => [
                'total' => (clone $runs)->count(),
                'failed' => (clone $runs)->where('status', JobRunStatus::Failed)->count(),
                'running' => (clone $runs)->where('status', JobRunStatus::Running)->count(),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
