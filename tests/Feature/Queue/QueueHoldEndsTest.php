<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Locations\DeactivateLocation;
use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDisposition;
use App\Enums\LeadStatus;
use App\Enums\QueueEventType;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * A hold ends at once, in the transaction of the change that ends it, and the
 * history says why and whose hold it was (decision 71). No tick runs in this
 * file, and the former holder's next "Next lead" is not what ends the hold.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->riverside = Location::factory()->create(['name' => 'Riverside']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $this->manager = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Morgan Manager']);
    $this->setter = User::factory()
        ->withRoleAt(Role::CallCenterAgent, $this->lakeview)
        ->withRoleAt(Role::CallCenterAgent, $this->riverside)
        ->create(['name' => 'Avery Agent']);

    $this->lead = Lead::factory()->at($this->lakeview)->assignedTo($this->manager)->working()->create();
    $this->cadence = enterQueue($this->lead);

    $this->claim = nextLead($this->setter);
    expect($this->claim->lead->id)->toBe($this->lead->id);

    // Three minutes into a five-minute hold.
    $this->travelTo('2026-10-07 14:03:00');
});

/**
 * The one entry that says the setter's hold ended, checked against whose it was.
 */
function holdEnding(): LeadQueueEvent
{
    $event = LeadQueueEvent::query()->whereIn('type', [QueueEventType::HoldEnded, QueueEventType::HoldExpired])->sole();

    expect($event->affected_user_id)->toBe(test()->setter->id);
    expect($event->hold_token)->toBe(test()->claim->cadence->hold_token);

    $cadence = test()->cadence->fresh();

    expect($cadence->held_by_user_id)->toBeNull();
    expect($cadence->hold_token)->toBeNull();
    expect($cadence->held_at)->toBeNull();
    expect($cadence->held_until)->toBeNull();
    expect(SchedulerHeartbeat::query()->count())->toBe(0);

    return $event;
}

test('disqualifying a held lead ends the hold and the cadence together', function () {
    $reason = LeadDisqualificationReason::factory()->create();

    app(DisqualifyLead::class)->handle($this->lead, $reason, 'A test submission.', $this->manager);

    $event = holdEnding();

    expect($event->type)->toBe(QueueEventType::HoldEnded);
    expect($event->reason)->toBe('disqualified');
    expect($event->actor_id)->toBe($this->manager->id);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::Disqualified);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:disqualified', 'ended:disqualified']);
});

test('scheduling a deferred follow-up on a held lead ends the hold and the cadence together', function () {
    app(MoveLeadToNurture::class)->handle($this->lead, '2026-10-20', 'Call back after their trip.', $this->manager);

    expect(holdEnding()->reason)->toBe('deferred_follow_up');
    expect($this->cadence->fresh()->state)->toBe(CadenceState::DeferredFollowUp);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:deferred_follow_up', 'ended:deferred_follow_up']);
});

test('moving a held lead ends the hold and leaves its place in the cadence alone', function (string $move) {
    $dueAt = $this->cadence->fresh()->due_at->toDateTimeString();

    match ($move) {
        'transferred' => app(RouteLead::class)->handle($this->lead, $this->riverside, 'Closer to the customer.', $this->manager),
        'returned to central' => app(ReturnLeadToCentral::class)->handle($this->lead, 'Outside every service area.', $this->manager),
    };

    $event = holdEnding();

    expect($event->type)->toBe(QueueEventType::HoldEnded);
    expect($event->reason)->toBe('moved');
    expect($event->actor_id)->toBe($this->manager->id);

    $cadence = $this->cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->due_at->toDateTimeString())->toBe($dueAt);
    expect($cadence->attempts_made)->toBe(0);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:moved']);
})->with(['transferred', 'returned to central']);

test('routing a held unrouted lead ends the hold', function () {
    $companyAgent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $unrouted = dueLead(attributes: ['received_at' => '2026-10-07 13:59:00']);
    $claim = nextLead($companyAgent);

    expect($claim->lead->id)->toBe($unrouted->id);

    app(RouteLead::class)->handle($unrouted, $this->riverside, null, $this->manager);

    expect(LeadCadence::query()->where('lead_id', $unrouted->id)->sole()->held_by_user_id)->toBeNull();
    expect(queueHistory($unrouted))->toBe(['entered', 'claimed', 'hold_ended:moved']);
    expect(LeadQueueEvent::query()->where('lead_id', $unrouted->id)->where('type', QueueEventType::HoldEnded)->sole()->affected_user_id)->toBe($companyAgent->id);
});

