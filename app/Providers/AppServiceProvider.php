<?php

namespace App\Providers;

use App\Pricing\PurchasePrice;
use App\Support\Actor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Purchase prices are cached per request or job.
        $this->app->scoped(PurchasePrice::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Remember who queued a job (also through follow-up jobs), so its run shows who started it
        // instead of "Planning".
        Queue::createPayloadUsing(fn () => ['started_by' => Actor::startedBy()]);
        Event::listen(JobProcessing::class, fn (JobProcessing $e) => Actor::setStartedBy($e->job->payload()['started_by'] ?? null));
        Event::listen(JobProcessed::class, fn () => Actor::setStartedBy(null));
        Event::listen(JobFailed::class, fn () => Actor::setStartedBy(null));
    }
}
