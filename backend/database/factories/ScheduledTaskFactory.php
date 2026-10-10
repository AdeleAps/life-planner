<?php

namespace Database\Factories;

use App\Models\ScheduledTask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTask>
 */
class ScheduledTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'plan_date' => today()->toDateString(),
            'status' => 'planned',
            'source' => 'planner',
        ];
    }
}
