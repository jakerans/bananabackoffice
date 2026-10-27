<?php

namespace Database\Factories;

use App\Enums\EstimateType;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaintProduct>
 */
class PaintProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_book_id' => PriceBook::factory(),
            'name' => fake()->unique()->colorName().' paint',
            'unit_label' => 'gal',
            'price_cents' => 3000,
            'coverage_area' => '300',
            'estimate_types' => EstimateType::values(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
