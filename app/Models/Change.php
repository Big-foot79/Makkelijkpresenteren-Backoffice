<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One entry in the change history. Written by the RecordsChanges trait, never edited. */
class Change extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobRun(): BelongsTo
    {
        return $this->belongsTo(JobRun::class);
    }

    public function actorLabel(): string
    {
        return match ($this->actor_type) {
            ActorType::User, ActorType::Agent => $this->user?->name ?? $this->actor_type->getLabel(),
            ActorType::Job => $this->jobRun?->label ?? 'Achtergrondtaak',
            ActorType::System => 'Systeem',
        };
    }
}
