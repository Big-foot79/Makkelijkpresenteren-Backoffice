<?php

namespace Tests\Feature;

use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Changes\ChangeResource;
use App\Filament\Resources\JobRuns\JobRunResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\BackofficeStats;
use App\Models\JobRun;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_every_page(): void
    {
        $admin = User::factory()->create();
        $run = JobRun::create(['type' => 'import.test', 'label' => 'Testimport', 'status' => JobRunStatus::Failed, 'log' => '[10:00:00] ERROR FTP onbereikbaar']);
        $task = Task::create(['kind' => TaskKind::JobFailed, 'title' => 'Mislukt: Testimport', 'job_run_id' => $run->id, 'data' => ['bestand' => 'stock.zip']]);

        $this->actingAs($admin);

        $this->get('/admin')->assertOk()->assertSee('Makkelijkpresenteren Backoffice');
        $this->get(TaskResource::getUrl())->assertOk()->assertSee('Mislukt: Testimport');
        $this->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk()->assertSee('stock.zip');
        $this->get(JobRunResource::getUrl())->assertOk()->assertSee('Testimport');
        $this->get(JobRunResource::getUrl('view', ['record' => $run]))->assertOk()->assertSee('FTP onbereikbaar');
        $this->get(ChangeResource::getUrl())->assertOk();
        $this->get(UserResource::getUrl())->assertOk();
        $this->get(UserResource::getUrl('create'))->assertOk();
    }

    public function test_a_task_can_be_completed_from_the_table(): void
    {
        $admin = User::factory()->create();
        $task = Task::create(['kind' => TaskKind::NoMatch, 'title' => 'Onbekende EAN 8712345678901']);

        $this->actingAs($admin);

        Livewire::test(ListTasks::class)
            ->callAction(TestAction::make('complete')->table($task), data: ['resolution' => 'EAN gecorrigeerd'])
            ->assertHasNoFormErrors();

        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertSame('EAN gecorrigeerd', $task->resolution);
        $this->assertSame($admin->id, $task->resolved_by_user_id);
    }

    public function test_dashboard_stats_widget_renders(): void
    {
        $this->actingAs(User::factory()->create());
        Task::create(['kind' => TaskKind::Approval, 'title' => 'Nieuwe producttekst']);

        Livewire::test(BackofficeStats::class)
            ->assertSee('Open taken')
            ->assertSee('1 ter goedkeuring');
    }

    public function test_staff_cannot_manage_users(): void
    {
        User::factory()->create(); // admin
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff);

        $this->get(TaskResource::getUrl())->assertOk();
        $this->get(UserResource::getUrl())->assertForbidden();
    }

    public function test_inactive_users_cannot_use_the_panel(): void
    {
        User::factory()->create(); // admin
        $staff = User::factory()->create(['is_active' => false]);

        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }
}
