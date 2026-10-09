<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const LOCATION_TIMEZONE_MIGRATION = '2026_10_08_120000_add_timezone_to_locations_table';

test('a Location that existed before the timezone migration receives America/New_York', function () {
    $migration = require database_path('migrations/'.LOCATION_TIMEZONE_MIGRATION.'.php');

    // Return to the schema as it was before this migration.
    $migration->down();
    expect(Schema::hasColumn('locations', 'timezone'))->toBeFalse();

    try {
        DB::table('locations')->insert([
            'name' => 'Lakeside Studio',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        expect(Schema::hasColumn('locations', 'timezone'))->toBeTrue();
        expect(DB::table('locations')->where('name', 'Lakeside Studio')->value('timezone'))->toBe('America/New_York');
    } finally {
        // On MySQL these schema changes commit the test transaction, so remove the row explicitly.
        if (! Schema::hasColumn('locations', 'timezone')) {
            $migration->up();
        }

        DB::table('locations')->where('name', 'Lakeside Studio')->delete();
    }
});
