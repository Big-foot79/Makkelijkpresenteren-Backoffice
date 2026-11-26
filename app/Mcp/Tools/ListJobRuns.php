<?php

namespace App\Mcp\Tools;

use App\Enums\JobRunStatus;
use App\Models\JobRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List recent job runs (supplier imports, enrichment, shop sync, supplier messages), newest first, with status and one-line summary. Set include_log to get the full log of each run, useful to explain why a run failed.')]
class ListJobRuns extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(JobRunStatus::class)],
            'type' => ['nullable', 'string', 'max:100'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'include_log' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $includeLog = (bool) ($validated['include_log'] ?? false);

        $runs = JobRun::query()
            ->where('created_at', '>=', now()->subHours($validated['hours'] ?? 24))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', 'like', $type.'%'))
            ->latest()
            ->limit($validated['limit'] ?? 25)
            ->get();

        return Response::structured([
            'count' => $runs->count(),
            'runs' => $runs->map(fn (JobRun $run) => array_filter([
                'id' => $run->id,
                'type' => $run->type,
                'label' => $run->label,
                'status' => $run->status->value,
                'summary' => $run->summary,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'log' => $includeLog ? $run->log : null,
            ], fn ($value) => $value !== null))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(array_column(JobRunStatus::cases(), 'value'))
                ->description('Only runs with this status, e.g. failed.'),
            'type' => $schema->string()
                ->description('Only runs whose type starts with this, e.g. "import" or "import.tdsynnex".'),
            'hours' => $schema->integer()
                ->min(1)
                ->max(720)
                ->description('How far back to look in hours, default 24.'),
            'include_log' => $schema->boolean()
                ->description('Include the full log of each run.'),
            'limit' => $schema->integer()
                ->min(1)
                ->max(100)
                ->description('Maximum number of runs, default 25.'),
        ];
    }
}
