<?php

namespace Tests\Fixtures;

use App\Models\JobRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A queued job that records a run and optionally queues a follow-up, like an import does. */
class TrackedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $chain = false) {}

    public function handle(): void
    {
        JobRun::track('test', 'Test', fn () => 'ok');

        if ($this->chain) {
            dispatch(new self);
        }
    }
}
