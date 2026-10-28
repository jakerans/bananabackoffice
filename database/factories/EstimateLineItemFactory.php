<?php

namespace Database\Factories;

use App\Enums\LineItemPricing;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateLineItem>
 */
class EstimateLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_option_id' => EstimateOption::factory(),
            'line_item_template_id' => null,
            'name' => 'Sample trip charge',
            'description' => null,
            'pricing' => LineItemPricing::Price,
            'price_cents' => 7500,
            'sort_order' => 0,
        ];
    }
}
