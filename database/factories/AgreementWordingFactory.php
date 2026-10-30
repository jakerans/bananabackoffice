<?php

namespace Database\Factories;

use App\Models\AgreementWording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgreementWording>
 */
class AgreementWordingFactory extends Factory
{
    /**
     * Define the model's default state: a short, made-up wording.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => fn (): int => (int) AgreementWording::query()->max('version') + 1,
            'title' => 'CUSTOMER AGREEMENT',
            'body' => implode("\n", [
                '1. SCOPE OF WORK',
                'The Company paints what the accepted estimate lists.',
                '2. PAYMENT TERMS',
                'A deposit is due when the estimate is accepted.',
                '- The balance is due when the work is done.',
            ]),
            'source' => AgreementWording::SOURCE_SETTINGS,
            'created_by' => null,
            'created_by_name' => null,
        ];
    }
}
