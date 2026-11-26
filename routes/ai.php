<?php

use App\Http\Middleware\EnsureAgentIsActive;
use App\Mcp\Servers\BackofficeServer;
use Laravel\Mcp\Facades\Mcp;

// The backoffice MCP server. Every AI agent authenticates with its own Sanctum token
// (php artisan backoffice:agent), so all its actions are logged under its name.
Mcp::web('/mcp', BackofficeServer::class)
    ->middleware(['auth:sanctum', EnsureAgentIsActive::class, 'throttle:120,1']);

// Local use (e.g. from the Coolify terminal): php artisan mcp:start backoffice
Mcp::local('backoffice', BackofficeServer::class);
