<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningDayInputTest extends TestCase
{
    use RefreshDatabase;

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
