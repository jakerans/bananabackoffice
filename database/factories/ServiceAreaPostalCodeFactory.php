<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceAreaPostalCode>
 */
class ServiceAreaPostalCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'postal_code' => fake()->unique()->numerify('#####'),
        ];
    }
}
