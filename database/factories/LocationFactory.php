<?php

namespace Database\Factories;

use App\Models\Location;
use App\Support\Queue\LocationTimezones;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'is_active' => true,
            'timezone' => LocationTimezones::DEFAULT,
        ];
    }

    /**
     * Indicate that the location is no longer operating.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
