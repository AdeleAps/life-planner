<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_task_details_and_dependencies(): void
    {
        $project = Task::factory()->create(['item_type' => 'project', 'type' => null, 'energy_cost' => null]);
        $dependency = Task::factory()->create(['title' => 'Arrange transport']);
        $task = Task::factory()->create(['title' => 'Old title']);

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'revision' => 1,
            'title' => 'Go hiking',
            'description' => 'Pack lunch',
            'type' => 'evening',
            'project_id' => $project->id,
            'energy_cost' => 'high',
            'priority' => 'high',
            'recurrence_type' => 'one_time',
            'is_multi_session' => true,
            'advance_notice_days' => 7,
            'day_preference' => 'non_workday',
            'day_preference_is_hard' => true,
            'deadline_on' => '2027-05-13',
            'deadline_is_hard' => true,
            'dependency_ids' => [$dependency->id],
        ])->assertOk()
            ->assertJsonPath('data.title', 'Go hiking')
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.dependencies.0.id', $dependency->id)
            ->assertJsonPath('data.revision', 2);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Go hiking',
            'description' => 'Pack lunch',
            'priority' => 'high',
            'energy_cost' => 'high',
            'advance_notice_days' => 7,
            'day_preference' => 'non_workday',
            'day_preference_is_hard' => true,
            'deadline_on' => '2027-05-13',
            'deadline_is_hard' => true,
            'revision' => 2,
        ]);
    }

    public function test_changing_to_a_recurring_day_task_clears_evening_only_values(): void
    {
        $task = Task::factory()->create([
            'is_multi_session' => true,
            'advance_notice_days' => 7,
            'day_preference' => 'workday',
            'day_preference_is_hard' => true,
            'deadline_on' => '2027-05-13',
            'deadline_is_hard' => true,
        ]);

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'revision' => 1,
            'title' => 'Take vitamins',
            'type' => 'day',
            'recurrence_type' => 'schedule',
            'recurrence_rule' => ['unit' => 'day', 'interval' => 1],
            'dependency_ids' => null,
        ])->assertOk()
            ->assertJsonPath('data.type', 'day')
            ->assertJsonPath('data.priority', null)
            ->assertJsonPath('data.energy_cost', null)
            ->assertJsonPath('data.recurrence_rule.unit', 'day')
            ->assertJsonPath('data.is_multi_session', false)
            ->assertJsonPath('data.advance_notice_days', null)
            ->assertJsonPath('data.day_preference', null)
            ->assertJsonPath('data.deadline_on', null)
            ->assertJsonPath('data.revision', 2);
    }

    public function test_a_stale_revision_cannot_overwrite_a_task(): void
    {
        $task = Task::factory()->create(['title' => 'Original', 'revision' => 2]);

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'revision' => 1,
            'title' => 'Stale change',
            'type' => 'evening',
            'recurrence_type' => 'one_time',
        ])->assertStatus(409);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Original', 'revision' => 2]);
    }

    public function test_a_completed_task_cannot_be_edited(): void
    {
        $task = Task::factory()->create(['title' => 'Finished', 'lifecycle_state' => 'completed']);

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'revision' => 1,
            'title' => 'Changed after completion',
            'type' => 'evening',
            'recurrence_type' => 'one_time',
        ])->assertNotFound();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Finished',
            'lifecycle_state' => 'completed',
            'revision' => 1,
        ]);
    }

    public function test_it_rejects_self_and_cyclic_dependencies(): void
    {
        $first = Task::factory()->create();
        $second = Task::factory()->create();
        $first->dependencies()->sync([$second->id]);

        $this->putJson("/api/v1/tasks/{$second->id}", [
            'revision' => 1,
            'title' => $second->title,
            'type' => 'evening',
            'recurrence_type' => 'one_time',
            'dependency_ids' => [$second->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['dependency_ids.0']);

        $this->putJson("/api/v1/tasks/{$second->id}", [
            'revision' => 1,
            'title' => $second->title,
            'type' => 'evening',
            'recurrence_type' => 'one_time',
            'dependency_ids' => [$first->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['dependency_ids']);

        $this->assertDatabaseHas('tasks', ['id' => $second->id, 'revision' => 1]);
        $this->assertDatabaseCount('task_dependencies', 1);
    }
}
