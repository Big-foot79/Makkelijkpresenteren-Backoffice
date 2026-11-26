<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\JobRunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Change;
use App\Models\JobRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_user_becomes_admin_and_next_user_is_staff(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertSame(UserRole::Admin, $first->fresh()->role);
        $this->assertSame(UserRole::Staff, $second->fresh()->role);
    }

    public function test_agents_cannot_use_the_panel(): void
    {
        User::factory()->create();
        $agent = User::factory()->create(['role' => UserRole::Agent]);

        $this->actingAs($agent)->get('/admin')->assertForbidden();
    }

    public function test_successful_job_run_is_recorded_with_summary_and_log(): void
    {
        $result = JobRun::track('test.ok', 'Testrun', function (JobRun $run) {
            $run->info('Stap 1');

            return '3 regels verwerkt';
        });

        $run = JobRun::sole();
        $this->assertSame('3 regels verwerkt', $result);
        $this->assertSame(JobRunStatus::Succeeded, $run->status);
        $this->assertSame('3 regels verwerkt', $run->summary);
        $this->assertStringContainsString('Stap 1', $run->log);
        $this->assertNotNull($run->finished_at);
    }

    public function test_failed_job_run_opens_a_task_and_rethrows(): void
    {
        try {
            JobRun::track('test.fail', 'Kapotte run', fn () => throw new RuntimeException('FTP onbereikbaar'));
            $this->fail('Exception was not re-thrown');
        } catch (RuntimeException) {
        }

        $run = JobRun::sole();
        $this->assertSame(JobRunStatus::Failed, $run->status);

        $task = Task::sole();
        $this->assertSame(TaskKind::JobFailed, $task->kind);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertSame($run->id, $task->job_run_id);
    }

    public function test_changes_are_credited_to_the_acting_user_or_job(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $task = Task::create(['kind' => TaskKind::Other, 'title' => 'Controleer EAN']);
        $task->resolve(TaskStatus::Done, 'Opgelost');

        $changes = Change::where('subject_type', $task->getMorphClass())->where('subject_id', $task->id)->get();
        $this->assertCount(2, $changes);
        $this->assertTrue($changes->every(fn (Change $c) => $c->actor_type === ActorType::User && $c->user_id === $admin->id));
        $this->assertSame('done', $changes->last()->new_values['status']);

        JobRun::track('test.edit', 'Job past taak aan', fn () => $task->update(['title' => 'Aangepast door job']));
        $jobChange = Change::latest('id')->first();
        $this->assertSame(ActorType::Job, $jobChange->actor_type);
        $this->assertNotNull($jobChange->job_run_id);
    }

    public function test_password_is_never_written_to_the_change_history(): void
    {
        $user = User::factory()->create();

        $change = Change::where('subject_type', $user->getMorphClass())->sole();
        $this->assertArrayNotHasKey('password', $change->new_values);
    }
}
