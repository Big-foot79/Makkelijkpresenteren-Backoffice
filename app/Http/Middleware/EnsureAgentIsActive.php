<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only active AI-agent users may use the MCP server. Switching an agent off in the panel
 * (Gebruikers → actief) cuts off all its tokens at once.
 */
class EnsureAgentIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAgent() || ! $user->is_active) {
            abort(403, 'Alleen actieve AI-agents hebben toegang tot de MCP-server.');
        }

        return $next($request);
    }
}
