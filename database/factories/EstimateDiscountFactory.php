<?php

namespace Database\Factories;

use App\Enums\DiscountKind;
use App\Models\Estimate;
use App\Models\EstimateDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateDiscount>
 */
class EstimateDiscountFactory extends Factory
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
            'discount_id' => null,
            'name' => 'Sample savings',
            'kind' => DiscountKind::Percentage,
            'value' => '5',
            'sort_order' => 0,
        ];
    }
}
