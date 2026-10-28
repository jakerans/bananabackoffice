<?php

namespace Database\Factories;

use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\EstimateArea;
use App\Models\EstimateSurface;
use App\Support\Pricing\PaintSnapshot;
use App\Support\Pricing\RateSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateSurface>
 */
class EstimateSurfaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_area_id' => EstimateArea::factory(),
            'production_rate_id' => null,
            'paint_product_id' => null,
            'rate' => (new RateSnapshot('Sample walls', RateMeasure::WallArea, RateCalculation::UnitsPerHour, ['150', '90', '60', null], usesCoats: true))->toArray(),
            'paint' => (new PaintSnapshot('Sample paint', 3050, '250', '400', '0.2'))->toArray(),
            'coats' => 1,
            'quantity' => '1',
            'prep_hours' => null,
            'sort_order' => 0,
        ];
    }
}
