<?php

namespace Database\Factories;

use App\Enums\EstimateType;
use App\Models\AreaTemplate;
use App\Models\PriceBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AreaTemplate>
 */
class AreaTemplateFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'side' => 'interior',
            'estimate_types' => [EstimateType::ResidentialInterior->value],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
