<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (fundament): roles, job runs, tasks and the change history.
 * Domain tables (products, suppliers, bids, ...) follow per phase, see docs/DATAMODEL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('staff')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // Every background action (import, enrichment, sync, supplier message) is a visible run.
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();          // e.g. import.tdsynnex.stock
            $table->string('label');                  // human readable
            $table->string('status')->index();        // App\Enums\JobRunStatus
            $table->nullableMorphs('subject');        // optional: the product, supplier, ... it is about
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('input')->nullable();        // what is needed to retry the run
            $table->text('summary')->nullable();      // one line result, e.g. "1.204 regels, 3 fouten"
            $table->longText('log')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Everything that needs a human (or agent) decision.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->index();          // App\Enums\TaskKind
            $table->string('status')->index();        // App\Enums\TaskStatus
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('data')->nullable();         // e.g. both values of a sync conflict
            $table->nullableMorphs('subject');
            $table->foreignId('job_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
        });

        // Who changed what: person, AI agent, job or system.
        Schema::create('changes', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('event');                  // created, updated, deleted
            $table->string('actor_type');             // App\Enums\ActorType
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('via')->nullable();        // panel, mcp, webhook, import, ...
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changes');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('job_runs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
