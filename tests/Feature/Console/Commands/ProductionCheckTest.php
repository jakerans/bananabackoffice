<?php

use App\Enums\Role;
use App\Models\AgreementWording;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\SchedulerHeartbeat;
use App\Models\ServiceAreaPostalCode;
use App\Models\TaxRate;
use App\Models\User;

/*
 * backoffice:production-check, step 30a (docs/slice-30-production-readiness-plan.md):
 * whether a server is safe for real data.
 */

/**
 * The settings of a server set up for real data, apart from its database.
 */
function productionSettings(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://backoffice.bananapainting.com',
        'session.secure' => true,
        'session.driver' => 'database',
        'auth.two_factor_required' => true,
        'mail.default' => 'resend',
        'services.resend.key' => 're_test_not_a_real_key_7f3a',
        'mail.from.address' => 'backoffice@mail.bananapainting.com',
        'filesystems.disks.files' => ['driver' => 's3', 'bucket' => 'banana-backoffice', 'key' => 'files-key-id-4b1c', 'secret' => 'files-secret-9d2e'],
        'services.quickbooks.environment' => 'production',
        'services.quickbooks.client_id' => 'qb-client-id-5e6f',
        'services.quickbooks.client_secret' => 'qb-client-secret-8a9b',
    ]);

    SchedulerHeartbeat::query()->forceCreate(['name' => SchedulerHeartbeat::QUEUE_TICK, 'last_ran_at' => now()]);
}

test('a server set up like a developer\'s computer is not ready, and each fix is named', function () {
    config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://backoffice.test', 'auth.two_factor_required' => false, 'mail.default' => 'log', 'services.quickbooks.environment' => 'sandbox']);

    $this->artisan('backoffice:production-check')
        ->expectsOutputToContain('Set APP_ENV=production.')
        ->expectsOutputToContain('Set APP_DEBUG=false')
        ->expectsOutputToContain('Set APP_URL to the https:// address')
        ->expectsOutputToContain('Remove AUTH_REQUIRE_TWO_FACTOR')
        ->expectsOutputToContain('Add the scheduler in Forge')
        ->expectsOutputToContain('Set MAIL_MAILER=resend')
        ->expectsOutputToContain('Set FILES_DRIVER=s3')
        ->expectsOutputToContain('Set QUICKBOOKS_ENVIRONMENT=production')
        ->expectsOutputToContain('Not ready for real data')
        ->assertFailed();
});

test('a server set up for real data passes every setting but its database, and never prints a key', function () {
    productionSettings();

    $this->artisan('backoffice:production-check')
        ->doesntExpectOutputToContain('Set APP_ENV=production.')
        ->doesntExpectOutputToContain('Set APP_DEBUG=false')
        ->doesntExpectOutputToContain('Set APP_URL')
        ->doesntExpectOutputToContain('Set SESSION_SECURE_COOKIE')
        ->doesntExpectOutputToContain('Set SESSION_DRIVER')
        ->doesntExpectOutputToContain('Remove AUTH_REQUIRE_TWO_FACTOR')
        ->doesntExpectOutputToContain('Add the scheduler')
        ->doesntExpectOutputToContain('Set MAIL_MAILER')
        ->doesntExpectOutputToContain('Set FILES_DRIVER')
        ->doesntExpectOutputToContain('Set QUICKBOOKS_ENVIRONMENT')
        ->doesntExpectOutputToContain('re_test_not_a_real_key_7f3a')
        ->doesntExpectOutputToContain('files-secret-9d2e')
        ->doesntExpectOutputToContain('qb-client-secret-8a9b')
        ->expectsOutputToContain('Database backups run every hour')
        ->expectsOutputToContain('The restore drill has passed');
});

