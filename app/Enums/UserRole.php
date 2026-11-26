<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    /** Everything, including users, agent tokens and supplier settings. */
    case Admin = 'admin';

    /** Day-to-day work: products, bids, purchasing, tasks. */
    case Staff = 'staff';

    /** An AI agent that works through the MCP server with its own token. */
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Beheerder',
            self::Staff => 'Medewerker',
            self::Agent => 'AI-agent',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Alles, inclusief gebruikers, agent-tokens en leveranciersinstellingen',
            self::Staff => 'Dagelijks werk: producten, bids, inkoop en taken',
            self::Agent => 'Werkt via de MCP-server met een eigen token; kan niet inloggen in het beheer',
        };
    }
}
