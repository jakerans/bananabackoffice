<?php

namespace Database\Factories;

use App\Enums\AreaShape;
use App\Models\EstimateArea;
use App\Models\EstimateOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateArea>
 */
class EstimateAreaFactory extends Factory
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
            'area_template_id' => null,
            'name' => 'Sample room',
            'shape' => AreaShape::Room,
            'width' => '12',
            'length' => '14',
            'height' => '8',
            'sort_order' => 0,
        ];
    }
}
