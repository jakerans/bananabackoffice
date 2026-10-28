<?php

namespace Database\Factories;

use App\Enums\EstimateType;
use App\Models\Estimate;
use App\Models\Lead;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estimate>
 */
class EstimateFactory extends Factory
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
            'lead_id' => fn (array $attributes): int => Lead::factory()->create(['location_id' => $attributes['location_id']])->id,
            'price_book_id' => null,
            'estimate_type' => EstimateType::ResidentialInterior,
            'hourly_rate_cents' => 5000,
            'tax_name' => null,
            'tax_percent' => null,
            'priced_at' => now(),
            'created_by' => null,
            'created_by_name' => 'Sample Seller',
        ];
    }
}
