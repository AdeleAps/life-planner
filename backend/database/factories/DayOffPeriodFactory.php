<?php

namespace Database\Factories;

use App\Models\DayOffPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DayOffPeriod>
 */
class DayOffPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'personal',
            'title' => 'Vacation',
            'start_on' => today('Europe/Riga')->addWeek()->toDateString(),
            'end_on' => today('Europe/Riga')->addWeek()->toDateString(),
            'source_key' => null,
            'goon_mode' => false,
        ];
    }
}
