<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletedTaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_tasks_are_hidden_by_default_and_included_on_request(): void
    {
        $active = Task::factory()->create(['title' => 'Still to do']);
        $completed = Task::factory()->create(['title' => 'Already done', 'lifecycle_state' => 'completed']);
        Task::factory()->create(['title' => 'Project', 'item_type' => 'project']);

        $this->getJson('/api/v1/tasks')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/v1/tasks?include_completed=1')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $completed->id)
            ->assertJsonPath('data.0.lifecycle_state', 'completed')
            ->assertJsonPath('data.1.id', $active->id);

        $this->getJson('/api/v1/tasks?include_completed=0')->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_completed_tasks_can_be_requested_when_no_active_tasks_remain(): void
    {
        $completed = Task::factory()->create(['lifecycle_state' => 'completed']);

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tasks?include_completed=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id);
    }
}