test('the move and the end of the hold are saved together or not at all', function () {
    $this->mock(RecordAuditEvent::class)->shouldReceive('handle')->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => app(RouteLead::class)->handle($this->lead, $this->riverside, 'Closer to the customer.', $this->manager))->toThrow(RuntimeException::class);

    expect($this->lead->fresh()->location_id)->toBe($this->lakeview->id);
    expect($this->cadence->fresh()->held_by_user_id)->toBe($this->setter->id);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed']);
});

test('revoking the access that let the setter work the lead ends the hold at once', function () {
    $this->actingAs($this->owner);

    app(RevokeAccess::class)->handle(AccessGrant::query()->where('user_id', $this->setter->id)->where('location_id', $this->lakeview->id)->sole());

    $event = holdEnding();

    expect($event->type)->toBe(QueueEventType::HoldEnded);
    expect($event->reason)->toBe('access_lost');
    expect($event->actor_id)->toBe($this->owner->id);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::InQueue);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
});

test('revoking access the setter does not need for the held lead leaves the hold alone', function () {
    app(RevokeAccess::class)->handle(AccessGrant::query()->where('user_id', $this->setter->id)->where('location_id', $this->riverside->id)->sole());

    expect($this->cadence->fresh()->held_by_user_id)->toBe($this->setter->id);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed']);
});

test('the revocation and the end of the hold are saved together or not at all', function () {
    $grant = AccessGrant::query()->where('user_id', $this->setter->id)->where('location_id', $this->lakeview->id)->sole();

    $this->mock(RecordAuditEvent::class)->shouldReceive('handle')->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => app(RevokeAccess::class)->handle($grant))->toThrow(RuntimeException::class);

    expect(AccessGrant::query()->whereKey($grant->id)->exists())->toBeTrue();
    expect($this->cadence->fresh()->held_by_user_id)->toBe($this->setter->id);
});

test('deactivating the setter ends the hold at once', function () {
    $this->actingAs($this->owner);

    app(DeactivateStaffMember::class)->handle($this->setter);

    $event = holdEnding();

    expect($event->reason)->toBe('access_lost');
    expect($event->actor_id)->toBe($this->owner->id);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
});

test('an attempt logged by the holder ends the hold with its own reason', function () {
    $attempt = logAttempt($this->lead, $this->setter);

    $event = holdEnding();

    expect($event->type)->toBe(QueueEventType::HoldEnded);
    expect($event->reason)->toBe('attempt_logged');
    expect($event->actor_id)->toBe($this->setter->id);
    expect($event->contact_attempt_id)->toBe($attempt->id);
    expect($attempt->cadence_step)->toBe(1);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:attempt_logged', 'attempt_counted']);
});

