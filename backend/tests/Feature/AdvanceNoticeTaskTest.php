<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvanceNoticeTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_evening_task_saves_advance_notice_and_defaults_to_none(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Go hiking',
            'type' => 'evening',
            'advance_notice_days' => 7,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.advance_notice_days', 7);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Go hiking',
            'advance_notice_days' => 7,
        ]);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Read a book',
            'type' => 'evening',
        ])->assertCreated()->assertJsonPath('data.advance_notice_days', null);
    }

    public function test_recurring_evening_task_can_require_two_weeks_notice(): void
    {
        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Meet friends',
            'type' => 'evening',
            'recurrence_type' => 'count',
            'recurrence_rule' => [
                'period_unit' => 'month',
                'period_interval' => 1,
                'min_occurrences' => 1,
                'max_occurrences' => 1,
            ],
            'advance_notice_days' => 14,
        ])->assertCreated()
            ->assertJsonPath('data.advance_notice_days', 14)
            ->assertJsonPath('data.recurrence_type', 'count');
    }

    public function test_day_tasks_projects_and_out_of_range_values_reject_advance_notice(): void
    {
        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Buy groceries',
            'type' => 'day',
            'advance_notice_days' => 7,
        ])->assertUnprocessable()->assertJsonValidationErrors(['advance_notice_days']);

        $this->postJson('/api/v1/projects', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Summer trip',
            'advance_notice_days' => 7,
        ])->assertUnprocessable()->assertJsonValidationErrors(['advance_notice_days']);

        foreach ([0, 15] as $days) {
            $this->postJson('/api/v1/tasks', [
                'client_request_id' => (string) Str::uuid(),
                'title' => 'Plan outing',
                'type' => 'evening',
                'advance_notice_days' => $days,
            ])->assertUnprocessable()->assertJsonValidationErrors(['advance_notice_days']);
        }

    }
}
