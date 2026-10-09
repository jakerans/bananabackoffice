<?php

use App\Actions\Queue\AdvanceLeadCadence;
use App\Enums\CadenceState;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\ContactAttemptLifecycleResult;
use App\Enums\QueueEventType;
use App\Enums\Role;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Support\Queue\QueueHistory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * What a logged contact attempt does to the cadence (docs/slice-4-plan.md,
 * contract 4; decision 67). The clock is Wednesday 7 October 2026, 10:00 am
 * Eastern, unless a test moves it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->lead = Lead::factory()->at($this->lakeview)->assignedTo($this->consultant)->working()->create();
    $this->cadence = enterQueue($this->lead);
});

/**
 * Everything stored on the Lead's own row, to prove an attempt leaves it byte for byte.
 *
 * @return array<string, mixed>
 */
function leadRow(Lead $lead): array
{
    return (array) DB::table('leads')->where('id', $lead->id)->first();
}

test('an outbound call that did not connect counts and schedules the next attempt', function (array $facts) {
    $before = leadRow($this->lead);
    $statusChanges = LeadStatusChange::query()->count();

    $attempt = logAttempt($this->lead, $this->consultant, $facts);

    expect($attempt->cadence_cycle)->toBe(1);
    expect($attempt->cadence_step)->toBe(1);
    expect($attempt->lifecycle_result)->toBe(ContactAttemptLifecycleResult::NoChange);

    $cadence = $this->cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->attempts_made)->toBe(1);
    expect($cadence->last_counted_attempt_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    // Day 1, second attempt: 30 minutes after the first.
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 14:30:00');

    expect(queueHistory($this->lead))->toBe(['entered', 'attempt_counted']);

    $event = LeadQueueEvent::query()->where('type', QueueEventType::AttemptCounted)->sole();

    expect($event->step)->toBe(1);
    expect($event->cycle)->toBe(1);
    expect($event->contact_attempt_id)->toBe($attempt->id);
    expect($event->actor_id)->toBe($this->consultant->id);
    expect($event->location_id)->toBe($this->lakeview->id);

    expect(leadRow($this->lead))->toBe($before);
    expect(LeadStatusChange::query()->count())->toBe($statusChanges);
})->with([
    'no answer' => [[]],
    'voicemail left' => [['disposition' => ContactAttemptDisposition::VoicemailLeft]],
    'wrong party' => [['disposition' => ContactAttemptDisposition::WrongParty, 'wasAnswered' => true]],
    'other, not connected' => [['disposition' => ContactAttemptDisposition::Other]],
]);

test('a real two-way connection ends the cadence, on any channel and in either direction', function (array $facts) {
    $before = leadRow($this->lead);
    $statusChanges = LeadStatusChange::query()->count();

    $attempt = logAttempt($this->lead, $this->consultant, ['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected, ...$facts]);

    expect($attempt->cadence_cycle)->toBeNull();
    expect($attempt->cadence_step)->toBeNull();

    $cadence = $this->cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::Connected);
    expect($cadence->due_at)->toBeNull();
    expect($cadence->ended_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect($cadence->attempts_made)->toBe(0);

    expect(queueHistory($this->lead))->toBe(['entered', 'ended:connected']);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Ended)->sole()->contact_attempt_id)->toBe($attempt->id);

    expect(leadRow($this->lead))->toBe($before);
    expect(LeadStatusChange::query()->count())->toBe($statusChanges);
})->with([
    'an outbound call' => [[]],
    'an inbound call' => [['direction' => ContactAttemptDirection::Inbound]],
    'an inbound text' => [['channel' => ContactAttemptChannel::Sms, 'direction' => ContactAttemptDirection::Inbound]],
    'an outbound email' => [['channel' => ContactAttemptChannel::Email, 'number' => null]],
]);

test('anything else is evidence only and leaves the cadence exactly as it was', function (array $facts) {
    // One counted attempt already, made at 10:00 am.
    logAttempt($this->lead, $this->consultant);

    $this->travelTo('2026-10-07 14:10:00');

    $before = leadRow($this->lead);
    $cadenceBefore = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();
    $statusChanges = LeadStatusChange::query()->count();

    $attempt = logAttempt($this->lead, $this->consultant, $facts);

    expect($attempt->exists)->toBeTrue();
    expect($attempt->cadence_cycle)->toBeNull();
    expect($attempt->cadence_step)->toBeNull();

    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($cadenceBefore);
    expect(queueHistory($this->lead))->toBe(['entered', 'attempt_counted']);
    expect(leadRow($this->lead))->toBe($before);
    expect(LeadStatusChange::query()->count())->toBe($statusChanges);
})->with([
    'a text that was only sent' => [['channel' => ContactAttemptChannel::Sms, 'disposition' => ContactAttemptDisposition::MessageSent]],
    'an email that was only sent' => [['channel' => ContactAttemptChannel::Email, 'number' => null, 'disposition' => ContactAttemptDisposition::MessageSent]],
    'an inbound call nobody connected on' => [['direction' => ContactAttemptDirection::Inbound]],
    'a back-dated call older than the last counted one' => [['occurredAt' => '2026-10-07 13:45:00']],
    'a call at the very moment of the last counted one' => [['occurredAt' => '2026-10-07 14:00:00']],
]);

