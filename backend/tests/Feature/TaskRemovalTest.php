<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_task_from_a_project_keeps_the_task_and_reports_when_the_project_is_empty(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);
        $first = Task::factory()->create(['project_id' => $project->id]);
        $second = Task::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/tasks/{$first->id}/project")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.project_id', null)
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('project_empty', false);

        $this->deleteJson("/api/v1/tasks/{$second->id}/project")
            ->assertOk()
            ->assertJsonPath('project_empty', true);

        $this->assertDatabaseHas('tasks', ['id' => $first->id, 'project_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $second->id, 'project_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $project->id]);
    }

    public function test_deleting_a_task_removes_its_dependencies_and_reports_an_empty_project(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);
        $task = Task::factory()->create(['project_id' => $project->id]);
        $dependent = Task::factory()->create();
        $dependent->dependencies()->sync([$task->id]);

        $this->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('project_id', $project->id)
            ->assertJsonPath('project_empty', true);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('tasks', ['id' => $project->id]);
        $this->assertDatabaseCount('task_dependencies', 0);
    }

    public function test_deleting_a_project_can_keep_its_tasks_as_independent_tasks(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);
        $active = Task::factory()->create(['project_id' => $project->id]);
        $completed = Task::factory()->create(['project_id' => $project->id, 'lifecycle_state' => 'completed']);

        $this->deleteJson("/api/v1/projects/{$project->id}", ['task_action' => 'detach'])
            ->assertOk()
            ->assertJsonPath('task_action', 'detach')
            ->assertJsonPath('affected_tasks', 2);

        $this->assertDatabaseMissing('tasks', ['id' => $project->id]);
        $this->assertDatabaseHas('tasks', ['id' => $active->id, 'project_id' => null, 'revision' => 2]);
        $this->assertDatabaseHas('tasks', ['id' => $completed->id, 'project_id' => null, 'revision' => 2]);
    }

    public function test_deleting_a_project_can_delete_its_tasks_too(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}", ['task_action' => 'delete'])
            ->assertOk()
            ->assertJsonPath('task_action', 'delete')
            ->assertJsonPath('affected_tasks', 1);

        $this->assertDatabaseMissing('tasks', ['id' => $project->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_project_deletion_requires_an_explicit_task_choice(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);
        Task::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task_action']);

        $this->assertDatabaseHas('tasks', ['id' => $project->id]);
    }
}
