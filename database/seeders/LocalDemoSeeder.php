<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class LocalDemoSeeder extends Seeder
{
    /**
     * Seed fictitious locations and staff for local development.
     *
     * Every account uses the password "password". Never run outside local development.
     */
    public function run(): void
    {
        // Checked here as well as in DatabaseSeeder, because a seeder can be run by name.
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('The demo seeder creates accounts with a known password and only runs in local development.');
        }

        $northside = Location::factory()->create(['name' => 'Northside']);
        $lakeview = Location::factory()->create(['name' => 'Lakeview']);
        $riverside = Location::factory()->create(['name' => 'Riverside']);

        User::factory()
            ->withRoleEverywhere(Role::Owner)
            ->create(['name' => 'Olive Owner', 'email' => 'owner@example.com']);

        User::factory()
            ->withRoleEverywhere(Role::Leadership)
            ->create(['name' => 'Lee Leadership', 'email' => 'leadership@example.com']);

        User::factory()
            ->withRoleEverywhere(Role::Finance)
            ->create(['name' => 'Finley Finance', 'email' => 'finance@example.com']);

        User::factory()
            ->withRoleEverywhere(Role::CallCenterAgent)
            ->create(['name' => 'Casey Callcenter', 'email' => 'callcenter@example.com']);

        User::factory()
            ->withRoleAt(Role::LocationManager, $northside)
            ->create(['name' => 'Morgan Manager', 'email' => 'manager@example.com']);

        User::factory()
            ->withRoleAt(Role::SalesConsultant, $northside)
            ->withRoleAt(Role::SalesConsultant, $lakeview)
            ->create(['name' => 'Sam Sales', 'email' => 'sales@example.com']);

        User::factory()
            ->withRoleAt(Role::ProductionManager, $riverside)
            ->create(['name' => 'Parker Production', 'email' => 'production@example.com']);
    }
}
