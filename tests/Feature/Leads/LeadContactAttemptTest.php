<?php

use App\Actions\Leads\RecordManualContactAttempt;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\ContactAttemptLifecycleResult;
use App\Enums\ContactAttemptSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\Impersonation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('lead staff record a clearly manual attempt without changing lifecycle state', function () {
    $this->travelTo('2026-10-07 20:00:00');

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $account = Account::factory()->create();
    $contact = Contact::factory()->create(['first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '555-201-3344']);
    $lead = Lead::factory()->at($lakeview)->from($account, $contact)->assignedTo($consultant)->working()->create();
    $statusChangesBefore = LeadStatusChange::query()->count();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptOccurredAt', '2026-10-07T14:30')
        ->set('attemptChannel', ContactAttemptChannel::Phone->value)
        ->set('attemptDirection', ContactAttemptDirection::Outbound->value)
        ->set('attemptNumber', '555.201.3344')
        ->set('attemptDurationSeconds', 125)
        ->set('attemptDisposition', ContactAttemptDisposition::Connected->value)
        ->call('recordContactAttempt')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Connected', 'Manual', 'Outbound Phone'])
        ->assertSee('Yes — manually reported')
        ->assertSee('No lifecycle change');

    $attempt = ContactAttempt::query()->sole();

    expect($attempt->lead->is($lead))->toBeTrue();
    expect($attempt->contact->is($contact))->toBeTrue();
    expect($attempt->location->is($lakeview))->toBeTrue();
    expect($attempt->responsibleUser->is($consultant))->toBeTrue();
    expect($attempt->responsible_user_name)->toBe('Sam Sales');
    expect($attempt->source)->toBe(ContactAttemptSource::Manual);
    expect($attempt->channel)->toBe(ContactAttemptChannel::Phone);
    expect($attempt->direction)->toBe(ContactAttemptDirection::Outbound);
    expect($attempt->number)->toBe('555.201.3344');
    expect($attempt->number_normalized)->toBe('+15552013344');
    expect($attempt->duration_seconds)->toBe(125);
    expect($attempt->was_answered)->toBeTrue();
    expect($attempt->was_connected)->toBeTrue();
    expect($attempt->lead_status)->toBe(LeadStatus::Working);
    expect($attempt->lifecycle_result)->toBe(ContactAttemptLifecycleResult::NoChange);
    expect($attempt->recording_reference)->toBeNull();
    expect($attempt->transcript_reference)->toBeNull();

    $unchanged = $lead->fresh();

    expect($unchanged->status)->toBe(LeadStatus::Working);
    expect($unchanged->assigned_user_id)->toBe($consultant->id);
    expect($unchanged->lifecycle_version)->toBe((int) $lead->lifecycle_version);
    expect(LeadStatusChange::query()->count())->toBe($statusChangesBefore);
});

test('a retried submission is recorded once while a fresh form may record the same facts again', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptOccurredAt', now()->subMinute()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'))
        ->set('attemptNumber', '555-201-3344')
        ->set('attemptDurationSeconds', 30);

    $firstSubmissionId = $page->get('attemptSubmissionId');

    $page->call('recordContactAttempt')->assertHasNoErrors();
    $page->call('recordContactAttempt')->assertHasNoErrors();

    expect(ContactAttempt::query()->count())->toBe(1);

    $page->call('openContactAttempt');

    expect($page->get('attemptSubmissionId'))->not->toBe($firstSubmissionId);

    $page->set('attemptOccurredAt', now()->subMinute()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'))
        ->set('attemptNumber', '555-201-3344')
        ->set('attemptDurationSeconds', 30)
        ->call('recordContactAttempt')
        ->assertHasNoErrors();

    expect(ContactAttempt::query()->count())->toBe(2);
});

test('a replay token rejects changed facts or a different recording actor', function () {
    $lakeview = Location::factory()->create();
    $firstConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $secondConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $submissionId = Str::uuid()->toString();
    $occurredAt = now()->subMinute();
    $action = app(RecordManualContactAttempt::class);

    $action->handle(
        $lead,
        $firstConsultant,
        $submissionId,
        ContactAttemptChannel::Phone,
        ContactAttemptDirection::Outbound,
        $occurredAt,
        '555-201-3344',
        30,
        false,
        false,
        ContactAttemptDisposition::NoAnswer,
    );

    expect(fn () => $action->handle(
        $lead,
        $firstConsultant,
        $submissionId,
        ContactAttemptChannel::Phone,
        ContactAttemptDirection::Outbound,
        $occurredAt,
        '555-201-3344',
        31,
        false,
        false,
        ContactAttemptDisposition::NoAnswer,
    ))->toThrow(ValidationException::class, 'contact attempt form is stale');

    expect(fn () => $action->handle(
        $lead,
        $secondConsultant,
        $submissionId,
        ContactAttemptChannel::Phone,
        ContactAttemptDirection::Outbound,
        $occurredAt,
        '555-201-3344',
        30,
        false,
        false,
        ContactAttemptDisposition::NoAnswer,
    ))->toThrow(ValidationException::class, 'contact attempt form is stale');

    expect(ContactAttempt::query()->count())->toBe(1);
});

