<?php

namespace Database\Factories;

use App\Models\PlanningCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningCycle>
 */
class PlanningCycleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_date' => CarbonImmutable::now('Europe/Riga')->addDay()->toDateString(),
            'review_requested_at' => null,
            'review_notification_sent_at' => null,
            'plan_completed_at' => null,
        ];
    }
}
