<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DayTaskRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_task_can_repeat_on_set_days(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Water plants',
            'type' => 'day',
            'recurrence_type' => 'schedule',
            'recurrence_rule' => [
                'unit' => 'week',
                'interval' => 1,
                'weekdays' => [1, 5],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'recurring')
            ->assertJsonPath('data.type', 'day')
            ->assertJsonPath('data.recurrence_type', 'schedule')
            ->assertJsonPath('data.recurrence_rule.weekdays', [1, 5])
            ->assertJsonPath('data.energy_cost', null)
            ->assertJsonPath('data.priority', null);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Water plants',
            'type' => 'day',
            'item_type' => 'task',
        ]);
    }

    public function test_day_task_can_repeat_by_count_target(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Tidy desk',
            'type' => 'day',
            'recurrence_type' => 'count',
            'recurrence_rule' => [
                'period_unit' => 'month',
                'period_interval' => 3,
                'min_occurrences' => 2,
                'max_occurrences' => 3,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'recurring')
            ->assertJsonPath('data.type', 'day')
            ->assertJsonPath('data.recurrence_type', 'count')
            ->assertJsonPath('data.recurrence_rule.period_interval', 3)
            ->assertJsonPath('data.recurrence_rule.min_occurrences', 2)
            ->assertJsonPath('data.recurrence_rule.max_occurrences', 3)
            ->assertJsonPath('data.energy_cost', null)
            ->assertJsonPath('data.priority', null);
    }

    public function test_day_task_recurrence_requires_a_rule(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Water plants',
            'type' => 'day',
            'recurrence_type' => 'schedule',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['recurrence_rule']);
    }
}
