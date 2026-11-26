<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Who made a change: a person, an AI agent, a background job or the system itself. */
enum ActorType: string implements HasLabel
{
    case User = 'user';
    case Agent = 'agent';
    case Job = 'job';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::User => 'Gebruiker',
            self::Agent => 'AI-agent',
            self::Job => 'Achtergrondtaak',
            self::System => 'Systeem',
        };
    }
}
