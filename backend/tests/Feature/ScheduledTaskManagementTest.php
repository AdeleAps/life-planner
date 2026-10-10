<?php

namespace Tests\Feature;

use App\Models\PlanningCycle;
use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledTaskManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_and_unsetting_an_assignment_keeps_the_task(): void
    {
        $today = CarbonImmutable::now('Europe/Riga');
        $future = $today->addDays(3)->toDateString();
        $task = Task::factory()->create();
        $assignment = ScheduledTask::factory()->create([
            'task_id' => $task->id, 'plan_date' => $today->toDateString(), 'source' => 'planner',
        ]);

        $this->patchJson("/api/v1/scheduled-tasks/{$assignment->id}", ['plan_date' => $future])
            ->assertOk()->assertJsonPath('data.plan_date', $future)
            ->assertJsonPath('data.source', 'manual');
        $this->getJson("/api/v1/planning-days/{$today->toDateString()}/tasks")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/planning-days/{$future}/tasks")
            ->assertOk()->assertJsonPath('data.0.id', $assignment->id);

        $this->deleteJson("/api/v1/scheduled-tasks/{$assignment->id}")
            ->assertOk()->assertJsonPath('removed', true);
        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $assignment->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'lifecycle_state' => 'active']);
    }

    public function test_rescheduling_a_missed_task_preserves_the_missed_day(): void
    {
        $today = CarbonImmutable::now('Europe/Riga');
        $past = $today->subDay()->toDateString();
        $future = $today->addDay()->toDateString();
        $task = Task::factory()->create();
        $missed = ScheduledTask::factory()->create([
            'task_id' => $task->id, 'plan_date' => $past,
            'status' => 'not_completed', 'source' => 'planner',
        ]);

        $this->deleteJson("/api/v1/scheduled-tasks/{$missed->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);
        $this->patchJson("/api/v1/scheduled-tasks/{$missed->id}", ['plan_date' => $future])
            ->assertOk()->assertJsonPath('data.plan_date', $future)
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.source', 'manual');

        $this->assertDatabaseHas('scheduled_tasks', [
            'id' => $missed->id, 'plan_date' => $past, 'status' => 'not_completed',
        ]);
        $this->assertDatabaseHas('scheduled_tasks', [
            'task_id' => $task->id, 'plan_date' => $future,
            'status' => 'planned', 'source' => 'manual',
        ]);
    }

    public function test_manual_adjustments_keep_a_saved_tomorrow_plan_marked_as_ready(): void
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        PlanningDayInput::query()->create([
            'plan_date' => $tomorrow, 'energy_level' => 'standard',
            'day_off' => false, 'dinner_enabled' => false, 'revision' => 1,
        ]);
        PlanningCycle::query()->create(['plan_date' => $tomorrow, 'plan_completed_at' => now()]);
        $assignment = ScheduledTask::factory()->create(['plan_date' => $tomorrow]);

        $this->deleteJson("/api/v1/scheduled-tasks/{$assignment->id}")->assertOk();
        $this->getJson("/api/v1/planning-days/{$tomorrow}/plan")
            ->assertOk()->assertJsonPath('data.published', true)
            ->assertJsonPath('data.note', '');
    }

    public function test_moving_accepted_advance_task_clears_old_forecast_and_unsetting_clears_acceptance(): void
    {
        $today = CarbonImmutable::now('Europe/Riga');
        $oldDate = $today->addDays(3)->toDateString();
        $newDate = $today->addDays(4)->toDateString();
        $task = Task::factory()->create(['advance_notice_days' => 2]);
        $assignment = ScheduledTask::factory()->create([
            'task_id' => $task->id, 'plan_date' => $oldDate, 'source' => 'advance_accepted',
        ]);
        DB::table('advance_offers')->insert([
            'task_id' => $task->id, 'task_revision' => $task->revision, 'proposed_on' => $oldDate,
            'status' => 'accepted', 'research_result' => json_encode([
                'status' => 'ready', 'summary' => 'Dry on the original day.', 'sources' => [],
            ]), 'notice_shortfall_days' => 0, 'allowance_cents' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/scheduled-tasks/{$assignment->id}", ['plan_date' => $newDate])
            ->assertOk()->assertJsonPath('data.source', 'advance_accepted')
            ->assertJsonPath('data.research_result', null);
        $this->assertDatabaseHas('advance_offers', [
            'task_id' => $task->id, 'status' => 'accepted', 'proposed_on' => $newDate,
            'research_result' => null,
        ]);

        $this->deleteJson("/api/v1/scheduled-tasks/{$assignment->id}")->assertOk();
        $this->assertDatabaseHas('advance_offers', ['task_id' => $task->id, 'status' => 'declined']);
    }

    public function test_moves_reject_completed_tasks_duplicate_dates_and_hard_deadline_violations(): void
    {
        $today = CarbonImmutable::now('Europe/Riga');
        $deadline = $today->addDays(2)->toDateString();
        $task = Task::factory()->create(['deadline_on' => $deadline, 'deadline_is_hard' => true]);
        $assignment = ScheduledTask::factory()->create([
            'task_id' => $task->id, 'plan_date' => $today->toDateString(),
        ]);
        ScheduledTask::factory()->create(['task_id' => $task->id, 'plan_date' => $deadline]);

        $this->patchJson("/api/v1/scheduled-tasks/{$assignment->id}", [
            'plan_date' => $today->addDays(3)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['plan_date']);
        $this->patchJson("/api/v1/scheduled-tasks/{$assignment->id}", [
            'plan_date' => $deadline,
        ])->assertUnprocessable()->assertJsonValidationErrors(['plan_date']);

        $assignment->update(['status' => 'completed']);
        $this->patchJson("/api/v1/scheduled-tasks/{$assignment->id}", [
            'plan_date' => $today->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);
        $this->deleteJson("/api/v1/scheduled-tasks/{$assignment->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);
    }

    public function test_dinner_tasks_can_only_be_deleted_and_do_not_reappear(): void
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $this->putJson("/api/v1/planning-days/{$tomorrow}/setup", [
            'energy_level' => 'standard', 'day_off' => false, 'dinner_enabled' => true,
            'dinner_dish' => 'Pasta',
        ])->assertCreated();
        $dinner = ScheduledTask::query()->where('plan_date', $tomorrow)
            ->where('source', 'dinner_cooking')->firstOrFail();
        $groceries = ScheduledTask::query()->where('plan_date', $tomorrow)
            ->where('source', 'dinner_groceries')->firstOrFail();

        $this->patchJson("/api/v1/scheduled-tasks/{$dinner->id}", [
            'plan_date' => CarbonImmutable::now('Europe/Riga')->addDays(2)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);
        $this->deleteJson("/api/v1/scheduled-tasks/{$dinner->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);
        $this->patchJson("/api/v1/scheduled-tasks/{$groceries->id}", [
            'plan_date' => CarbonImmutable::now('Europe/Riga')->addDays(2)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);
        $this->deleteJson("/api/v1/scheduled-tasks/{$groceries->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_task']);

        $this->deleteJson("/api/v1/tasks/{$dinner->task_id}")->assertOk();
        $this->assertDatabaseHas('planning_day_inputs', [
            'plan_date' => $tomorrow, 'dinner_enabled' => false,
        ]);
        $this->assertDatabaseHas('scheduled_tasks', ['id' => $groceries->id, 'source' => 'manual']);

        $this->putJson("/api/v1/planning-days/{$tomorrow}/setup", [
            'energy_level' => 'standard', 'day_off' => false, 'dinner_enabled' => false,
        ])->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $dinner->task_id]);
        $this->assertDatabaseCount('scheduled_tasks', 1);
    }
}
