<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_request_id' => (string) Str::uuid(),
            'title' => fake()->sentence(4),
            'description' => null,
            'source' => 'seed',
            'item_type' => 'task',
            'project_id' => null,
            'type' => 'evening',
            'priority' => 'standard',
            'energy_cost' => 'standard',
            'recurrence_type' => 'one_time',
            'recurrence_rule' => null,
            'is_multi_session' => false,
            'deadline_on' => null,
            'deadline_is_hard' => null,
            'lifecycle_state' => 'active',
            'revision' => 1,
        ];
    }
}
