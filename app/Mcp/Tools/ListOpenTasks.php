<?php

namespace App\Mcp\Tools;

use App\Enums\TaskKind;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List open tasks (status open or in progress), newest first. Tasks are things that need a decision: sync conflicts, failed job runs, unmatched items, price deviations and proposals waiting for approval.')]
class ListOpenTasks extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(TaskKind::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tasks = Task::active()
            ->with(['assignedTo:id,name', 'jobRun:id,type,label'])
            ->when($validated['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->latest()
            ->limit($validated['limit'] ?? 25)
            ->get();

        return Response::structured([
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'kind' => $task->kind->value,
                'status' => $task->status->value,
                'title' => $task->title,
                'description' => $task->description,
                'subject' => $task->subject_type ? "{$task->subject_type}#{$task->subject_id}" : null,
                'job_run' => $task->jobRun?->only(['id', 'type', 'label']),
                'assigned_to' => $task->assignedTo?->name,
                'created_at' => $task->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->enum(array_column(TaskKind::cases(), 'value'))
                ->description('Only tasks of this kind.'),
            'limit' => $schema->integer()
                ->min(1)
                ->max(100)
                ->description('Maximum number of tasks, default 25.'),
        ];
    }
}
