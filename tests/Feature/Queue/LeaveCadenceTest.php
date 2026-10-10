<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\ReopenLead;
use App\Actions\Leads\ReturnLeadToWorking;
use App\Actions\Queue\RunQueueTick;
use App\Actions\Queue\SkipHeldLead;
use App\Enums\CadenceState;
use App\Enums\ContactAttemptDisposition;
use App\Enums\LeadStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueSkipReason;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadQueueEvent;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Support\Queue\QueueClaim;
use App\Support\Queue\QueueHistory;

/*
 * Leaving the queue (decision 67): disqualifying a Lead or scheduling a
 * Deferred Follow-up ends its cadence in the same transaction. A Lead that has
 * left does not come back by itself.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create();
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->lead = Lead::factory()->at($this->lakeview)->assignedTo($this->manager)->working()->create();
    $this->cadence = enterQueue($this->lead);
    $this->reason = LeadDisqualificationReason::factory()->create(['name' => 'Spam']);
});

test('disqualifying a lead ends its cadence in the same transaction', function () {
    app(DisqualifyLead::class)->handle($this->lead, $this->reason, 'A test submission.', $this->manager);

    $cadence = $this->cadence->fresh();

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Disqualified);
    expect($cadence->state)->toBe(CadenceState::Disqualified);
    expect($cadence->due_at)->toBeNull();
    expect($cadence->ended_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect(queueHistory($this->lead))->toBe(['entered', 'ended:disqualified']);

    $event = LeadQueueEvent::query()->where('type', QueueEventType::Ended)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->actor_name)->toBe('Morgan Manager');
    expect($event->location_id)->toBe($this->lakeview->id);
});

test('scheduling a deferred follow-up ends the cadence in the same transaction', function () {
    app(MoveLeadToNurture::class)->handle($this->lead, '2026-10-20', 'Call back after their trip.', $this->manager);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Nurture);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::DeferredFollowUp);
    expect($this->cadence->fresh()->due_at)->toBeNull();
    expect(queueHistory($this->lead))->toBe(['entered', 'ended:deferred_follow_up']);

    // Changing the follow-up date later records nothing more in the queue.
    app(MoveLeadToNurture::class)->handle($this->lead->fresh(), '2026-10-22', 'Call back after their trip.', $this->manager);

    expect(queueHistory($this->lead))->toBe(['entered', 'ended:deferred_follow_up']);
});

test('leaving rolls back together with the change that caused it', function (string $change, string $failing) {
    $leave = fn () => match ($change) {
        'disqualify' => app(DisqualifyLead::class)->handle($this->lead, $this->reason, 'A test submission.', $this->manager),
        'schedule follow-up' => app(MoveLeadToNurture::class)->handle($this->lead, '2026-10-20', 'Call back.', $this->manager),
    };

    $this->mock($failing)
        ->shouldReceive($failing === QueueHistory::class ? 'record' : 'handle')
        ->andThrow(new RuntimeException('It could not be recorded.'));

    expect($leave)->toThrow(RuntimeException::class);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Working);
    expect($lead->lifecycle_version)->toBe((int) $this->lead->lifecycle_version);
    expect($lead->assigned_user_id)->toBe($this->manager->id);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::InQueue);
    expect($this->cadence->fresh()->due_at)->not->toBeNull();
    expect(queueHistory($this->lead))->toBe(['entered']);
})->with([
    'disqualify',
    'schedule follow-up',
])->with([
    'the queue history fails' => QueueHistory::class,
    'the audit event fails' => RecordAuditEvent::class,
]);

test('resuming a deferred follow-up does not put the lead back in the queue', function () {
    app(MoveLeadToNurture::class)->handle($this->lead, '2026-10-20', 'Call back after their trip.', $this->manager);
    app(ReturnLeadToWorking::class)->handle($this->lead->fresh(), $this->manager);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Working);
    expect($this->cadence->fresh()->state)->toBe(CadenceState::DeferredFollowUp);
    expect(queueHistory($this->lead))->toBe(['entered', 'ended:deferred_follow_up']);
    expect(nextLead($this->manager)->status)->toBe(QueueClaim::NOTHING_DUE);
});

test('reopening a disqualified lead does not put it back in the queue', function () {
    app(DisqualifyLead::class)->handle($this->lead, $this->reason, 'A test submission.', $this->manager);
    app(ReopenLead::class)->handle($this->lead->fresh(), 'Disqualified by mistake.', $this->manager);

    expect($this->lead->fresh()->isActive())->toBeTrue();
    expect($this->cadence->fresh()->state)->toBe(CadenceState::Disqualified);
    expect(queueHistory($this->lead))->toBe(['entered', 'ended:disqualified']);
    expect(nextLead($this->manager)->status)->toBe(QueueClaim::NOTHING_DUE);
});

test('a lead with no cadence is disqualified and deferred exactly as before', function () {
    $outside = Lead::factory()->at($this->lakeview)->assignedTo($this->manager)->working()->create();
    $other = Lead::factory()->at($this->lakeview)->assignedTo($this->manager)->working()->create();

    app(DisqualifyLead::class)->handle($outside, $this->reason, 'A test submission.', $this->manager);
    app(MoveLeadToNurture::class)->handle($other, '2026-10-20', 'Call back.', $this->manager);

    expect($outside->fresh()->status)->toBe(LeadStatus::Disqualified);
    expect($other->fresh()->status)->toBe(LeadStatus::Nurture);
    expect(LeadQueueEvent::query()->whereIn('lead_id', [$outside->id, $other->id])->count())->toBe(0);
});

test('no cadence change adds a lead status or writes to the lifecycle history', function () {
    // The cadence adds none. "appointment_booked" is slice 6's, added by booking an appointment (rulebook, rule 3).
    expect(array_column(LeadStatus::cases(), 'value'))->toBe(['new', 'working', 'qualified', 'appointment_booked', 'nurture', 'disqualified']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $second = Lead::factory()->at($this->lakeview)->create();
    enterQueue($second);

    $historyBefore = LeadStatusChange::query()->count();
    $versions = Lead::query()->orderBy('id')->pluck('lifecycle_version', 'id')->all();
    $statuses = Lead::query()->orderBy('id')->pluck('status', 'id')->all();
    $assignees = Lead::query()->orderBy('id')->pluck('assigned_user_id', 'id')->all();

    // Take, skip, take again, count twelve attempts on one Lead and connect on the other.
    $claim = nextLead($agent);
    app(SkipHeldLead::class)->handle($agent, $claim->cadence->id, $claim->cadence->hold_token, QueueSkipReason::CannotCallNow);
    nextLead($agent);

    for ($number = 1; $number <= 12; $number++) {
        $this->travelTo($this->cadence->fresh()->due_at);
        logAttempt($this->lead, $this->manager);
    }

    logAttempt($second, $agent, ['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected]);
    app(RunQueueTick::class)->handle();

    expect($this->cadence->fresh()->state)->toBe(CadenceState::FinishedNoContact);
    expect(LeadStatusChange::query()->count())->toBe($historyBefore);
    expect(Lead::query()->orderBy('id')->pluck('lifecycle_version', 'id')->all())->toBe($versions);
    expect(Lead::query()->orderBy('id')->pluck('status', 'id')->all())->toEqual($statuses);
    expect(Lead::query()->orderBy('id')->pluck('assigned_user_id', 'id')->all())->toBe($assignees);
});
