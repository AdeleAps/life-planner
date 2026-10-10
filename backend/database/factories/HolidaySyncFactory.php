<?php

namespace Database\Factories;

use App\Models\HolidaySync;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HolidaySync>
 */
class HolidaySyncFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => fake()->unique()->numberBetween(2026, 2031),
            'provider' => 'nager-v4',
            'synced_at' => now(),
        ];
    }
}
