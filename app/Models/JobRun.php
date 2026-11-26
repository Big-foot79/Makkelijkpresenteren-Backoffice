<?php

namespace App\Models;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Support\Actor;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Throwable;

/**
 * A visible background run: queued, running, succeeded or failed, with a log.
 *
 * Usage inside a queued job:
 *
 *   JobRun::track('import.tdsynnex.stock', 'TD Synnex voorraad', function (JobRun $run) {
 *       $run->info('Bestand gedownload');
 *       ...
 *       return '1.204 regels verwerkt';   // becomes the summary
 *   });
 *
 * A failure is logged, marks the run as failed, opens a task and is re-thrown so the queue can retry.
 */
class JobRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => JobRunStatus::class,
            'input' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function track(string $type, string $label, Closure $work, ?Model $subject = null, array $input = []): mixed
    {
        $run = static::create([
            'type' => $type,
            'label' => $label,
            'status' => JobRunStatus::Running,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'triggered_by_user_id' => Actor::user()?->getKey(),
            'input' => $input ?: null,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $result = Actor::asJob($run, fn () => $work($run));

            $run->update([
                'status' => JobRunStatus::Succeeded,
                'summary' => is_string($result) ? $result : $run->summary,
                'finished_at' => now(),
            ]);

            return $result;
        } catch (Throwable $e) {
            $run->error($e->getMessage());
            $run->update([
                'status' => JobRunStatus::Failed,
                'summary' => str($e->getMessage())->limit(250)->toString(),
                'finished_at' => now(),
            ]);

            Actor::asJob($run, fn () => Task::create([
                'kind' => TaskKind::JobFailed,
                'status' => TaskStatus::Open,
                'title' => "Mislukt: {$label}",
                'description' => $e->getMessage(),
                'job_run_id' => $run->id,
                'subject_type' => $run->subject_type,
                'subject_id' => $run->subject_id,
            ]));

            throw $e;
        }
    }

    public function info(string $message): void
    {
        $this->appendLog('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->appendLog('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->appendLog('ERROR', $message);
    }

    protected function appendLog(string $level, string $message): void
    {
        $line = sprintf('[%s] %s %s', now()->format('H:i:s'), $level, $message);
        $this->log = ltrim(($this->log ?? '')."\n".$line);
        $this->saveQuietly();
    }

    public function duration(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        return $this->started_at->diffForHumans($this->finished_at ?? now(), syntax: true, short: true);
    }

    public function scopeFailed(Builder $query): void
    {
        $query->where('status', JobRunStatus::Failed);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
