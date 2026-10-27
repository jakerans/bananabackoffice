<?php

use App\Models\SchedulerHeartbeat;

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
