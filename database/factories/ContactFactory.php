<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Support\Contact\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state: a fictitious person with a phone and an email.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = '555'.fake()->unique()->numerify('#######');
        $email = fake()->unique()->safeEmail();

        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => $email,
            'email_normalized' => Str::lower($email),
            'phone' => $phone,
            'phone_normalized' => PhoneNumber::normalize($phone),
        ];
    }

    /**
     * Indicate the person's name.
     */
    public function named(string $firstName, ?string $lastName = null): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    /**
     * Indicate that the Contact has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
