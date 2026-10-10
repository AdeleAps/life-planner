<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiSessionTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_time_evening_task_can_continue_across_sessions(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Finish God of War',
            'type' => 'evening',
            'recurrence_type' => 'one_time',
            'is_multi_session' => true,
            'deadline_on' => '2026-12-31',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'one_off')
            ->assertJsonPath('data.is_multi_session', true)
            ->assertJsonPath('data.deadline_on', '2026-12-31')
            ->assertJsonPath('data.lifecycle_state', 'active');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Finish God of War',
            'is_multi_session' => true,
            'lifecycle_state' => 'active',
        ]);
    }

    public function test_one_time_evening_task_defaults_to_one_session(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Reply to email',
            'type' => 'evening',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_multi_session', false);
    }

    public function test_day_and_recurring_tasks_cannot_be_multi_session(): void
    {
        $dayResponse = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Buy groceries',
            'type' => 'day',
            'is_multi_session' => true,
        ]);

        $dayResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['is_multi_session']);

        $recurringResponse = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Clean the house',
            'type' => 'evening',
            'recurrence_type' => 'schedule',
            'recurrence_rule' => [
                'unit' => 'week',
                'interval' => 1,
                'weekdays' => [0],
            ],
            'is_multi_session' => true,
        ]);

        $recurringResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['is_multi_session']);
    }
}
