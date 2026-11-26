<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates (or reuses) an AI agent user and issues a new MCP token for it.
 * The token is shown once; store it in the agent's MCP client configuration.
 */
class CreateAgent extends Command
{
    protected $signature = 'backoffice:agent
        {name : Naam van de agent, bijv. "Claude"}
        {email : Uniek e-mailadres voor deze agent, bijv. claude@agents.local}
        {--token-name=mcp : Naam van het token, handig bij meerdere tokens per agent}';

    protected $description = 'Maak een AI-agent aan en geef een token voor de MCP-server';

    public function handle(): int
    {
        $user = User::firstOrNew(['email' => $this->argument('email')]);

        if ($user->exists && ! $user->isAgent()) {
            $this->error('Dit e-mailadres hoort bij een gewone gebruiker, niet bij een agent.');

            return self::FAILURE;
        }

        $user->fill([
            'name' => $this->argument('name'),
            'role' => UserRole::Agent,
            'is_active' => true,
        ]);

        if (! $user->exists) {
            // Agents never log in to the panel; give them an unusable random password.
            $user->password = Str::password(64);
        }

        $user->save();

        $token = $user->createToken($this->option('token-name'))->plainTextToken;

        $this->info("Agent: {$user->name} <{$user->email}>");
        $this->newLine();
        $this->line('Token (wordt maar één keer getoond):');
        $this->line($token);
        $this->newLine();
        $this->line('MCP-server: '.rtrim(config('app.url'), '/').'/mcp');
        $this->line('Header: Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
