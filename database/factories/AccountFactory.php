<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\ContactRole;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\AccountContactRole;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state: a household that has no Contact yet.
     *
     * Use "withPrimaryContact" for a household as staff would see one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => AccountType::Residential,
            'display_name' => Account::UNNAMED,
        ];
    }

    /**
     * Indicate that the Account is a business.
     */
    public function commercial(?string $businessName = null): static
    {
        return $this->state(function (array $attributes) use ($businessName) {
            $businessName ??= fake()->company();

            return [
                'type' => AccountType::Commercial,
                'business_name' => $businessName,
                'display_name' => $businessName,
            ];
        });
    }

    /**
     * Give the Account its primary Contact, which names a household.
     *
     * @param  list<ContactRole>  $roles
     */
    public function withPrimaryContact(?Contact $contact = null, array $roles = [ContactRole::Owner]): static
    {
        return $this->connect($contact, $roles, primary: true);
    }

    /**
     * Give the Account a further Contact, with what they are to it.
     *
     * @param  list<ContactRole>  $roles
     */
    public function withContact(?Contact $contact = null, array $roles = [ContactRole::Other]): static
    {
        return $this->connect($contact, $roles, primary: false);
    }

    /**
     * Indicate that the Account has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * @param  list<ContactRole>  $roles
     */
    private function connect(?Contact $contact, array $roles, bool $primary): static
    {
        return $this->afterCreating(function (Account $account) use ($contact, $roles, $primary): void {
            $link = new AccountContact;

            $link->forceFill([
                'account_id' => $account->id,
                'contact_id' => ($contact ?? Contact::factory()->create())->id,
                'is_primary' => $primary,
            ])->save();

            foreach ($roles as $role) {
                (new AccountContactRole)->forceFill(['account_contact_id' => $link->id, 'role' => $role])->save();
            }

            $account->refreshDisplayName();
        });
    }
}
