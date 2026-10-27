<?php

namespace Database\Factories;

use App\Enums\DiscountKind;
use App\Models\Discount;
use App\Models\PriceBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
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
            'name' => fake()->unique()->colorName().' savings',
            'kind' => DiscountKind::Percentage,
            'value' => '5',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
