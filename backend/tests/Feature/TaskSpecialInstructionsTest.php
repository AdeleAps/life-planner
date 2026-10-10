<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskSpecialInstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_and_evening_tasks_can_store_optional_special_instructions(): void
    {
        foreach (['day', 'evening'] as $type) {
            $instructions = $type === 'day'
                ? 'Check the weather report before planning.'
                : 'Research nearby parking and include the options in my plan.';

            $response = $this->postJson('/api/v1/tasks', [
                'client_request_id' => (string) Str::uuid(),
                'title' => "Plan {$type} activity",
                'type' => $type,
                'special_instructions' => $instructions,
            ])->assertCreated()
                ->assertJsonPath('data.special_instructions', $instructions);

            $this->assertDatabaseHas('tasks', [
                'id' => $response->json('data.id'),
                'special_instructions' => $instructions,
            ]);
        }

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'No extra instructions',
            'type' => 'day',
        ])->assertCreated()->assertJsonPath('data.special_instructions', null);
    }

    public function test_editing_a_task_can_change_or_clear_special_instructions(): void
    {
        $task = Task::factory()->create(['special_instructions' => 'Check the forecast.']);

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'revision' => 1,
            'title' => $task->title,
            'type' => 'evening',
            'recurrence_type' => 'one_time',
            'special_instructions' => 'Research parking first.',
        ])->assertOk()->assertJsonPath('data.special_instructions', 'Research parking first.');

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'revision' => 2,
            'title' => $task->title,
            'type' => 'evening',
            'recurrence_type' => 'one_time',
            'special_instructions' => null,
        ])->assertOk()->assertJsonPath('data.special_instructions', null);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'special_instructions' => null]);
    }

    public function test_projects_cannot_have_special_instructions(): void
    {
        $this->postJson('/api/v1/projects', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Summer plans',
            'special_instructions' => 'Check the weather.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['special_instructions']);

        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);

        $this->putJson("/api/v1/projects/{$project->id}", [
            'revision' => 1,
            'title' => $project->title,
            'special_instructions' => 'Research parking.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['special_instructions']);
    }
}
