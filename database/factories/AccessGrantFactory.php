<?php

namespace Database\Factories;

use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => Role::SalesConsultant,
            'scope' => GrantScope::Location,
            'location_id' => Location::factory(),
            'granted_by' => null,
        ];
    }

    /**
     * Grant the given role.
     */
    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    /**
     * Scope the grant to a single location.
     */
    public function at(Location $location): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => GrantScope::Location,
            'location_id' => $location->id,
        ]);
    }

    /**
     * Scope the grant to all locations.
     */
    public function everywhere(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => GrantScope::AllLocations,
            'location_id' => null,
        ]);
    }
}