test('channel changes clear incompatible disposition facts and the action rejects tampering', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptChannel', ContactAttemptChannel::Email->value)
        ->assertSet('attemptDisposition', ContactAttemptDisposition::MessageSent->value)
        ->assertSee('Message sent')
        ->assertDontSee('Voicemail left')
        ->set('attemptDisposition', ContactAttemptDisposition::VoicemailLeft->value)
        ->call('recordContactAttempt')
        ->assertHasErrors([
            'attemptDisposition' => 'The selected disposition is not available for this channel.',
        ]);

    $page->set('attemptDisposition', ContactAttemptDisposition::MessageSent->value)
        ->call('recordContactAttempt')
        ->assertHasNoErrors();

    expect(ContactAttempt::query()->sole()->channel)->toBe(ContactAttemptChannel::Email);
    expect(ContactAttempt::query()->sole()->disposition)->toBe(ContactAttemptDisposition::MessageSent);
});

test('phone attempts reject a sent-message disposition', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptOccurredAt', now()->subMinute()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'))
        ->set('attemptNumber', '555-201-3344')
        ->set('attemptDurationSeconds', 30)
        ->set('attemptDisposition', ContactAttemptDisposition::MessageSent->value)
        ->call('recordContactAttempt')
        ->assertHasErrors([
            'attemptDisposition' => 'The selected disposition is not available for this channel.',
        ]);

    expect(ContactAttempt::query()->count())->toBe(0);
});

test('manual attempt input rejects false connection evidence and invalid channel facts', function (array $updates, string $errorKey, string $message) {
    $this->travelTo('2026-10-07 20:00:00');

    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptOccurredAt', '2026-10-07T14:30')
        ->set('attemptNumber', '555-201-3344')
        ->set('attemptDurationSeconds', 30);

    foreach ($updates as $property => $value) {
        $page->set($property, $value);
    }

    $page->call('recordContactAttempt')->assertHasErrors([$errorKey => $message]);

    expect(ContactAttempt::query()->count())->toBe(0);
})->with([
    'connected cannot be unanswered' => [[
        'attemptDisposition' => ContactAttemptDisposition::Other->value,
        'attemptAnswered' => false,
        'attemptConnected' => true,
    ], 'attemptConnected', 'A two-way connection must also be marked answered.'],
    'voicemail is not a two-way connection' => [[
        'attemptDisposition' => ContactAttemptDisposition::VoicemailLeft->value,
        'attemptAnswered' => false,
        'attemptConnected' => true,
    ], 'attemptConnected', 'The connection status does not match the selected disposition.'],
    'sent message is not answered' => [[
        'attemptDisposition' => ContactAttemptDisposition::MessageSent->value,
        'attemptAnswered' => true,
        'attemptConnected' => false,
    ], 'attemptAnswered', 'The answered status does not match the selected disposition.'],
    'email cannot claim call duration' => [[
        'attemptChannel' => ContactAttemptChannel::Email->value,
        'attemptDurationSeconds' => 30,
        'attemptDisposition' => ContactAttemptDisposition::NoAnswer->value,
    ], 'attemptDurationSeconds', 'Duration is recorded only for phone calls.'],
]);

test('manual attempt input rejects a future event time and missing phone number', function () {
    $this->travelTo('2026-10-07 20:00:00');

    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptOccurredAt', '2026-10-08T14:30')
        ->set('attemptNumber', '')
        ->set('attemptDurationSeconds', 0)
        ->call('recordContactAttempt')
        ->assertHasErrors([
            'attemptOccurredAt' => 'The attempt time cannot be in the future.',
            'attemptNumber' => 'Enter the phone number used for this attempt.',
        ]);

    expect(ContactAttempt::query()->count())->toBe(0);
});

test('manual attempt input rejects tampered enum values', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt')
        ->set('attemptChannel', 'provider_verified')
        ->set('attemptDirection', 'sideways')
        ->set('attemptDisposition', 'sale_closed')
        ->call('recordContactAttempt')
        ->assertHasErrors(['attemptChannel', 'attemptDirection', 'attemptDisposition']);

    expect(ContactAttempt::query()->count())->toBe(0);
});

test('an open attempt form cannot be submitted after location access is removed', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openContactAttempt');

    $consultant->accessGrants()->delete();
    $consultant->refresh();

    $page->set('attemptOccurredAt', now()->subMinute()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'))
        ->set('attemptNumber', '555-201-3344')
        ->set('attemptDurationSeconds', 30)
        ->call('recordContactAttempt')
        ->assertForbidden();

    expect(ContactAttempt::query()->count())->toBe(0);
});

