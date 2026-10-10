<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DayPreferenceTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_evening_task_defaults_to_no_day_preference_and_can_prefer_workdays(): void
    {
        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Read',
            'type' => 'evening',
        ])->assertCreated()
            ->assertJsonPath('data.day_preference', null)
            ->assertJsonPath('data.day_preference_is_hard', null);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Study',
            'type' => 'evening',
            'day_preference' => 'workday',
        ])->assertCreated()
            ->assertJsonPath('data.day_preference', 'workday')
            ->assertJsonPath('data.day_preference_is_hard', false);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Study',
            'day_preference' => 'workday',
            'day_preference_is_hard' => false,
        ]);
    }

    public function test_recurring_evening_task_can_require_non_workdays(): void
    {
        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Go hiking',
            'type' => 'evening',
            'recurrence_type' => 'count',
            'recurrence_rule' => [
                'period_unit' => 'month',
                'period_interval' => 1,
                'min_occurrences' => 1,
                'max_occurrences' => 1,
            ],
            'day_preference' => 'non_workday',
            'day_preference_is_hard' => true,
        ])->assertCreated()
            ->assertJsonPath('data.day_preference', 'non_workday')
            ->assertJsonPath('data.day_preference_is_hard', true);
    }

    public function test_day_tasks_projects_and_incomplete_preferences_are_rejected(): void
    {
        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Buy groceries',
            'type' => 'day',
            'day_preference' => 'non_workday',
        ])->assertUnprocessable()->assertJsonValidationErrors(['day_preference']);

        $this->postJson('/api/v1/projects', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Trip',
            'day_preference' => 'workday',
        ])->assertUnprocessable()->assertJsonValidationErrors(['day_preference']);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Study',
            'type' => 'evening',
            'day_preference_is_hard' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['day_preference_is_hard']);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Study',
            'type' => 'evening',
            'day_preference' => 'holiday_only',
        ])->assertUnprocessable()->assertJsonValidationErrors(['day_preference']);

    }
}
