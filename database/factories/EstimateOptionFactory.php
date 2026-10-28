<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateOption>
 */
class EstimateOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'name' => 'Option 1',
            'description' => null,
            'sort_order' => 0,
            'total_cents' => 0,
        ];
    }
}