test('the recording action rechecks current location authorization', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($northside)->create();

    expect(fn () => app(RecordManualContactAttempt::class)->handle(
        $lead,
        $consultant,
        Str::uuid()->toString(),
        ContactAttemptChannel::Phone,
        ContactAttemptDirection::Outbound,
        now()->subMinute(),
        '555-201-3344',
        30,
        false,
        false,
        ContactAttemptDisposition::NoAnswer,
    ))->toThrow(AuthorizationException::class);

    expect(ContactAttempt::query()->count())->toBe(0);
});

test('attempt history hides rows from locations the current viewer cannot access', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $lakeviewConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Old Lakeview Rep']);
    $northsideConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create(['name' => 'Current Northside Rep']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    ContactAttempt::factory()->forLead($lead)->by($lakeviewConsultant)->create([
        'disposition' => ContactAttemptDisposition::VoicemailLeft,
        'was_answered' => false,
        'was_connected' => false,
    ]);

    $lead->forceFill(['location_id' => $northside->id])->save();

    ContactAttempt::factory()->forLead($lead)->by($northsideConsultant)->create([
        'disposition' => ContactAttemptDisposition::Connected,
        'was_answered' => true,
        'was_connected' => true,
    ]);

    $this->actingAs($northsideConsultant)
        ->get(route('leads.show', $lead))
        ->assertSee('Connected')
        ->assertSee('Current Northside Rep')
        ->assertDontSee('Old Lakeview Rep')
        ->assertDontSee('Lakeview');

    $this->actingAs($owner)
        ->get(route('leads.show', $lead))
        ->assertSeeInOrder(['Current Northside Rep', 'Old Lakeview Rep'])
        ->assertSee('Lakeview')
        ->assertSee('Northside');
});

test('historical contact identity is shown only while the recorded customer remains visible', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->create();
    $contact = Contact::factory()->create(['first_name' => 'Historical', 'last_name' => 'Contact']);
    $lead = Lead::factory()->at($lakeview)->from($account, $contact)->create();

    ContactAttempt::factory()->forLead($lead)->by($consultant)->create();

    $lead->forceFill(['account_id' => null, 'contact_id' => null, 'property_id' => null])->save();

    $this->actingAs($consultant)
        ->get(route('leads.show', $lead))
        ->assertSee('Contact attempts')
        ->assertDontSee('Historical Contact');

    $this->actingAs($owner)
        ->get(route('leads.show', $lead))
        ->assertSee('Historical Contact');
});

test('attempt history is ordered by actual event time and then newest record', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    ContactAttempt::factory()->forLead($lead)->by($consultant)->create([
        'occurred_at' => '2026-10-07 14:00:00',
        'disposition' => ContactAttemptDisposition::NoAnswer,
        'was_answered' => false,
        'was_connected' => false,
    ]);
    ContactAttempt::factory()->forLead($lead)->by($consultant)->create([
        'occurred_at' => '2026-10-07 15:00:00',
        'disposition' => ContactAttemptDisposition::VoicemailLeft,
        'was_answered' => false,
        'was_connected' => false,
    ]);
    ContactAttempt::factory()->forLead($lead)->by($consultant)->create([
        'occurred_at' => '2026-10-07 15:00:00',
        'disposition' => ContactAttemptDisposition::WrongParty,
        'was_answered' => true,
        'was_connected' => false,
    ]);

    $this->actingAs($consultant)
        ->get(route('leads.show', $lead))
        ->assertSeeInOrder(['Wrong party', 'Voicemail left', 'No answer']);
});

test('attempt records are immutable', function () {
    $attempt = ContactAttempt::factory()->create();

    expect(fn () => $attempt->forceFill(['disposition' => ContactAttemptDisposition::NoAnswer])->save())
        ->toThrow(LogicException::class, 'cannot be changed');

    $attempt->refresh();

    expect(fn () => $attempt->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');

    expect(ContactAttempt::query()->count())->toBe(1);
});

test('an impersonated manual attempt preserves both identities without changing its source', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $lead = Lead::factory()->at($lakeview)->create();

    app(Impersonation::class)->serveAs($owner, $consultant);

    try {
        app(RecordManualContactAttempt::class)->handle(
            $lead,
            $consultant,
            Str::uuid()->toString(),
            ContactAttemptChannel::Sms,
            ContactAttemptDirection::Inbound,
            CarbonImmutable::now()->subMinute(),
            '555-201-3344',
            null,
            true,
            true,
            ContactAttemptDisposition::Connected,
        );
    } finally {
        app(Impersonation::class)->forgetRequest();
    }

    $attempt = ContactAttempt::query()->sole();

    expect($attempt->responsible_user_name)->toBe('Roseanne Smith');
    expect($attempt->impersonator_name)->toBe('Jacob Ransom');
    expect($attempt->recordedBy())->toBe('Jacob Ransom, impersonating Roseanne Smith');
    expect($attempt->source)->toBe(ContactAttemptSource::Manual);
});
