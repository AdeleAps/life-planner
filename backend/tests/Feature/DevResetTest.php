<?php

namespace Tests\Feature;

use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_tomorrow_and_replaces_today_with_three_sample_tasks(): void
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();

        PlanningDayInput::query()->create([
            'plan_date' => $tomorrow,
            'energy_level' => 'high',
            'day_off' => true,
            'dinner_enabled' => false,
            'revision' => 1,
        ]);

        $ownTask = Task::factory()->create(['source' => 'form', 'title' => 'Keep this task']);
        ScheduledTask::factory()->create(['task_id' => $ownTask->id, 'plan_date' => $tomorrow]);
        $oldSample = Task::factory()->create(['source' => 'test_reset']);
        ScheduledTask::factory()->create(['task_id' => $oldSample->id, 'plan_date' => $today, 'source' => 'test_reset']);
        $dinnerTask = Task::factory()->create(['source' => 'dinner_groceries', 'title' => 'Grocery shopping', 'type' => 'day']);
        ScheduledTask::factory()->create(['task_id' => $dinnerTask->id, 'plan_date' => $tomorrow, 'source' => 'dinner_groceries']);

        $this->postJson('/api/v1/dev/reset')->assertOk()
            ->assertJsonPath('today', $today)
            ->assertJsonPath('tomorrow', $tomorrow)
            ->assertJsonCount(3, 'scheduled_tasks');

        $this->assertDatabaseMissing('planning_day_inputs', ['plan_date' => $tomorrow]);
        $this->assertDatabaseMissing('tasks', ['id' => $oldSample->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $dinnerTask->id]);
        $this->assertDatabaseHas('tasks', ['id' => $ownTask->id]);
        $this->assertDatabaseCount('scheduled_tasks', 3);
        $this->assertDatabaseHas('scheduled_tasks', ['plan_date' => $today, 'status' => 'planned']);
        $this->assertDatabaseMissing('scheduled_tasks', ['plan_date' => $tomorrow]);

        $this->getJson("/api/v1/planning-days/{$today}/tasks")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.task.title', 'Water the plants');

        $this->postJson('/api/v1/dev/reset')->assertOk()->assertJsonCount(3, 'scheduled_tasks');
        $this->assertDatabaseCount('scheduled_tasks', 3);
        $this->assertDatabaseCount('tasks', 4);
        $this->getJson('/api/v1/planning-days/2026-02-30/tasks')->assertUnprocessable();
    }

    public function test_reset_is_unavailable_outside_local_and_testing_environments(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('/api/v1/dev/reset')->assertNotFound();
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }
}
