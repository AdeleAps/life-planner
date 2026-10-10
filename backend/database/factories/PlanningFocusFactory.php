<?php

namespace Database\Factories;

use App\Models\PlanningFocus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningFocus>
 */
class PlanningFocusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'week',
            'starts_on' => CarbonImmutable::now('Europe/Riga')->startOfWeek()->toDateString(),
            'focus' => fake()->sentence(),
            'prompted_at' => now(),
        ];
    }
}
