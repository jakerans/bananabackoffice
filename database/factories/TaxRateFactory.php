<?php

namespace Database\Factories;

use App\Models\PriceBook;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
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
            'name' => fake()->unique()->city().' tax',
            'percent' => '5.5',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
