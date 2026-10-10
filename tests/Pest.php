<?php

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\CancelAppointment;
use App\Actions\Leads\RecordManualContactAttempt;
use App\Actions\Queue\ClaimNextLead;
use App\Actions\Queue\EnterLeadCadence;
use App\Enums\CallQueue;
use App\Enums\CancellationParty;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\AppointmentCancellationReason;
use App\Models\ContactAttempt;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\ProjectSize;
use App\Models\Property;
use App\Models\Service;
use App\Models\User;
use App\Support\Appointments\BookingResult;
use App\Support\Queue\CallingHours;
use App\Support\Queue\QueueClaim;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportTesting\Testable;
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
 * The page is the first component inside the layout's main area. The header
 * above it holds a component of its own, the notification bell, which is not
 * the page.
 *
 * @return array<string, mixed>
 */
function livewireSnapshot(TestResponse $response): array
{
    $html = (string) $response->getContent();
    $main = strpos($html, '<main');

    return Utils::extractAttributeDataFromHtml($main === false ? $html : substr($html, $main), 'wire:snapshot');
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

/**
 * Put a Lead in the call queue the way capture does, due now unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function enterQueue(Lead $lead, array $overrides = []): LeadCadence
{
    $cadence = app(EnterLeadCadence::class)->handle($lead);

    $cadence->forceFill(['due_at' => now(), ...$overrides])->save();

    return $cadence;
}

/**
 * Press "Next lead" as a staff member.
 */
function nextLead(User $user, CallQueue $queue = CallQueue::Main): QueueClaim
{
    return app(ClaimNextLead::class)->handle($user, $queue);
}

/**
 * Log a contact attempt through the slice 3 action: an outbound phone call
 * that nobody answered, made now, unless told otherwise.
 *
 * @param  array<string, mixed>  $facts
 */
function logAttempt(Lead $lead, User $by, array $facts = []): ContactAttempt
{
    $facts += [
        'submissionId' => Str::uuid()->toString(),
        'channel' => ContactAttemptChannel::Phone,
        'direction' => ContactAttemptDirection::Outbound,
        'occurredAt' => now(),
        'number' => '555-201-3344',
        'durationSeconds' => 20,
        'wasAnswered' => false,
        'wasConnected' => false,
        'disposition' => ContactAttemptDisposition::NoAnswer,
    ];

    if (is_string($facts['occurredAt'])) {
        $facts['occurredAt'] = CarbonImmutable::parse($facts['occurredAt'], 'UTC');
    }

    if (! $facts['channel']->supportsDuration()) {
        $facts['durationSeconds'] = null;
    }

    return app(RecordManualContactAttempt::class)->handle($lead, $by, ...$facts);
}

/**
 * A Lead's queue history as short words, oldest first: "hold_ended:moved".
 *
 * @return list<string>
 */
function queueHistory(Lead $lead): array
{
    return LeadQueueEvent::query()
        ->where('lead_id', $lead->id)
        ->orderBy('id')
        ->get()
        ->map(fn (LeadQueueEvent $event): string => $event->reason === null ? $event->type->value : "{$event->type->value}:{$event->reason}")
        ->all();
}

/**
 * Save a new version of the Contact Center settings, as the settings page does,
 * without going through its permission check.
 *
 * @param  array<string, mixed>  $changes
 */
function saveQueueSettings(array $changes): ContactCenterSettings
{
    $current = ContactCenterSettings::current();

    $next = new ContactCenterSettings;
    $next->forceFill([
        'version' => $current->version + 1,
        'cadence' => $current->cadence,
        'calling_opens_at' => $current->calling_opens_at,
        'calling_closes_at' => $current->calling_closes_at,
        'calling_days' => $current->calling_days,
        'hold_minutes' => $current->hold_minutes,
        'cancelled_release_days' => $current->cancelled_release_days,
        ...$changes,
    ])->save();

    return $next;
}

/**
 * A Lead in the call queue, due now, at a location or unrouted.
 *
 * @param  array<string, mixed>  $cadence
 * @param  array<string, mixed>  $attributes
 */
function dueLead(?Location $location = null, array $cadence = [], array $attributes = []): Lead
{
    $factory = $location === null ? Lead::factory() : Lead::factory()->at($location);
    $lead = $factory->create($attributes);

    enterQueue($lead, $cadence);

    return $lead;
}

/**
 * A Lead an appointment can be booked for (rulebook, rule 1): Qualified, at a
 * Location, and linked to a customer with a property.
 *
 * @param  array<string, mixed>  $attributes
 */
function bookableLead(Location $location, ?User $assignee = null, array $attributes = []): Lead
{
    $account = Account::factory()->withPrimaryContact()->create();
    $property = Property::factory()->of($account)->create();

    $factory = Lead::factory()->at($location)->from($account, $account->primaryContact(), $property)->qualified();

    return ($assignee === null ? $factory : $factory->assignedTo($assignee))->create($attributes);
}

/**
 * Book an appointment through the action, tomorrow at 3:00 pm in the Lead's
 * Location's time for 90 minutes unless told otherwise.
 */
function bookAppointment(Lead $lead, User $salesperson, User $by, ?string $date = null, string $time = '15:00', int $minutes = Appointment::DEFAULT_MINUTES): BookingResult
{
    $timezone = CallingHours::timezoneFor($lead->location);

    return app(BookAppointment::class)->handle($lead, $salesperson, $date ?? now()->timezone($timezone)->addDay()->toDateString(), $time, $minutes, $by);
}

/**
 * The Appointment card of a Lead, as a staff member sees it on the Lead page.
 *
 * @return Testable<Component>
 */
function appointmentCard(User $user, Lead $lead): Testable
{
    return Livewire::actingAs($user)->test('pages::leads.appointment', ['lead' => $lead]);
}

/**
 * Record a cancellation of the Lead's appointment through the action, by the
 * customer for "No longer needed" unless told otherwise.
 *
 * @param  array<string, mixed>  $with  Any of: party, reason, note, disqualifies, disqualifyFor, neverShouldHaveBeenBooked.
 */
function cancelAppointment(Lead $lead, User $by, array $with = []): void
{
    app(CancelAppointment::class)->handle(
        $lead,
        $lead->standingAppointment() ?? Appointment::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail(),
        array_key_exists('party', $with) ? $with['party'] : CancellationParty::Customer->value,
        array_key_exists('reason', $with) ? $with['reason'] : AppointmentCancellationReason::query()->where('name', 'No longer needed')->sole(),
        $with['note'] ?? null,
        $by,
        null,
        $with['disqualifies'] ?? null,
        $with['disqualifyFor'] ?? null,
        $with['neverShouldHaveBeenBooked'] ?? false,
    );
}
