<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ORIGINAL_GRANT_INDEX = 'access_grants_user_id_role_scope_location_id_unique';
const REPLACEMENT_GRANT_INDEX = 'access_grants_user_id_role_location_key_unique';
const DUPLICATE_GRANTS_MIGRATION = '2026_10_05_003716_prevent_duplicate_access_grants';

test('the staff member foreign key has an index of its own', function () {
    // MySQL needs this so that no uniqueness rule is ever the only index backing the foreign key.
    expect(Schema::hasIndex('access_grants', ['user_id']))->toBeTrue();
});

test('the uniqueness rule uses the calculated location key and the original index is gone', function () {
    expect(Schema::hasColumn('access_grants', 'location_key'))->toBeTrue();
    expect(Schema::hasIndex('access_grants', ['user_id', 'role', 'location_key'], 'unique'))->toBeTrue();
    expect(Schema::hasIndex('access_grants', REPLACEMENT_GRANT_INDEX))->toBeTrue();
    expect(Schema::hasIndex('access_grants', ORIGINAL_GRANT_INDEX))->toBeFalse();
});

test('the staff member index migration runs before the uniqueness migration', function () {
    $files = collect(glob(database_path('migrations/*_access_grants*.php')))->map(fn (string $path) => basename($path, '.php'))->sort()->values();

    $index = $files->search(fn (string $name) => str_ends_with($name, 'add_staff_member_index_to_access_grants'));
    $uniqueness = $files->search(fn (string $name) => str_ends_with($name, 'prevent_duplicate_access_grants'));

    expect($index)->not->toBeFalse();
    expect($uniqueness)->not->toBeFalse();
    expect($index)->toBeLessThan($uniqueness);
});

test('the uniqueness migration can be rolled back and applied again', function () {
    $migration = require database_path('migrations/'.DUPLICATE_GRANTS_MIGRATION.'.php');

    $migration->down();

    expect(Schema::hasColumn('access_grants', 'location_key'))->toBeFalse();
    expect(Schema::hasIndex('access_grants', REPLACEMENT_GRANT_INDEX))->toBeFalse();
    expect(Schema::hasIndex('access_grants', ORIGINAL_GRANT_INDEX))->toBeTrue();
    expect(Schema::hasIndex('access_grants', ['user_id']))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('access_grants', 'location_key'))->toBeTrue();
    expect(Schema::hasIndex('access_grants', REPLACEMENT_GRANT_INDEX))->toBeTrue();
    expect(Schema::hasIndex('access_grants', ORIGINAL_GRANT_INDEX))->toBeFalse();
});

test('running the uniqueness migration again after it has finished changes nothing', function () {
    $migration = require database_path('migrations/'.DUPLICATE_GRANTS_MIGRATION.'.php');

    $before = Schema::getIndexes('access_grants');

    $migration->up();

    expect(Schema::getIndexes('access_grants'))->toEqual($before);
});

test('the uniqueness migration finishes the job when an earlier run stopped part way', function (Closure $leaveHalfDone) {
    $migration = require database_path('migrations/'.DUPLICATE_GRANTS_MIGRATION.'.php');

    $migration->down();

    $leaveHalfDone();

    $migration->up();

    expect(Schema::hasColumn('access_grants', 'location_key'))->toBeTrue();
    expect(Schema::hasIndex('access_grants', REPLACEMENT_GRANT_INDEX))->toBeTrue();
    expect(Schema::hasIndex('access_grants', ORIGINAL_GRANT_INDEX))->toBeFalse();
})->with([
    'nothing was done' => [fn () => null],
    'only the calculated column was added' => [function () {
        DB::statement('alter table access_grants add column location_key integer as (coalesce(location_id, 0)) virtual');
    }],
    'the column and the new index were added but the original index was not removed' => [function () {
        DB::statement('alter table access_grants add column location_key integer as (coalesce(location_id, 0)) virtual');
        DB::statement('create unique index '.REPLACEMENT_GRANT_INDEX.' on access_grants (user_id, role, location_key)');
    }],
]);
