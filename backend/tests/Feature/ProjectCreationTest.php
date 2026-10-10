<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_and_its_steps_keep_their_own_deadlines_and_dependencies(): void
    {
        $prerequisite = Task::factory()->create(['title' => 'Get supplies']);

        $projectResponse = $this->postJson('/api/v1/projects', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Renovate kitchen',
            'priority' => 'high',
            'deadline_on' => '2026-12-31',
            'deadline_is_hard' => true,
            'dependency_ids' => [$prerequisite->id],
        ]);

        $projectResponse->assertCreated()
            ->assertJsonPath('data.item_type', 'project')
            ->assertJsonPath('data.type', null)
            ->assertJsonPath('data.deadline_on', '2026-12-31')
            ->assertJsonPath('data.dependencies.0.id', $prerequisite->id);

        $projectId = $projectResponse->json('data.id');

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Paint cabinets',
            'type' => 'evening',
            'project_id' => $projectId,
            'is_multi_session' => true,
            'deadline_on' => '2026-12-15',
            'dependency_ids' => [$prerequisite->id],
        ])->assertCreated()
            ->assertJsonPath('data.item_type', 'task')
            ->assertJsonPath('data.project_id', $projectId)
            ->assertJsonPath('data.deadline_on', '2026-12-15')
            ->assertJsonPath('data.dependencies.0.id', $prerequisite->id);

        $this->getJson('/api/v1/projects')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projectId);

        $this->getJson('/api/v1/tasks')->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_recurring_tasks_cannot_be_project_steps_but_can_depend_on_a_project(): void
    {
        $project = Task::factory()->create([
            'item_type' => 'project',
            'type' => null,
            'priority' => 'standard',
            'energy_cost' => null,
        ]);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Weekly practice',
            'project_id' => $project->id,
            'recurrence_type' => 'schedule',
            'recurrence_rule' => ['unit' => 'week', 'interval' => 1, 'weekdays' => [1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['project_id']);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Weekly practice',
            'project_id' => null,
            'recurrence_type' => 'schedule',
            'recurrence_rule' => ['unit' => 'week', 'interval' => 1, 'weekdays' => [1]],
            'dependency_ids' => [$project->id],
        ])->assertCreated()
            ->assertJsonPath('data.project_id', null)
            ->assertJsonPath('data.dependencies.0.id', $project->id);

        $this->postJson('/api/v1/tasks', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'Impossible step',
            'project_id' => $project->id,
            'dependency_ids' => [$project->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['dependency_ids.0']);
    }

    public function test_existing_one_time_task_can_be_added_to_a_project_without_changing_its_details(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null, 'energy_cost' => null]);
        $task = Task::factory()->create([
            'title' => 'Finish God of War',
            'description' => 'Continue the story',
            'is_multi_session' => true,
            'deadline_on' => '2026-12-15',
        ]);

        $this->patchJson("/api/v1/tasks/{$task->id}/project", ['project_id' => $project->id])
            ->assertOk()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.title', 'Finish God of War')
            ->assertJsonPath('data.description', 'Continue the story')
            ->assertJsonPath('data.is_multi_session', true)
            ->assertJsonPath('data.deadline_on', '2026-12-15');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'project_id' => $project->id,
            'revision' => 2,
        ]);

        $this->patchJson("/api/v1/tasks/{$task->id}/project", ['project_id' => $project->id])
            ->assertOk();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'revision' => 2]);
    }

    public function test_only_unassigned_one_time_tasks_can_be_added_to_a_project(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null, 'energy_cost' => null]);
        $anotherProject = Task::factory()->create(['item_type' => 'project', 'type' => null, 'energy_cost' => null]);
        $recurringTask = Task::factory()->create(['recurrence_type' => 'schedule', 'recurrence_rule' => ['unit' => 'day', 'interval' => 1]]);
        $assignedTask = Task::factory()->create(['project_id' => $anotherProject->id]);
        $dependentTask = Task::factory()->create();
        $dependentTask->dependencies()->attach($project->id);

        foreach ([$recurringTask, $assignedTask, $dependentTask] as $task) {
            $this->patchJson("/api/v1/tasks/{$task->id}/project", ['project_id' => $project->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['project_id']);

            $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $task->project_id]);
        }
    }
}
