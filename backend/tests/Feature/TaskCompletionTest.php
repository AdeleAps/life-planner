<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_time_task_finishes_and_retry_does_not_create_another_record(): void
    {
        $task = Task::factory()->create();
        $requestId = (string) Str::uuid();
        $input = ['client_request_id' => $requestId, 'revision' => 1, 'completion_type' => 'final'];

        $response = $this->postJson("/api/v1/tasks/{$task->id}/completions", $input);
        $response->assertCreated()
            ->assertJsonPath('data.lifecycle_state', 'completed')
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('completion.completion_type', 'final')
            ->assertJsonPath('completion_count', 1);

        $this->assertNotNull($response->json('completion.completed_at'));
        $this->postJson("/api/v1/tasks/{$task->id}/completions", $input)
            ->assertOk()->assertJsonPath('completion_count', 1);

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tasks?include_completed=1')->assertOk()
            ->assertJsonPath('data.0.lifecycle_state', 'completed');
        $this->assertDatabaseCount('task_completions', 1);
    }

    public function test_recurring_task_can_record_more_than_once_on_the_same_day_and_stays_active(): void
    {
        $task = Task::factory()->create([
            'recurrence_type' => 'schedule',
            'recurrence_rule' => ['unit' => 'day', 'interval' => 1],
        ]);

        foreach ([1, 2] as $revision) {
            $this->postJson("/api/v1/tasks/{$task->id}/completions", [
                'client_request_id' => (string) Str::uuid(),
                'revision' => $revision,
                'completion_type' => 'occurrence',
            ])->assertCreated()
                ->assertJsonPath('data.lifecycle_state', 'active')
                ->assertJsonPath('completion_count', $revision);
        }

        $this->getJson("/api/v1/tasks/{$task->id}/completions")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.completion_type', 'occurrence');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'lifecycle_state' => 'active', 'revision' => 3]);
    }

    public function test_multi_session_task_can_finish_a_session_then_finish_entire_task(): void
    {
        $task = Task::factory()->create(['is_multi_session' => true]);

        $this->postJson("/api/v1/tasks/{$task->id}/completions", [
            'client_request_id' => (string) Str::uuid(),
            'revision' => 1,
            'completion_type' => 'session',
        ])->assertCreated()
            ->assertJsonPath('data.lifecycle_state', 'active')
            ->assertJsonPath('completion_count', 1);

        $this->postJson("/api/v1/tasks/{$task->id}/completions", [
            'client_request_id' => (string) Str::uuid(),
            'revision' => 2,
            'completion_type' => 'final',
        ])->assertCreated()
            ->assertJsonPath('data.lifecycle_state', 'completed')
            ->assertJsonPath('completion_count', 2);

        $this->getJson("/api/v1/tasks/{$task->id}/completions")
            ->assertOk()
            ->assertJsonPath('data.0.completion_type', 'final')
            ->assertJsonPath('data.1.completion_type', 'session');
    }

    public function test_invalid_actions_stale_revisions_and_projects_are_rejected(): void
    {
        $task = Task::factory()->create();
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);

        $this->postJson("/api/v1/tasks/{$task->id}/completions", [
            'client_request_id' => (string) Str::uuid(),
            'revision' => 1,
            'completion_type' => 'session',
        ])->assertUnprocessable()->assertJsonValidationErrors(['completion_type']);

        $this->postJson("/api/v1/tasks/{$task->id}/completions", [
            'client_request_id' => (string) Str::uuid(),
            'revision' => 2,
            'completion_type' => 'final',
        ])->assertStatus(409);

        $this->postJson("/api/v1/tasks/{$project->id}/completions", [
            'client_request_id' => (string) Str::uuid(),
            'revision' => 1,
            'completion_type' => 'final',
        ])->assertNotFound();
        $this->getJson("/api/v1/tasks/{$project->id}/completions")->assertNotFound();
        $this->assertDatabaseCount('task_completions', 0);
    }
}
