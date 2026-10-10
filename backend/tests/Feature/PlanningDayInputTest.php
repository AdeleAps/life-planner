<?php

namespace Tests\Feature;

use App\Models\ScheduledTask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanningDayInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_tomorrow_setup_requires_review_and_only_unfinished_assignments_appear(): void
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $unfinishedTask = Task::factory()->create(['title' => 'Unfinished']);
        $finishedTask = Task::factory()->create(['title' => 'Already finished']);
        $unfinished = ScheduledTask::factory()->create(['task_id' => $unfinishedTask->id, 'plan_date' => $today]);
        ScheduledTask::factory()->create(['task_id' => $finishedTask->id, 'plan_date' => $today]);

        $this->postJson("/api/v1/tasks/{$finishedTask->id}/completions", [
            'client_request_id' => (string) Str::uuid(),
            'revision' => $finishedTask->revision,
            'completion_type' => 'final',
        ])->assertCreated();

        $this->getJson('/api/v1/planning-review/pending')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unfinished->id);

        $settings = ['energy_level' => 'standard', 'day_off' => false, 'dinner_enabled' => false];
        $this->putJson("/api/v1/planning-days/{$tomorrow}/setup", $settings)
            ->assertUnprocessable()->assertJsonValidationErrors(['task_review']);

        $this->assertDatabaseMissing('planning_day_inputs', ['plan_date' => $tomorrow]);
        $this->assertDatabaseHas('scheduled_tasks', ['id' => $unfinished->id, 'status' => 'planned']);
        $this->assertDatabaseCount('task_completions', 1);
    }

    public function test_review_defaults_can_be_saved_as_completions_or_explicitly_left_unfinished(): void
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $completedTask = Task::factory()->create(['title' => 'Complete in review']);
        $unfinishedTask = Task::factory()->create(['title' => 'Leave unfinished']);
        $completed = ScheduledTask::factory()->create(['task_id' => $completedTask->id, 'plan_date' => $today]);
        $unfinished = ScheduledTask::factory()->create(['task_id' => $unfinishedTask->id, 'plan_date' => $today]);
        $settings = [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'task_review' => [
                ['scheduled_task_id' => $completed->id, 'done' => true],
                ['scheduled_task_id' => $unfinished->id, 'done' => false],
            ],
        ];

        $this->putJson("/api/v1/planning-days/{$tomorrow}/setup", $settings)->assertCreated();
        $this->assertDatabaseHas('scheduled_tasks', ['id' => $completed->id, 'status' => 'completed']);
        $this->assertDatabaseHas('scheduled_tasks', ['id' => $unfinished->id, 'status' => 'not_completed']);
        $this->assertDatabaseHas('tasks', ['id' => $completedTask->id, 'lifecycle_state' => 'completed']);
        $this->assertDatabaseHas('tasks', ['id' => $unfinishedTask->id, 'lifecycle_state' => 'active']);
        $this->assertDatabaseHas('task_completions', ['task_id' => $completedTask->id, 'completion_type' => 'final']);
        $this->assertDatabaseCount('task_completions', 1);
        $this->getJson('/api/v1/planning-review/pending')->assertOk()->assertJsonCount(0, 'data');

        $this->putJson("/api/v1/planning-days/{$tomorrow}/setup", [
            'energy_level' => 'low',
            'day_off' => false,
            'dinner_enabled' => false,
            'task_review' => [],
        ])->assertOk();
        $this->assertDatabaseCount('task_completions', 1);
    }

    public function test_missed_day_stays_pending_until_review_and_recurring_review_records_an_occurrence(): void
    {
        $yesterday = CarbonImmutable::now('Europe/Riga')->subDay()->toDateString();
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $task = Task::factory()->create([
            'recurrence_type' => 'schedule',
            'recurrence_rule' => ['unit' => 'day', 'interval' => 1],
        ]);
        $assignment = ScheduledTask::factory()->create(['task_id' => $task->id, 'plan_date' => $yesterday]);

        $this->getJson('/api/v1/planning-review/pending')->assertOk()
            ->assertJsonPath('data.0.plan_date', $yesterday);

        $this->putJson("/api/v1/planning-days/{$tomorrow}/setup", [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'task_review' => [['scheduled_task_id' => $assignment->id, 'done' => true]],
        ])->assertCreated();

        $this->assertDatabaseHas('scheduled_tasks', ['id' => $assignment->id, 'status' => 'completed']);
        $this->assertDatabaseHas('task_completions', ['task_id' => $task->id, 'completion_type' => 'occurrence']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'lifecycle_state' => 'active']);
    }

    public function test_tomorrow_setup_defaults_save_and_reload_by_date(): void
    {
        $url = '/api/v1/planning-days/2026-10-11/setup';

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.plan_date', '2026-10-11')
            ->assertJsonPath('data.energy_level', 'standard')
            ->assertJsonPath('data.day_off', false)
            ->assertJsonPath('data.dinner_enabled', false)
            ->assertJsonPath('data.saved', false);

        $settings = [
            'energy_level' => 'low',
            'day_off' => false,
            'dinner_enabled' => true,
            'dinner_dish' => '  Pasta  ',
        ];

        $this->putJson($url, $settings)->assertCreated()
            ->assertJsonPath('data.dinner_dish', 'Pasta')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.saved', true);

        $this->putJson($url, $settings)->assertOk()->assertJsonPath('data.revision', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.dinner_dish', 'Pasta');

        $this->putJson($url, [
            'energy_level' => 'standard',
            'day_off' => true,
            'dinner_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.dinner_dish', null)
            ->assertJsonPath('data.revision', 2);

        $this->getJson('/api/v1/planning-days/2026-10-12/setup')->assertOk()
            ->assertJsonPath('data.energy_level', 'standard')
            ->assertJsonPath('data.saved', false);

        $this->assertDatabaseCount('planning_day_inputs', 1);
    }

    public function test_dinner_requires_a_dish_and_invalid_dates_are_rejected(): void
    {
        $this->putJson('/api/v1/planning-days/2026-10-11/setup', [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => true,
            'dinner_dish' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['dinner_dish']);

        $this->getJson('/api/v1/planning-days/2026-02-30/setup')->assertUnprocessable();
        $this->assertDatabaseCount('planning_day_inputs', 0);
    }

    public function test_manual_tasks_are_saved_without_duplicates_and_can_be_removed(): void
    {
        $first = Task::factory()->create(['title' => 'First task']);
        $second = Task::factory()->create(['title' => 'Second task']);
        $settings = [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'manual_task_ids' => [$first->id, $second->id],
        ];

        $this->putJson('/api/v1/planning-days/2026-10-11/setup', $settings)->assertCreated()
            ->assertJsonCount(2, 'scheduled_tasks');
        $this->putJson('/api/v1/planning-days/2026-10-11/setup', $settings)->assertOk();
        $this->assertDatabaseCount('scheduled_tasks', 2);

        $this->getJson('/api/v1/planning-days/2026-10-11/setup')->assertOk()
            ->assertJsonCount(2, 'scheduled_tasks');

        $this->getJson('/api/v1/planning-days/2026-10-11/tasks')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'manual');

        $settings['manual_task_ids'] = [$second->id];
        $this->putJson('/api/v1/planning-days/2026-10-11/setup', $settings)->assertOk();
        $this->assertDatabaseCount('scheduled_tasks', 1);
        $this->assertDatabaseHas('scheduled_tasks', ['task_id' => $second->id, 'plan_date' => '2026-10-11']);

        $settings['manual_task_ids'] = [];
        $this->putJson('/api/v1/planning-days/2026-10-11/setup', $settings)->assertOk();
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_high_energy_and_day_off_with_dinner_are_rejected(): void
    {
        $url = '/api/v1/planning-days/2026-10-11/setup';

        $this->putJson($url, [
            'energy_level' => 'high',
            'day_off' => false,
            'dinner_enabled' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['energy_level']);

        $this->putJson($url, [
            'energy_level' => 'standard',
            'day_off' => true,
            'dinner_enabled' => true,
            'dinner_dish' => 'Pasta',
        ])->assertUnprocessable()->assertJsonValidationErrors(['dinner_enabled']);

        $this->assertDatabaseCount('planning_day_inputs', 0);
    }

    public function test_dinner_creates_two_default_tasks_once_and_removes_them_when_switched_off(): void
    {
        $url = '/api/v1/planning-days/2026-10-11/setup';
        $manualTask = Task::factory()->create(['title' => 'Keep me tomorrow']);
        $settings = [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => true,
            'dinner_dish' => 'Pasta',
            'manual_task_ids' => [$manualTask->id],
        ];

        $this->putJson($url, $settings)->assertCreated()->assertJsonCount(3, 'scheduled_tasks');
        $this->assertDatabaseHas('tasks', [
            'title' => 'Grocery shopping',
            'source' => 'dinner_groceries',
            'type' => 'day',
            'priority' => null,
            'energy_cost' => null,
            'recurrence_type' => 'one_time',
        ]);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Dinner',
            'source' => 'dinner_cooking',
            'type' => 'evening',
            'priority' => 'standard',
            'energy_cost' => 'standard',
            'recurrence_type' => 'one_time',
        ]);
        $this->assertDatabaseHas('scheduled_tasks', ['plan_date' => '2026-10-11', 'source' => 'dinner_groceries']);
        $this->assertDatabaseHas('scheduled_tasks', ['plan_date' => '2026-10-11', 'source' => 'dinner_cooking']);

        $this->putJson($url, $settings)->assertOk()->assertJsonCount(3, 'scheduled_tasks');
        $settings['dinner_dish'] = 'Soup';
        $this->putJson($url, $settings)->assertOk()->assertJsonCount(3, 'scheduled_tasks');
        $this->assertDatabaseCount('tasks', 3);

        $settings['day_off'] = true;
        $settings['dinner_enabled'] = false;
        $settings['dinner_dish'] = null;
        $this->putJson($url, $settings)->assertOk()->assertJsonCount(1, 'scheduled_tasks');
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('scheduled_tasks', ['task_id' => $manualTask->id, 'plan_date' => '2026-10-11']);
    }

    public function test_unfinished_dependencies_cannot_be_manually_scheduled(): void
    {
        $dependency = Task::factory()->create();
        $task = Task::factory()->create();
        $task->dependencies()->attach($dependency);

        $this->putJson('/api/v1/planning-days/2026-10-11/setup', [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'manual_task_ids' => [$task->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['manual_task_ids']);

        $this->assertDatabaseCount('planning_day_inputs', 0);
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }
}
