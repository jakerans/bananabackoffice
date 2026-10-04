<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only local development receives data, and all of it is fictitious. Real
     * environments start empty; create the first Owner with backoffice:create-owner.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $this->call(LocalDemoSeeder::class);
    }
}
