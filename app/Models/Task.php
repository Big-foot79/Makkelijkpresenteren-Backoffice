<?php

namespace App\Models;

use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Concerns\RecordsChanges;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Something that needs a decision: a sync conflict, a failed job, a proposal to approve. */
class Task extends Model
{
    use RecordsChanges;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'status' => TaskStatus::class,
            'data' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            $task->created_by_user_id ??= Actor::user()?->getKey();
        });
    }

    public function resolve(TaskStatus $status = TaskStatus::Done, ?string $resolution = null): void
    {
        $this->update([
            'status' => $status,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolved_by_user_id' => Actor::user()?->getKey(),
        ]);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::active());
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function jobRun(): BelongsTo
    {
        return $this->belongsTo(JobRun::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