test('a counted attempt carries its cycle and step from the insert itself and the row is never updated', function () {
    DB::enableQueryLog();

    $attempt = logAttempt($this->lead, $this->consultant);

    $statements = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => Str::lower($query));
    DB::disableQueryLog();

    $inserts = $statements->filter(fn (string $query): bool => Str::startsWith($query, 'insert into') && Str::contains($query, 'contact_attempts'));
    $updates = $statements->filter(fn (string $query): bool => Str::startsWith($query, 'update') && Str::contains($query, 'contact_attempts'));

    expect($inserts)->toHaveCount(1);
    expect($inserts->first())->toContain('cadence_cycle')->toContain('cadence_step');
    expect($updates)->toBeEmpty();

    // The Lead's own row is not written either.
    expect($statements->filter(fn (string $query): bool => preg_match('/^update [`"]leads[`"]/', $query) === 1))->toBeEmpty();

    expect(ContactAttempt::query()->sole()->only(['cadence_cycle', 'cadence_step']))->toBe(['cadence_cycle' => 1, 'cadence_step' => 1]);
    expect($attempt->cadence_step)->toBe(1);
});

test('a replayed submission returns the existing attempt and moves nothing', function () {
    $submissionId = Str::uuid()->toString();

    $first = logAttempt($this->lead, $this->consultant, ['submissionId' => $submissionId]);

    $cadenceBefore = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();
    $eventsBefore = LeadQueueEvent::query()->count();

    DB::enableQueryLog();
    $second = logAttempt($this->lead, $this->consultant, ['submissionId' => $submissionId]);
    $statements = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => Str::lower($query));
    DB::disableQueryLog();

    expect($second->id)->toBe($first->id);
    expect(ContactAttempt::query()->count())->toBe(1);
    expect(LeadQueueEvent::query()->count())->toBe($eventsBefore);
    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($cadenceBefore);

    // The replay is answered before the cadence is read at all.
    expect($statements->filter(fn (string $query): bool => Str::contains($query, ['lead_cadences', 'lead_queue_events', 'contact_center_settings'])))->toBeEmpty();
});

test('when the cadence change fails after the insert neither the attempt nor any cadence change is saved', function () {
    $cadenceBefore = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();

    $this->partialMock(AdvanceLeadCadence::class)
        ->shouldReceive('apply')
        ->once()
        ->andThrow(new RuntimeException('The cadence could not be advanced.'));

    expect(fn () => logAttempt($this->lead, $this->consultant))->toThrow(RuntimeException::class, 'The cadence could not be advanced.');

    expect(ContactAttempt::query()->count())->toBe(0);
    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($cadenceBefore);
    expect(queueHistory($this->lead))->toBe(['entered']);
});

test('when the queue history cannot be written the attempt and the cadence change roll back together', function () {
    $cadenceBefore = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();

    $this->mock(QueueHistory::class)
        ->shouldReceive('record')
        ->andThrow(new RuntimeException('The queue history could not be written.'));

    expect(fn () => logAttempt($this->lead, $this->consultant))->toThrow(RuntimeException::class);

    expect(ContactAttempt::query()->count())->toBe(0);
    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($cadenceBefore);
});

