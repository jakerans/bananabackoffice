<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Queue\ReturnLeadToQueue;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\ContactAttemptDisposition;
use App\Enums\LeadStatus;
use App\Enums\QueueEventType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\Impersonation;
use App\Support\Queue\QueueHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Put back in queue (decision 71): for the people who may assign Leads there,
 * with a required note, audited, and saved with its records or not at all.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->riverside = Location::factory()->create(['name' => 'Riverside']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    $this->lead = Lead::factory()->at($this->lakeview)->assignedTo($this->consultant)->working()->create();
    $this->cadence = enterQueue($this->lead);
});

function connect(Lead $lead, User $by): void
{
    logAttempt($lead, $by, ['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected]);
}

function finishCadence(Lead $lead, User $by): void
{
    for ($number = 1; $number <= 12; $number++) {
        test()->travelTo(test()->cadence->fresh()->due_at);
        logAttempt($lead, $by);
    }
}

test('a lead that left the queue part-way resumes where it stopped', function () {
    logAttempt($this->lead, $this->consultant);
    $this->travelTo('2026-10-07 14:30:00');
    logAttempt($this->lead, $this->consultant);
    $this->travelTo('2026-10-07 15:00:00');
    connect($this->lead, $this->consultant);

    expect($this->cadence->fresh()->state)->toBe(CadenceState::Connected);

    $row = (array) DB::table('leads')->where('id', $this->lead->id)->first();
    $enteredAt = $this->cadence->fresh()->entered_at->toDateTimeString();

    // Thursday, 7:00 am Eastern: before opening.
    $this->travelTo('2026-10-08 11:00:00');
    app(ReturnLeadToQueue::class)->handle($this->lead, '  Logged as connected by mistake; it was a voicemail.  ', $this->manager);

    $cadence = $this->cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->cycle)->toBe(1);
    expect($cadence->attempts_made)->toBe(2);
    // The next unused attempt, due at the next opening: 8:00 am Eastern.
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-08 12:00:00');
    expect($cadence->ended_at)->toBeNull();
    expect($cadence->entered_at->toDateTimeString())->toBe($enteredAt);
    expect($cadence->held_by_user_id)->toBeNull();

    $event = LeadQueueEvent::query()->where('type', QueueEventType::ReturnedToQueue)->sole();

    expect($event->note)->toBe('Logged as connected by mistake; it was a voicemail.');
    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->cycle)->toBe(1);
    expect($event->step)->toBe(3);
    expect($event->location_id)->toBe($this->lakeview->id);

    // MySQL does not keep the order JSON keys were written in, so these compare by value.
    $audit = AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->sole();

    expect($audit->subject->is($this->lead))->toBeTrue();
    expect($audit->actor_name)->toBe('Morgan Manager');
    expect($audit->before())->toEqual(['state' => 'connected', 'cycle' => 1, 'step' => 2]);
    expect($audit->after())->toEqual(['state' => 'in_queue', 'cycle' => 1, 'step' => 3]);
    expect($audit->reason)->toBe('Logged as connected by mistake; it was a voicemail.');
    expect($audit->location_id)->toBe($this->lakeview->id);

    // Status, assignee and lifecycle revision: the Lead's own row has not changed.
    expect((array) DB::table('leads')->where('id', $this->lead->id)->first())->toBe($row);

    // Its next counted attempt is the third.
    $this->travelTo('2026-10-08 12:00:00');
    expect(logAttempt($this->lead, $this->consultant)->only(['cadence_cycle', 'cadence_step']))->toBe(['cadence_cycle' => 1, 'cadence_step' => 3]);
});

test('a lead whose cadence finished with no contact restarts at attempt 1 in a new cycle', function () {
    finishCadence($this->lead, $this->consultant);

    expect($this->cadence->fresh()->state)->toBe(CadenceState::FinishedNoContact);

    // Monday, 10:00 am Eastern: inside calling hours, so due at once.
    $this->travelTo('2026-10-12 14:00:00');
    app(ReturnLeadToQueue::class)->handle($this->lead, 'A new number was found.', $this->manager);

    $cadence = $this->cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->cycle)->toBe(2);
    expect($cadence->attempts_made)->toBe(0);
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-12 14:00:00');
    expect($cadence->entered_at->toDateTimeString())->toBe('2026-10-12 14:00:00');
    expect($cadence->ended_at)->toBeNull();

    $audit = AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->sole();

    expect($audit->before())->toEqual(['state' => 'finished_no_contact', 'cycle' => 1, 'step' => 12]);
    expect($audit->after())->toEqual(['state' => 'in_queue', 'cycle' => 2, 'step' => 1]);

    // The new cycle uses its own steps: no clash with the first cycle's twelve.
    $attempt = logAttempt($this->lead, $this->consultant);

    expect($attempt->only(['cadence_cycle', 'cadence_step']))->toBe(['cadence_cycle' => 2, 'cadence_step' => 1]);
    expect($this->cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-12 14:30:00');
});