test('staging\'s bucket, a stopped scheduler or another sending domain are not ready', function () {
    productionSettings();
    config([
        'filesystems.disks.files.bucket' => 'backoffice-staging',
        'mail.from.address' => 'backoffice@bananapainting.com',
    ]);
    SchedulerHeartbeat::query()->update(['last_ran_at' => now()->subMinutes(10)]);

    $this->artisan('backoffice:production-check')
        ->expectsOutputToContain('Set FILES_DRIVER=s3, the production bucket')
        ->expectsOutputToContain('Add the scheduler in Forge')
        ->expectsOutputToContain('Set MAIL_MAILER=resend')
        ->assertFailed();
});

test('the copy\'s clean-up and customer email are named until they are set, and their keys are never printed', function () {
    productionSettings();

    $this->artisan('backoffice:production-check')
        ->expectsOutputToContain('Set FILES_COPY_BUCKET')
        ->expectsOutputToContain('Set the MAIL_CUSTOMERS_ settings')
        ->assertFailed();

    config([
        'filesystems.disks.files_copy' => ['bucket' => 'made-up-copy-bucket', 'key' => 'copy-key-id-1a2b', 'secret' => 'copy-secret-3c4d'],
        'mail.customers.enabled' => true,
        'mail.customers.to_anyone' => true,
        'mail.customers.from_address' => 'hello@email.bananapainting.com',
        'mail.mailers.customers.transport' => 'resend',
        'mail.mailers.customers.key' => 're_customers_not_a_real_key_2b4d',
    ]);

    $this->artisan('backoffice:production-check')
        ->doesntExpectOutputToContain('Set FILES_COPY_BUCKET')
        ->doesntExpectOutputToContain('Set the MAIL_CUSTOMERS_ settings')
        ->doesntExpectOutputToContain('copy-secret-3c4d')
        ->doesntExpectOutputToContain('re_customers_not_a_real_key_2b4d');
});

test('customer email from the staff sending domain, or not to anyone, is not ready', function (array $settings) {
    productionSettings();
    config([
        'mail.customers.enabled' => true,
        'mail.customers.to_anyone' => true,
        'mail.customers.from_address' => 'hello@email.bananapainting.com',
        'mail.mailers.customers.transport' => 'resend',
        'mail.mailers.customers.key' => 're_customers_not_a_real_key_2b4d',
        ...$settings,
    ]);

    $this->artisan('backoffice:production-check')->expectsOutputToContain('Set the MAIL_CUSTOMERS_ settings');
})->with([
    'the staff domain' => [['mail.customers.from_address' => 'hello@mail.bananapainting.com']],
    'only test addresses' => [['mail.customers.to_anyone' => false]],
]);

test('a new server is told what it has nothing of yet, and not what it has', function () {
    productionSettings();

    $this->artisan('backoffice:production-check')
        ->expectsOutputToContain('backoffice:create-owner')
        ->expectsOutputToContain('Add one in Company > Locations')
        ->expectsOutputToContain('Add its ZIP codes')
        ->expectsOutputToContain('backoffice:pricing-import')
        ->expectsOutputToContain('Add one in Settings > Pricing Settings')
        ->expectsOutputToContain('backoffice:agreement-import')
        ->expectsOutputToContain('pay plan in Finance > Payroll')
        ->assertFailed();

    User::factory()->withRoleEverywhere(Role::Owner)->create();
    $location = Location::factory()->create();
    ServiceAreaPostalCode::factory()->create(['location_id' => $location->id]);
    $book = PriceBook::factory()->create(['location_id' => null]);
    ProductionRate::factory()->create(['price_book_id' => $book->id]);
    TaxRate::factory()->create(['price_book_id' => $book->id]);
    AgreementWording::factory()->create();

    $this->artisan('backoffice:production-check')
        ->doesntExpectOutputToContain('backoffice:create-owner')
        ->doesntExpectOutputToContain('Add one in Company > Locations')
        ->doesntExpectOutputToContain('Add its ZIP codes')
        ->doesntExpectOutputToContain('backoffice:pricing-import')
        ->doesntExpectOutputToContain('Add one in Settings > Pricing Settings')
        ->doesntExpectOutputToContain('backoffice:agreement-import');
});
