<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_waits_for_all_dependencies_to_be_completed(): void
    {
        $first = Task::factory()->create(['title' => 'Buy paint']);
        $second = Task::factory()->create(['title' => 'Clear room']);

        $response = $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Paint room',
            'dependency_ids' => [$first->id, $second->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.dependencies.0.id', $first->id)
            ->assertJsonPath('data.dependencies.1.id', $second->id)
            ->assertJsonPath('data.dependencies_complete', false);

        $this->assertDatabaseCount('task_dependencies', 2);

        $first->update(['lifecycle_state' => 'completed']);
        $this->getJson('/api/v1/tasks')->assertOk()
            ->assertJsonPath('data.0.dependencies_complete', false);

        $second->update(['lifecycle_state' => 'completed']);
        $this->getJson('/api/v1/tasks')->assertOk()
            ->assertJsonPath('data.0.dependencies_complete', true);
    }

    public function test_dependencies_are_optional_and_must_reference_distinct_existing_tasks(): void
    {
        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Standalone task',
        ])->assertCreated()
            ->assertJsonPath('data.dependencies', null)
            ->assertJsonPath('data.dependencies_complete', true);

        $dependency = Task::factory()->create();

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Invalid dependencies',
            'dependency_ids' => [$dependency->id, $dependency->id, 999999],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['dependency_ids.0', 'dependency_ids.1', 'dependency_ids.2']);

        $this->assertDatabaseMissing('tasks', ['title' => 'Invalid dependencies']);
    }
}
