<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\PriceBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceBook>
 */
class PriceBookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => null,
            'hourly_rate_cents' => 5000,
            'estimate_type_hourly_rates' => null,
            'tax_rate_basis_points' => 0,
        ];
    }

    /**
     * A Location's own price book, customized from the default.
     */
    public function forLocation(Location $location): static
    {
        return $this->state(fn (array $attributes) => [
            'location_id' => $location->id,
        ]);
    }
}