test('a lead with no attempts left restarts even though it did not finish', function () {
    logAttempt($this->lead, $this->consultant);
    $this->travelTo('2026-10-07 14:30:00');
    logAttempt($this->lead, $this->consultant);
    $this->travelTo('2026-10-07 15:00:00');
    connect($this->lead, $this->consultant);

    // The cadence is shortened to the two attempts this Lead has already had.
    saveQueueSettings(['cadence' => [['type' => 'timed', 'waits_minutes' => [0, 30]]]]);

    app(ReturnLeadToQueue::class)->handle($this->lead, 'They asked for another call.', $this->manager);

    $cadence = $this->cadence->fresh();

    expect($cadence->cycle)->toBe(2);
    expect($cadence->attempts_made)->toBe(0);
    expect($cadence->state)->toBe(CadenceState::InQueue);
});

test('the note is required', function (?string $note) {
    connect($this->lead, $this->consultant);

    expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, $note, $this->manager))
        ->toThrow(ValidationException::class, 'Say why this lead is going back in the queue.');

    expect($this->cadence->fresh()->state)->toBe(CadenceState::Connected);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->count())->toBe(0);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::ReturnedToQueue)->count())->toBe(0);
})->with([null, '', '   ']);

test('who may put a lead back: the people who may assign leads where it is', function (Closure $person, bool $allowed) {
    connect($this->lead, $this->consultant);

    $user = $person($this);

    expect($user->can('returnToQueue', $this->lead->fresh()))->toBe($allowed);

    if ($allowed) {
        app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $user);

        expect($this->cadence->fresh()->state)->toBe(CadenceState::InQueue);
    } else {
        expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $user))->toThrow(AuthorizationException::class);

        expect($this->cadence->fresh()->state)->toBe(CadenceState::Connected);
        expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->count())->toBe(0);
    }
})->with([
    'an owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'leadership' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'a call center agent' => [fn ($test) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), true],
    'a location manager where they may assign' => [fn ($test) => $test->manager, true],
    'a location manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, $test->riverside)->create(), false],
    'the sales consultant it is assigned to' => [fn ($test) => $test->consultant, false],
    'finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), false],
    'a production manager' => [fn ($test) => User::factory()->withRoleEverywhere(Role::ProductionManager)->create(), false],
    'a deactivated manager' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->inactive()->create(), false],
]);

test('an unrouted lead is put back only by company-wide lead staff', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $unrouted = Lead::factory()->create();
    $this->cadence = enterQueue($unrouted);
    connect($unrouted, $agent);

    expect($this->manager->can('returnToQueue', $unrouted))->toBeFalse();
    expect(User::factory()->withRoleEverywhere(Role::LocationManager)->create()->can('returnToQueue', $unrouted))->toBeFalse();
    expect(fn () => app(ReturnLeadToQueue::class)->handle($unrouted, 'Back in the queue.', $this->manager))->toThrow(AuthorizationException::class);

    // 2:00 am Eastern: an unrouted Lead waits for Eastern's opening.
    $this->travelTo('2026-10-08 06:00:00');
    app(ReturnLeadToQueue::class)->handle($unrouted, 'Back in the queue.', $agent);

    expect($this->cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-08 12:00:00');
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->sole()->location_id)->toBeNull();
});

test('a lead already in the queue, or with no cadence, cannot be put back', function () {
    expect($this->manager->can('returnToQueue', $this->lead))->toBeFalse();
    expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager))->toThrow(AuthorizationException::class);

    $never = Lead::factory()->at($this->lakeview)->create();

    expect($this->manager->can('returnToQueue', $never))->toBeFalse();
    expect(fn () => app(ReturnLeadToQueue::class)->handle($never, 'Back in the queue.', $this->manager))->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->count())->toBe(0);
    expect(queueHistory($this->lead))->toBe(['entered']);
});

test('a disqualified lead or one in deferred follow-up is refused until it is reopened or resumed', function (string $status) {
    connect($this->lead, $this->consultant);

    // The Lead's lifecycle moves on separately, by the unchanged B7 actions.
    DB::table('leads')->where('id', $this->lead->id)->update(['status' => $status]);

    expect($this->manager->can('returnToQueue', $this->lead->fresh()))->toBeFalse();
    expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager))->toThrow(AuthorizationException::class);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::Connected);

    // Once it is New, Working or Qualified again it may be put back.
    DB::table('leads')->where('id', $this->lead->id)->update(['status' => LeadStatus::Qualified->value]);

    app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager);

    expect($this->cadence->fresh()->state)->toBe(CadenceState::InQueue);
})->with([LeadStatus::Disqualified->value, LeadStatus::Nurture->value]);

