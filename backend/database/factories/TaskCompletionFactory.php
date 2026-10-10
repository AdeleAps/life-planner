<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskCompletion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaskCompletion>
 */
class TaskCompletionFactory extends Factory
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
            'client_request_id' => (string) Str::uuid(),
            'completion_type' => 'occurrence',
            'completed_at' => now(),
        ];
    }
}
