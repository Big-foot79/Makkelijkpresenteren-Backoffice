<?php

namespace Tests\Feature;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Enums\UserRole;
use App\Mcp\Servers\BackofficeServer;
use App\Mcp\Tools\BackofficeStatus;
use App\Mcp\Tools\ListJobRuns;
use App\Mcp\Tools\ListOpenTasks;
use App\Models\JobRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        User::factory()->create(); // first user = admin

        return User::factory()->create(['role' => UserRole::Agent]);
    }

    public function test_status_tool_counts_open_tasks_and_failed_runs(): void
    {
        Task::create(['kind' => TaskKind::SyncConflict, 'title' => 'Prijs gewijzigd in Woo']);
        Task::create(['kind' => TaskKind::SyncConflict, 'title' => 'Titel gewijzigd in Woo']);
        Task::create(['kind' => TaskKind::NoMatch, 'title' => 'Onbekende EAN']);
        JobRun::create(['type' => 'import.test', 'label' => 'Test', 'status' => JobRunStatus::Failed]);

        BackofficeServer::actingAs($this->agent())
            ->tool(BackofficeStatus::class)
            ->assertOk()
            ->assertStructuredContentPath('open_tasks_total', 3)
            ->assertStructuredContentPath('open_tasks_by_kind.sync_conflict', 2)
            ->assertStructuredContentPath('job_runs_last_24h.failed', 1);
    }

    public function test_open_tasks_can_be_filtered_by_kind(): void
    {
        Task::create(['kind' => TaskKind::SyncConflict, 'title' => 'Prijs gewijzigd in Woo']);
        Task::create(['kind' => TaskKind::NoMatch, 'title' => 'Onbekende EAN']);

        BackofficeServer::actingAs($this->agent())
            ->tool(ListOpenTasks::class, ['kind' => 'no_match'])
            ->assertOk()
            ->assertStructuredContentPath('count', 1)
            ->assertSee('Onbekende EAN')
            ->assertDontSee('Prijs gewijzigd');
    }

    public function test_job_runs_can_include_the_log(): void
    {
        $run = JobRun::create(['type' => 'import.tdsynnex.stock', 'label' => 'TD Synnex voorraad', 'status' => JobRunStatus::Failed]);
        $run->error('FTP onbereikbaar');

        BackofficeServer::actingAs($this->agent())
            ->tool(ListJobRuns::class, ['status' => 'failed', 'include_log' => true])
            ->assertOk()
            ->assertSee('FTP onbereikbaar');
    }

    public function test_http_endpoint_requires_an_agent_token(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();

        User::factory()->create(); // first user = admin
        $staffToken = User::factory()->create()->createToken('x')->plainTextToken;

        $this->withToken($staffToken)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertForbidden();
    }

    public function test_agent_token_can_list_tools_over_http(): void
    {
        $token = $this->agent()->createToken('mcp')->plainTextToken;

        $this->withToken($token)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertSee('backoffice-status')
            ->assertSee('list-open-tasks')
            ->assertSee('list-job-runs');
    }
}