test('each counted attempt uses its own step, and the database refuses a step used twice', function () {
    $first = logAttempt($this->lead, $this->consultant);

    $this->travelTo('2026-10-07 14:30:00');
    $second = logAttempt($this->lead, $this->consultant);

    expect([$first->cadence_step, $second->cadence_step])->toBe([1, 2]);
    expect($this->cadence->fresh()->attempts_made)->toBe(2);
    // Day 1, third attempt: 90 minutes after the second.
    expect($this->cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-07 16:00:00');

    expect(fn () => ContactAttempt::factory()->forLead($this->lead)->create(['cadence_cycle' => 1, 'cadence_step' => 2]))
        ->toThrow(UniqueConstraintViolationException::class);

    // Attempts that do not count never collide: both columns stay empty.
    ContactAttempt::factory()->forLead($this->lead)->count(2)->create();

    expect(ContactAttempt::query()->whereNull('cadence_step')->count())->toBe(2);
});

test('the twelfth counted attempt marks the cadence finished with no contact', function () {
    $before = leadRow($this->lead);
    $steps = [];

    for ($number = 1; $number <= 12; $number++) {
        $cadence = $this->cadence->fresh();

        expect($cadence->state)->toBe(CadenceState::InQueue);

        // Each call is made exactly when it falls due.
        $this->travelTo($cadence->due_at);
        $steps[] = logAttempt($this->lead, $this->consultant)->cadence_step;
    }

    $cadence = $this->cadence->fresh();

    expect($steps)->toBe(range(1, 12));
    expect($cadence->state)->toBe(CadenceState::FinishedNoContact);
    expect($cadence->attempts_made)->toBe(12);
    expect($cadence->due_at)->toBeNull();
    // The twelfth fell on Sunday at 2:30 pm Eastern.
    expect($cadence->ended_at->toDateTimeString())->toBe('2026-10-11 18:30:00');
    expect($cadence->held_by_user_id)->toBeNull();

    expect(queueHistory($this->lead))->toBe(['entered', ...array_fill(0, 12, 'attempt_counted'), 'finished_no_contact']);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::FinishedNoContact)->sole()->step)->toBe(12);

    // Finished is not a Lead status: the Lead's own row has not changed.
    expect(leadRow($this->lead))->toBe($before);

    // A later call is kept as evidence and moves nothing.
    $this->travelTo('2026-10-12 14:00:00');
    $later = logAttempt($this->lead, $this->consultant);

    expect($later->cadence_step)->toBeNull();
    expect($this->cadence->fresh()->state)->toBe(CadenceState::FinishedNoContact);
});

test('when the cadence is shortened below what a lead has had its next counted attempt finishes it', function () {
    logAttempt($this->lead, $this->consultant);
    $this->travelTo('2026-10-07 14:30:00');
    logAttempt($this->lead, $this->consultant);

    saveQueueSettings(['cadence' => [['type' => 'timed', 'waits_minutes' => [0, 30]]]]);

    // The stored due time is not recalculated by the change.
    expect($this->cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-07 16:00:00');

    $this->travelTo('2026-10-07 16:00:00');
    $third = logAttempt($this->lead, $this->consultant);

    expect($third->cadence_step)->toBe(3);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::FinishedNoContact);
});

test('a lead with no cadence behaves exactly as in slice 3', function () {
    $outside = Lead::factory()->at($this->lakeview)->create();
    $before = leadRow($outside);

    $attempt = logAttempt($outside, $this->consultant);

    expect($attempt->cadence_cycle)->toBeNull();
    expect($attempt->cadence_step)->toBeNull();
    expect(LeadCadence::query()->where('lead_id', $outside->id)->exists())->toBeFalse();
    expect(queueHistory($outside))->toBe([]);
    expect(leadRow($outside))->toBe($before);
});

test('a lead whose cadence has left the queue is unaffected by a later attempt', function () {
    logAttempt($this->lead, $this->consultant, ['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected]);

    $cadenceBefore = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();

    $this->travelTo('2026-10-07 15:00:00');
    $attempt = logAttempt($this->lead, $this->consultant);

    expect($attempt->cadence_step)->toBeNull();
    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($cadenceBefore);
    expect(queueHistory($this->lead))->toBe(['entered', 'ended:connected']);
});

test('an attempt logged from the lead page moves the cadence like any other', function () {
    Livewire::actingAs($this->consultant)
        ->test('pages::leads.show', ['lead' => $this->lead])
        ->call('openContactAttempt')
        ->set('attemptOccurredAt', '2026-10-07T09:58')
        ->set('attemptChannel', ContactAttemptChannel::Phone->value)
        ->set('attemptDirection', ContactAttemptDirection::Outbound->value)
        ->set('attemptNumber', '555-201-3344')
        ->set('attemptDurationSeconds', 15)
        ->set('attemptDisposition', ContactAttemptDisposition::NoAnswer->value)
        ->call('recordContactAttempt')
        ->assertHasNoErrors();

    $attempt = ContactAttempt::query()->sole();
    $cadence = $this->cadence->fresh();

    expect($attempt->cadence_step)->toBe(1);
    expect($cadence->attempts_made)->toBe(1);
    // Thirty minutes after the call was actually made, at 9:58 am Eastern.
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 14:28:00');
});
