<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\BackofficeStatus;
use App\Mcp\Tools\ListJobRuns;
use App\Mcp\Tools\ListOpenTasks;
use App\Mcp\Tools\SearchSupplierItems;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Makkelijkpresenteren Backoffice')]
#[Version('0.2.0')]
#[Instructions(<<<'TXT'
    Backoffice of the webshop makkelijkpresenteren.nl (AV equipment: displays, projectors,
    video conferencing). The backoffice is the source of truth for products, prices, stock and
    enrichment; WooCommerce follows. Products are matched on EAN.

    Concepts:
    - Job runs: every background action (supplier import, enrichment, shop sync) with status and log.
    - Supplier items: the full catalogues of the distributors with purchase price, stock and
      availability per EAN (search-supplier-items).
    - Tasks: everything that needs a decision (sync conflict, failed run, no match, price deviation,
      approval). Start with backoffice-status for an overview.

    Rules: you act as your own agent user and every change is logged under your name. Actions with
    consequences (publishing prices, purchase orders, emails to manufacturers) are never executed
    directly; they become a task for human approval. Answer users in Dutch.
    TXT)]
class BackofficeServer extends Server
{
    protected array $tools = [
        BackofficeStatus::class,
        ListOpenTasks::class,
        ListJobRuns::class,
        SearchSupplierItems::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
