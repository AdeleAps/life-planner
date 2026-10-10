<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_project_details_without_changing_its_tasks(): void
    {
        $project = Task::factory()->create([
            'item_type' => 'project',
            'type' => null,
            'priority' => 'standard',
            'energy_cost' => null,
        ]);
        $step = Task::factory()->create(['project_id' => $project->id]);
        $prerequisite = Task::factory()->create();

        $this->putJson("/api/v1/projects/{$project->id}", [
            'revision' => 1,
            'title' => 'Renovate kitchen',
            'description' => 'Start with the cabinets',
            'priority' => 'high',
            'deadline_on' => '2027-05-13',
            'deadline_is_hard' => true,
            'dependency_ids' => [$prerequisite->id],
        ])->assertOk()
            ->assertJsonPath('data.title', 'Renovate kitchen')
            ->assertJsonPath('data.dependencies.0.id', $prerequisite->id)
            ->assertJsonPath('data.steps_count', 1)
            ->assertJsonPath('data.revision', 2);

        $this->assertDatabaseHas('tasks', [
            'id' => $project->id,
            'priority' => 'high',
            'deadline_on' => '2027-05-13',
            'deadline_is_hard' => true,
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('tasks', ['id' => $step->id, 'project_id' => $project->id, 'revision' => 1]);
    }

    public function test_it_can_clear_project_options_and_dependencies(): void
    {
        $project = Task::factory()->create([
            'item_type' => 'project',
            'type' => null,
            'priority' => 'high',
            'energy_cost' => null,
            'deadline_on' => '2027-05-13',
            'deadline_is_hard' => true,
            'description' => 'Old notes',
        ]);
        $dependency = Task::factory()->create();
        $project->dependencies()->sync([$dependency->id]);

        $this->putJson("/api/v1/projects/{$project->id}", [
            'revision' => 1,
            'title' => $project->title,
            'description' => null,
            'priority' => 'standard',
            'dependency_ids' => null,
        ])->assertOk()
            ->assertJsonPath('data.deadline_on', null)
            ->assertJsonPath('data.deadline_is_hard', null)
            ->assertJsonPath('data.dependencies', null);

        $this->assertDatabaseHas('tasks', [
            'id' => $project->id,
            'description' => null,
            'priority' => 'standard',
            'deadline_on' => null,
            'deadline_is_hard' => null,
        ]);
        $this->assertDatabaseCount('task_dependencies', 0);
    }

    public function test_stale_and_completed_projects_cannot_be_edited(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null, 'revision' => 2]);
        $completed = Task::factory()->create(['item_type' => 'project', 'type' => null, 'lifecycle_state' => 'completed']);

        $this->putJson("/api/v1/projects/{$project->id}", [
            'revision' => 1,
            'title' => 'Stale change',
        ])->assertStatus(409);

        $this->putJson("/api/v1/projects/{$completed->id}", [
            'revision' => 1,
            'title' => 'Completed change',
        ])->assertNotFound();

        $this->assertDatabaseHas('tasks', ['id' => $project->id, 'title' => $project->title, 'revision' => 2]);
        $this->assertDatabaseHas('tasks', ['id' => $completed->id, 'title' => $completed->title]);
    }

    public function test_it_rejects_dependencies_on_itself_or_its_steps(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null]);
        $step = Task::factory()->create(['project_id' => $project->id]);
        $prerequisite = Task::factory()->create();
        $prerequisite->dependencies()->sync([$project->id]);

        $this->putJson("/api/v1/projects/{$project->id}", [
            'revision' => 1,
            'title' => $project->title,
            'dependency_ids' => [$project->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['dependency_ids.0']);

        foreach ([$step->id, $prerequisite->id] as $dependencyId) {
            $this->putJson("/api/v1/projects/{$project->id}", [
                'revision' => 1,
                'title' => $project->title,
                'dependency_ids' => [$dependencyId],
            ])->assertUnprocessable()->assertJsonValidationErrors(['dependency_ids']);
        }

        $this->assertDatabaseHas('tasks', ['id' => $project->id, 'revision' => 1]);
        $this->assertDatabaseCount('task_dependencies', 1);
    }
}