test('the caller\'s stale copy of the lead decides nothing', function () {
    connect($this->lead, $this->consultant);

    $stale = $this->lead->fresh();
    $stale->load('location');

    // Moved to a Location the manager does not manage, after the page was opened.
    DB::table('leads')->where('id', $this->lead->id)->update(['location_id' => $this->riverside->id]);

    expect(fn () => app(ReturnLeadToQueue::class)->handle($stale, 'Back in the queue.', $this->manager))->toThrow(AuthorizationException::class);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::Connected);
});

test('the caller\'s stale copy of themselves decides nothing', function (Closure $change) {
    connect($this->lead, $this->consultant);

    // Loaded, with their grants, while they were still allowed.
    $stale = User::query()->with('accessGrants')->findOrFail($this->manager->id);
    expect($stale->can('returnToQueue', $this->lead->fresh()))->toBeTrue();

    $change($this);

    $cadence = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();
    $history = LeadQueueEvent::query()->count();
    $audit = AuditEvent::query()->count();

    // The copy in hand still says yes; the rows as they are now say no.
    expect($stale->is_active)->toBeTrue();
    expect($stale->accessGrants)->toHaveCount(1);
    expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $stale))->toThrow(AuthorizationException::class);

    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($cadence);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::Connected);
    expect(LeadQueueEvent::query()->count())->toBe($history);
    expect(AuditEvent::query()->count())->toBe($audit);
})->with([
    'deactivated since' => [fn ($test) => DB::table('users')->where('id', $test->manager->id)->update(['is_active' => false])],
    'their grant revoked since' => [fn ($test) => DB::table('access_grants')->where('user_id', $test->manager->id)->delete()],
    'their grant moved to another location since' => [fn ($test) => DB::table('access_grants')->where('user_id', $test->manager->id)->update(['location_id' => $test->riverside->id])],
    'removed since' => [fn ($test) => DB::table('users')->where('id', $test->manager->id)->delete()],
]);

test('the change, its history entry and its audit event are saved together or not at all', function (string $failing) {
    connect($this->lead, $this->consultant);

    $before = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();
    $events = LeadQueueEvent::query()->count();

    $this->mock($failing)
        ->shouldReceive($failing === QueueHistory::class ? 'record' : 'handle')
        ->andThrow(new RuntimeException('It could not be recorded.'));

    expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager))->toThrow(RuntimeException::class);

    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($before);
    expect(LeadQueueEvent::query()->count())->toBe($events);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->count())->toBe(0);
})->with([
    'the audit event fails' => RecordAuditEvent::class,
    'the queue history fails' => QueueHistory::class,
]);

test('a double submit changes nothing the second time', function () {
    connect($this->lead, $this->consultant);

    app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager);

    $after = (array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first();

    $this->travelTo('2026-10-07 14:00:05');

    expect(fn () => app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager))->toThrow(AuthorizationException::class);

    expect((array) DB::table('lead_cadences')->where('id', $this->cadence->id)->first())->toBe($after);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->count())->toBe(1);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::ReturnedToQueue)->count())->toBe(1);
});

test('the conditional update itself refuses a cadence that is already back in the queue', function () {
    connect($this->lead, $this->consultant);

    // Two submits pass the permission check; the first has now been saved.
    $changed = fn (): int => DB::table('lead_cadences')
        ->where('id', $this->cadence->id)
        ->where('state', '<>', CadenceState::InQueue->value)
        ->update(['state' => CadenceState::InQueue->value]);

    expect($changed())->toBe(1);
    expect($changed())->toBe(0);
});

test('a put back made while impersonating names the owner in the audit and the history', function () {
    connect($this->lead, $this->consultant);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);

    app(Impersonation::class)->serveAs($owner, $this->manager);

    try {
        app(ReturnLeadToQueue::class)->handle($this->lead, 'Back in the queue.', $this->manager);
    } finally {
        app(Impersonation::class)->forgetRequest();
    }

    $audit = AuditEvent::query()->where('action', AuditAction::LeadReturnedToQueue)->sole();
    $event = LeadQueueEvent::query()->where('type', QueueEventType::ReturnedToQueue)->sole();

    expect($audit->madeBy())->toBe('Jacob Ransom, impersonating Morgan Manager');
    expect($event->actor_name)->toBe('Morgan Manager');
    expect($event->impersonator_name)->toBe('Jacob Ransom');
});

test('the audit action is stored as lead.returned_to_queue', function () {
    expect(AuditAction::LeadReturnedToQueue->value)->toBe('lead.returned_to_queue');
});
