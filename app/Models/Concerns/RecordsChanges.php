<?php

namespace App\Models\Concerns;

use App\Models\Change;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add to any model whose changes must be traceable (products, prices, bids, ...).
 * Every create, update and delete becomes a row in `changes`, credited to the current Actor.
 */
trait RecordsChanges
{
    /** Attributes that are never stored in the change history. */
    protected array $changeHistoryHidden = ['password', 'private_key', 'passphrase', 'remember_token', 'updated_at', 'created_at'];

    public static function bootRecordsChanges(): void
    {
        static::created(fn (Model $model) => $model->recordChange('created', [], $model->getAttributes()));

        static::updated(function (Model $model) {
            $new = $model->getChanges();
            $old = array_intersect_key($model->getOriginal(), $new);
            $model->recordChange('updated', $old, $new);
        });

        static::deleted(fn (Model $model) => $model->recordChange('deleted', $model->getOriginal(), []));
    }

    public function changeHistory(): MorphMany
    {
        return $this->morphMany(Change::class, 'subject')->latest('created_at');
    }

    protected function recordChange(string $event, array $old, array $new): void
    {
        $hidden = array_flip($this->changeHistoryHidden);
        $old = array_diff_key($old, $hidden);
        $new = array_diff_key($new, $hidden);

        if ($event === 'updated' && $new === []) {
            return;
        }

        Change::create([
            'subject_type' => $this->getMorphClass(),
            'subject_id' => $this->getKey(),
            'event' => $event,
            'actor_type' => Actor::type(),
            'user_id' => Actor::user()?->getKey(),
            'job_run_id' => Actor::jobRun()?->getKey(),
            'via' => Actor::channel(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }
}
