<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Enums\UserRole;
use App\Models\JobRun;
use App\Models\User;
use Closure;

/**
 * Who is acting right now, so the change history knows whom to credit.
 *
 * - In the panel: the logged-in user (resolved automatically).
 * - Via MCP: the agent user behind the token (resolved automatically, via = mcp).
 * - In a job: wrap the work in Actor::asJob($run, fn () => ...).
 */
class Actor
{
    private static ?JobRun $jobRun = null;

    private static ?string $via = null;

    /** The user who started the queued job that is running now (set from the job payload). */
    private static ?int $startedBy = null;

    public static function setStartedBy(?int $userId): void
    {
        self::$startedBy = $userId;
    }

    /** Who to credit for starting a run: the logged-in user, or whoever queued the current job. */
    public static function startedBy(): ?int
    {
        return self::user()?->getKey() ?? self::$startedBy;
    }

    public static function asJob(JobRun $run, Closure $callback): mixed
    {
        $previous = [self::$jobRun, self::$via];
        self::$jobRun = $run;
        self::$via = 'job';

        try {
            return $callback();
        } finally {
            [self::$jobRun, self::$via] = $previous;
        }
    }

    public static function via(string $via, Closure $callback): mixed
    {
        $previous = self::$via;
        self::$via = $via;

        try {
            return $callback();
        } finally {
            self::$via = $previous;
        }
    }

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function jobRun(): ?JobRun
    {
        return self::$jobRun;
    }

    public static function type(): ActorType
    {
        if (self::$jobRun) {
            return ActorType::Job;
        }

        $user = self::user();

        if ($user === null) {
            return ActorType::System;
        }

        return $user->role === UserRole::Agent ? ActorType::Agent : ActorType::User;
    }

    public static function channel(): ?string
    {
        if (self::$via) {
            return self::$via;
        }

        if (app()->runningInConsole()) {
            return 'console';
        }

        return request()->is('mcp*') ? 'mcp' : 'panel';
    }
}
