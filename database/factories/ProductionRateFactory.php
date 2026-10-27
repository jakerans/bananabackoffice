<?php

namespace Database\Factories;

use App\Enums\EstimateType;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRate>
 */
class ProductionRateFactory extends Factory
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
            'rate_category_id' => null,
            'name' => fake()->unique()->words(3, true),
            'measure' => RateMeasure::WallArea,
            'calculation' => RateCalculation::UnitsPerHour,
            'value_1' => '100',
            'value_2' => '80',
            'value_3' => '60',
            'value_4' => null,
            'default_coats' => 2,
            'uses_coats' => true,
            'estimate_types' => EstimateType::values(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
