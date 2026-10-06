<?php

use App\Models\ProjectSize;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The ID of one of the three project sizes, for filling in a lead form.
 */
function projectSizeId(string $key = ProjectSize::SMALL): int
{
    return ProjectSize::forKey($key)->id;
}

function serviceNamed(string $name): Service
{
    return Service::query()->where('name', $name)->sole();
}

/**
 * A single service, as a lead form holds it: enough to satisfy "at least one service".
 *
 * @return list<string>
 */
function oneService(string $name = 'Interior Painting'): array
{
    return [(string) serviceNamed($name)->id];
}

/**
 * Make every outgoing email fail as it would when the mail server cannot be reached.
 */
function useFailingMailer(): void
{
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection could not be established.');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });

    config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
}

/**
 * Sign in as the Owner and start viewing Backoffice as a staff member, the way
 * the Owner does it: from the staff member's page in Settings.
 */
function impersonate(User $owner, User $staff): void
{
    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('impersonate')
        ->assertRedirect(route('dashboard'));
}

/**
 * The state of the Livewire page in a response, as the browser holds it.
 *
 * @return array<string, mixed>
 */
function livewireSnapshot(TestResponse $response): array
{
    return Utils::extractAttributeDataFromHtml((string) $response->getContent(), 'wire:snapshot');
}

/**
 * Send a Livewire action the way a browser does: a real request through every
 * middleware. Livewire's own test helper skips middleware, and impersonation is
 * applied by middleware, so these tests must not use it once impersonating.
 *
 * @param  array<string, mixed>  $snapshot
 * @param  array<int, mixed>  $params
 * @param  array<string, mixed>  $updates
 */
function sendLivewireAction(array $snapshot, string $method, array $params = [], array $updates = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => $updates,
            'calls' => [['method' => $method, 'params' => $params, 'path' => '']],
        ]],
    ]);
}