test('an attempt logged by someone else ends the hold and says so', function (array $facts, array $history) {
    $attempt = logAttempt($this->lead, $this->manager, $facts);

    $event = holdEnding();

    expect($event->type)->toBe(QueueEventType::HoldEnded);
    expect($event->reason)->toBe('attempt_logged_by_other');
    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->contact_attempt_id)->toBe($attempt->id);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', ...$history]);
    // Exactly one entry ends the hold.
    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->count())->toBe(1);
})->with([
    'a call that counts' => [[], ['hold_ended:attempt_logged_by_other', 'attempt_counted']],
    'a connection' => [['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected], ['hold_ended:attempt_logged_by_other', 'ended:connected']],
    'a text that is evidence only' => [['channel' => ContactAttemptChannel::Sms, 'disposition' => ContactAttemptDisposition::MessageSent], ['hold_ended:attempt_logged_by_other']],
]);

test('a connection logged by the holder ends the hold as their own attempt', function () {
    logAttempt($this->lead, $this->setter, ['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected]);

    expect(holdEnding()->reason)->toBe('attempt_logged');
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:attempt_logged', 'ended:connected']);
});

test('an attempt logged by the former holder after their hold expired is still saved and counted', function () {
    // Seven minutes after taking it: the hold ran out two minutes ago.
    $this->travelTo('2026-10-07 14:07:00');

    $attempt = logAttempt($this->lead, $this->setter);

    expect($attempt->exists)->toBeTrue();
    expect($attempt->cadence_step)->toBe(1);
    expect($this->cadence->fresh()->attempts_made)->toBe(1);

    // Its time had already run out, so the history says expired, not ended.
    expect(holdEnding()->type)->toBe(QueueEventType::HoldExpired);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_expired', 'attempt_counted']);
});

test('when someone else now holds the lead the former holder\'s late attempt still counts and ends that hold', function () {
    $other = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Blake Agent']);

    $this->travelTo('2026-10-07 14:06:00');
    $taken = nextLead($other);
    expect($taken->lead->id)->toBe($this->lead->id);

    $this->travelTo('2026-10-07 14:07:00');
    $attempt = logAttempt($this->lead, $this->setter);

    expect($attempt->cadence_step)->toBe(1);
    expect($this->cadence->fresh()->held_by_user_id)->toBeNull();
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_expired', 'claimed', 'hold_ended:attempt_logged_by_other', 'attempt_counted']);

    $ended = LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->sole();

    expect($ended->affected_user_id)->toBe($other->id);
    expect($ended->hold_token)->toBe($taken->cadence->hold_token);
    expect($ended->actor_id)->toBe($this->setter->id);
});

test('ending a hold never touches the lead\'s assignee, status or revision', function () {
    $row = (array) DB::table('leads')->where('id', $this->lead->id)->first();

    logAttempt($this->lead, $this->manager);
    app(RevokeAccess::class)->handle(AccessGrant::query()->where('user_id', $this->setter->id)->where('location_id', $this->lakeview->id)->sole());

    expect((array) DB::table('leads')->where('id', $this->lead->id)->first())->toBe($row);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->manager->id);
});

/*
|--------------------------------------------------------------------------
| Deactivating a Location
|--------------------------------------------------------------------------
*/

test('a location with active leads is still refused and every hold is left untouched', function () {
    expect(fn () => app(DeactivateLocation::class)->handle($this->lakeview))
        ->toThrow(ValidationException::class, 'still has 1 active lead');

    expect($this->lakeview->fresh()->is_active)->toBeTrue();
    expect($this->cadence->fresh()->held_by_user_id)->toBe($this->setter->id);
    expect($this->cadence->fresh()->hold_token)->toBe($this->claim->cadence->hold_token);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed']);
});

test('deactivating a location ends every hold on a lead there, in the same transaction', function () {
    // State the refusal cannot reach by itself: a held, in-queue cadence on a
    // Lead at the Location that no longer counts as active.
    DB::table('leads')->where('id', $this->lead->id)->update(['status' => LeadStatus::Disqualified->value]);

    $secondSetter = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Blake Agent']);
    $second = dueLead($this->lakeview);
    $secondClaim = nextLead($secondSetter);
    expect($secondClaim->lead->id)->toBe($second->id);
    DB::table('leads')->where('id', $second->id)->update(['status' => LeadStatus::Disqualified->value]);

    // A hold at another Location, which must be left alone.
    $thirdSetter = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Agent']);
    $elsewhere = dueLead($this->riverside);
    $elsewhereClaim = nextLead($thirdSetter);
    expect($elsewhereClaim->lead->id)->toBe($elsewhere->id);

    $this->actingAs($this->owner);
    app(DeactivateLocation::class)->handle($this->lakeview);

    expect($this->lakeview->fresh()->is_active)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::LocationDeactivated)->count())->toBe(1);

    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
    expect(queueHistory($second))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
    expect(queueHistory($elsewhere))->toBe(['entered', 'claimed']);

    $ended = LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->orderBy('id')->get();

    expect($ended->pluck('affected_user_id')->all())->toBe([$this->setter->id, $secondSetter->id]);
    expect($ended->pluck('hold_token')->all())->toBe([$this->claim->cadence->hold_token, $secondClaim->cadence->hold_token]);
    expect($ended->pluck('actor_id')->unique()->all())->toBe([$this->owner->id]);
    expect($ended->pluck('location_id')->unique()->all())->toBe([$this->lakeview->id]);

    expect(LeadCadence::query()->whereIn('lead_id', [$this->lead->id, $second->id])->whereNotNull('held_by_user_id')->count())->toBe(0);
    expect(LeadCadence::query()->where('lead_id', $elsewhere->id)->sole()->held_by_user_id)->toBe($thirdSetter->id);

    // Neither a tick nor the former holder's next click did this.
    expect(SchedulerHeartbeat::query()->count())->toBe(0);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Claimed)->count())->toBe(3);
});

test('when the deactivation cannot be audited the location stays active and the holds stay', function () {
    DB::table('leads')->where('id', $this->lead->id)->update(['status' => LeadStatus::Disqualified->value]);

    $this->mock(RecordAuditEvent::class)->shouldReceive('handle')->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => app(DeactivateLocation::class)->handle($this->lakeview))->toThrow(RuntimeException::class);

    expect($this->lakeview->fresh()->is_active)->toBeTrue();
    expect($this->cadence->fresh()->held_by_user_id)->toBe($this->setter->id);
    expect($this->cadence->fresh()->hold_token)->toBe($this->claim->cadence->hold_token);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed']);
});
