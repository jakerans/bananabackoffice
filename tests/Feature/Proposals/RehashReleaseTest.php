<?php

use App\Actions\Appointments\TakeLeadBackFromContactCenter;
use App\Actions\Proposals\EditProposal;
use App\Actions\Proposals\MarkLeadLost;
use App\Actions\Proposals\PresentProposalAgain;
use App\Actions\Proposals\RecordNoDemoReason;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Proposals\UndoLeadLost;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\CallQueue;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\LeadCadence;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\Objection;
use App\Models\SaleEntry;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Appointments\SalesReturn;
use App\Support\Proposals\ProposalValidity;
use App\Support\Queue\DueQueue;
use App\Support\Queue\QueueClaim;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * Rehash (docs/slice-9-pending-rehash-plan.md, step 9b; product-architecture.md,
 * 5.5 and 20; decision 137): when a Lead's time with its salesperson is up it
 * goes to the Contact Center in a queue of its own, with what was pitched; the
 * call center books a return visit or records that the customer said no; and
 * the salesperson it came from can take it back.
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales, marked run at 4:35 pm. "Whole exterior" is pending, valid until
 * Thursday the 15th, with the objection "Wants to think it over". The Lead
 * leaves Sam at midnight on the 15th, Lakeview time: 04:00 UTC on the 16th.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->thinking = Objection::query()->where('name', 'Wants to think it over')->sole();

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-08 20:35:00');
    runAppointment($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * "Whole exterior", pending, with the objection written down.
 */
function pendingBid($test)
{
    $proposal = addProposal($test->lead->fresh(), $test->sam);

    app(RecordProposalResult::class)->handle($test->lead->fresh(), $proposal, 'pending', ['objectionId' => $test->thinking->id], $test->sam);

    return $proposal->fresh();
}

/**
 * Let the Lead's last day pass and the timer run, then make its first call due.
 *
 * @return array<string, int> What the timer reported.
 */
function releaseToRehash($test, string $at = '2026-10-16 15:00:00'): array
{
    $test->travelTo($at);

    $done = app(RunQueueTick::class)->handle();

    LeadCadence::query()->where('state', CadenceState::InQueue)->update(['due_at' => now()->subMinute()]);

    return $done;
}

/*
|--------------------------------------------------------------------------
| The release
|--------------------------------------------------------------------------
*/

test('the day after its last pending proposal expires, the lead goes to the contact center for rehash', function () {
    $proposal = pendingBid($this);
    $this->sam->notifications()->delete();

    // 11:00 pm on the 15th at Lakeview: its last day is not over.
    $this->travelTo('2026-10-16 03:00:00');

    expect(app(RunQueueTick::class)->handle()['released'])->toBe(0);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);

    // Half past midnight.
    $this->travelTo('2026-10-16 04:30:00');

    expect(app(RunQueueTick::class)->handle())->toMatchArray(['released' => 1, 'failed' => 0]);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->released_from_user_id)->toBe($this->sam->id);
    expect($lead->released_from_sales_at->toDateTimeString())->toBe('2026-10-16 04:30:00');
    expect($lead->isInRehash())->toBeTrue();

    // In the Rehash queue, as a Lead nobody has called this time round.
    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect([$cadence->queue, $cadence->state, $cadence->attempts_made])->toBe([CallQueue::Rehash, CadenceState::InQueue, 0]);

    // The bid is as it was: still pending, with its day, and the Lead still counts as issued.
    expect($proposal->fresh()->only(['result', 'objection_name']))->toBe(['result' => ProposalResult::Pending, 'objection_name' => 'Wants to think it over']);
    expect($proposal->fresh()->valid_until->toDateString())->toBe('2026-10-15');
    expect(LeadIssue::query()->where('lead_id', $lead->id)->counted()->count())->toBe(1);

    // Its salesperson is told, with nothing about the customer.
    $notice = $this->sam->notifications()->get()->firstWhere('data.kind', AppointmentChanged::PENDING_LEAD_RELEASED);

    expect($notice->data['message'])->toBe('A lead of yours was not sold, lost or booked again in time and went to the Contact Center for rehash. You can take it back from the lead.');
    expect($notice->data['message'])->not->toContain('Pat Customer');

    // Done by the timer, so by nobody.
    $event = AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole();

    expect($event->actor_id)->toBeNull();
    expect($event->values_before)->toMatchArray(['status' => 'appointment_run', 'salesperson' => 'Sam Sales']);
    expect($event->values_after)->toMatchArray(['status' => 'qualified', 'how' => 'released_pending', 'counts_as_issued' => true]);

    // What the call center is told before dialling.
    $return = SalesReturn::of($lead);

    expect($return->isRehash)->toBeTrue();
    expect($return->headline)->toBe('Rehash: this lead has a bid that is still pending');
    expect($return->pitched)->toBe(['Whole exterior, $8,450. Still pending. Main objection: Wants to think it over. Expired yesterday, Thu, Oct 15.']);
    expect($return->note)->toBe('Nothing was sold, lost or booked before the last pending estimate expired on Oct 15, 2026.');

    // Once.
    expect(app(RunQueueTick::class)->handle()['released'])->toBe(0);
});

test('a lead that is booked, sold, lost or given more time is never released', function (Closure $arrange) {
    $proposal = pendingBid($this);

    $arrange($this, $proposal);

    $status = $this->lead->fresh()->status;

    expect(releaseToRehash($this, '2026-10-17 15:00:00')['released'])->toBe(0);
    expect($this->lead->fresh()->only(['status', 'assigned_user_id']))->toBe(['status' => $status, 'assigned_user_id' => $this->sam->id]);
    expect($this->lead->fresh()->isInRehash())->toBeFalse();
})->with([
    'a return visit is booked' => [fn ($test, $proposal) => bookAppointment($test->lead->fresh(), $test->sam, $test->sam, '2026-10-20', '10:00')],
    'it is sold' => [fn ($test, $proposal) => app(RecordProposalResult::class)->handle($test->lead->fresh(), $proposal, 'sold', [], $test->sam)],
    'the lead is marked lost' => [fn ($test, $proposal) => app(MarkLeadLost::class)->handle($test->lead->fresh(), ['lossReasonId' => $test->price->id, 'objectionId' => $test->thinking->id], $test->sam)],
    'the day was moved on' => [fn ($test, $proposal) => app(EditProposal::class)->handle($test->lead->fresh(), $proposal, $proposal->name, '8450', $test->sam, validUntil: '2026-10-25')],
    'another pending proposal runs later' => [function ($test, $proposal) {
        $deck = addProposal($test->lead->fresh(), $test->sam, ['name' => 'Deck', 'price' => '1800']);

        app(EditProposal::class)->handle($test->lead->fresh(), $deck, 'Deck', '1800', $test->sam, validUntil: '2026-10-25');
    }],
]);

test('a lead run with nothing presented has the same seven days, is warned about, and then goes to rehash too (decision 137)', function () {
    app(RecordNoDemoReason::class)->handle($this->lead, $this->appointment, 'Only one of the two owners was home.', $this->sam);
    $this->sam->notifications()->delete();

    expect(ProposalValidity::ownershipUntil($this->lead->fresh())->toDateString())->toBe('2026-10-15');

    $this->travelTo('2026-10-13 15:00:00');

    expect(app(RunQueueTick::class)->handle())->toMatchArray(['warned' => 1, 'released' => 0]);
    expect($this->sam->notifications()->sole()->data['message'])->toStartWith('A lead of yours stays with you for 2 more days.');

    expect(releaseToRehash($this)['released'])->toBe(1);

    $return = SalesReturn::of($this->lead->fresh());

    expect($this->lead->fresh()->isInRehash())->toBeTrue();
    expect($return->headline)->toBe('Rehash: this lead had an appointment and nothing was presented');
    expect($return->pitched)->toBe([]);
    expect($return->noDemoReason)->toBe('Only one of the two owners was home.');
    expect($return->note)->toBe('Nothing was presented and nothing was booked by Oct 15, 2026.');
});

test('marking the only proposal lost gives seven days from then, and then the lead goes to rehash with nothing pending', function () {
    $proposal = pendingBid($this);

    $this->travelTo('2026-10-12 15:00:00');
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'lost', ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $this->sam);

    expect(ProposalValidity::ownershipUntil($this->lead->fresh())->toDateString())->toBe('2026-10-19');
    expect(releaseToRehash($this, '2026-10-19 15:00:00')['released'])->toBe(0);
    expect(releaseToRehash($this, '2026-10-20 15:00:00')['released'])->toBe(1);

    $return = SalesReturn::of($this->lead->fresh());

    expect($return->headline)->toBe('Rehash: nothing is pending on this lead');
    expect($return->pitched)->toBe(['Whole exterior, $8,450. Lost: Price.']);
});

test('a lead is never released sooner than seven days after it came back to its salesperson', function () {
    $proposal = pendingBid($this);

    // A return visit on the 20th, after the proposal expired on the 15th. It is run, and the proposal is not presented again.
    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-20', '10:00');
    $this->travelTo('2026-10-20 16:00:00');
    runAppointment($this->lead->fresh(), $this->sam);

    // The offer has expired; the salesperson's time with the Lead has not (plan, 5.5: they are separate).
    expect($proposal->fresh()->valid_until->toDateString())->toBe('2026-10-15');
    expect(ProposalValidity::ownershipUntil($this->lead->fresh())->toDateString())->toBe('2026-10-27');

    expect(releaseToRehash($this, '2026-10-21 15:00:00')['released'])->toBe(0);
    expect(releaseToRehash($this, '2026-10-27 15:00:00')['released'])->toBe(0);
    expect(releaseToRehash($this, '2026-10-28 15:00:00')['released'])->toBe(1);
});

test('a lead whose salesperson is gone is still released, with nobody to tell or to take it back', function () {
    pendingBid($this);
    $this->lead->fresh()->forceFill(['assigned_user_id' => null, 'assigned_at' => null])->save();

    expect(releaseToRehash($this)['released'])->toBe(1);
    expect($this->lead->fresh()->only(['status', 'released_from_user_id']))->toBe(['status' => LeadStatus::Qualified, 'released_from_user_id' => null]);
    expect($this->lead->fresh()->isInRehash())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The call center works it
|--------------------------------------------------------------------------
*/

test('only the rehash button hands the lead out', function () {
    pendingBid($this);
    releaseToRehash($this);

    $due = app(DueQueue::class);

    expect($due->dueNowCount($this->agent, now(), CallQueue::Rehash))->toBe(1);
    expect($due->dueNowCount($this->agent, now(), CallQueue::Main))->toBe(0);
    expect($due->dueNowCount($this->agent, now(), CallQueue::CancelledAppointments))->toBe(0);

    expect(nextLead($this->agent, CallQueue::Main)->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(nextLead($this->agent, CallQueue::CancelledAppointments)->status)->toBe(QueueClaim::NOTHING_DUE);

    $claim = nextLead($this->agent, CallQueue::Rehash);

    expect($claim->given())->toBeTrue();
    expect($claim->lead->id)->toBe($this->lead->id);
});

test('booked again from rehash it is issued again, to any salesperson, and its proposals go with it', function () {
    $proposal = pendingBid($this);
    releaseToRehash($this);

    // The call center books the return visit with Riley.
    bookAppointment($this->lead->fresh(), $this->riley, $this->agent, '2026-10-20', '10:00');

    $lead = $this->lead->fresh();

    expect($lead->only(['status', 'assigned_user_id', 'released_from_user_id']))->toBe(['status' => LeadStatus::AppointmentBooked, 'assigned_user_id' => $this->riley->id, 'released_from_user_id' => null]);
    expect($lead->isInRehash())->toBeFalse();
    expect(SalesReturn::of($lead))->toBeNull();

    // A new Lead Issued, for Riley, as a cancelled Lead that is rebooked (decision 137, answer 7).
    expect(LeadIssue::query()->where('lead_id', $lead->id)->counted()->orderBy('id')->pluck('salesperson_id')->all())->toBe([$this->sam->id, $this->riley->id]);

    // Riley runs it, presents the same proposal again and sells it: seven more days, then Riley's sale.
    $this->travelTo('2026-10-20 16:00:00');
    $visit = runAppointment($lead, $this->riley);
    app(PresentProposalAgain::class)->handle($this->lead->fresh(), $proposal, $visit->id, $this->riley);

    expect($proposal->fresh()->valid_until->toDateString())->toBe('2026-10-27');

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', [], $this->riley);

    expect(SaleEntry::query()->sole()->only(['salesperson_id', 'is_lead_win']))->toBe(['salesperson_id' => $this->riley->id, 'is_lead_win' => true]);
});

/*
|--------------------------------------------------------------------------
| Taking it back
|--------------------------------------------------------------------------
*/

test('the salesperson it was released from takes it back as appointment run, and its time starts again', function () {
    $proposal = pendingBid($this);
    releaseToRehash($this);

    expect(fn () => app(TakeLeadBackFromContactCenter::class)->handle($this->lead->fresh(), 'They called me.', $this->riley))->toThrow(AuthorizationException::class);

    app(TakeLeadBackFromContactCenter::class)->handle($this->lead->fresh(), 'The homeowner called me back.', $this->sam);

    $lead = $this->lead->fresh();

    // Appointment run, not Appointment cancelled: it left with a visit run and a bid pending.
    expect($lead->only(['status', 'assigned_user_id', 'released_from_user_id', 'returned_from_sales_at']))
        ->toBe(['status' => LeadStatus::AppointmentRun, 'assigned_user_id' => $this->sam->id, 'released_from_user_id' => null, 'returned_from_sales_at' => null]);
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->isInQueue())->toBeFalse();
    // The same issue: nothing is added to Leads Issued.
    expect(LeadIssue::query()->where('lead_id', $lead->id)->count())->toBe(1);

    $event = AuditEvent::query()->where('action', AuditAction::LeadTakenBackBySales)->sole();

    expect($event->values_after)->toMatchArray(['status' => 'appointment_run', 'salesperson' => 'Sam Sales']);

    // The offer is still expired, and Sam has seven days from today all the same.
    expect($proposal->fresh()->valid_until->toDateString())->toBe('2026-10-15');
    expect(ProposalValidity::ownershipUntil($lead)->toDateString())->toBe('2026-10-23');
    expect(releaseToRehash($this, '2026-10-17 15:00:00')['released'])->toBe(0);
    expect(releaseToRehash($this, '2026-10-24 15:00:00')['released'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The customer says no
|--------------------------------------------------------------------------
*/

test('the call center marks the lead lost with a sales loss reason, and it leaves the queue', function () {
    $proposal = pendingBid($this);
    releaseToRehash($this);
    $this->sam->notifications()->delete();

    // The setter has it on their card when the customer says no.
    nextLead($this->agent, CallQueue::Rehash);

    app(MarkLeadLost::class)->fromRehash($this->lead->fresh(), ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id, 'note' => 'Went with a cheaper quote.'], $this->agent);

    $lead = $this->lead->fresh();

    expect($lead->only(['status', 'lost_by_name', 'lost_reason_name', 'lost_objection_name', 'lost_note']))
        ->toBe(['status' => LeadStatus::Lost, 'lost_by_name' => 'Casey Caller', 'lost_reason_name' => 'Price', 'lost_objection_name' => 'Wants to think it over', 'lost_note' => 'Went with a cheaper quote.']);
    // The bid is lost with the same reason, so it is counted with every other lost bid (decision 137, answer 6).
    expect($proposal->fresh()->only(['result', 'loss_reason_name', 'result_by_name']))->toBe(['result' => ProposalResult::Lost, 'loss_reason_name' => 'Price', 'result_by_name' => 'Casey Caller']);

    // Out of the queue, and the setter's hold with it.
    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($cadence->isInQueue())->toBeFalse();
    expect($cadence->held_by_user_id)->toBeNull();
    expect($lead->isInRehash())->toBeFalse();

    $event = AuditEvent::query()->where('action', AuditAction::LeadMarkedLost)->sole();

    expect($event->actor_id)->toBe($this->agent->id);
    expect($event->values_before)->toMatchArray(['status' => 'qualified']);
    expect($event->values_after)->toMatchArray(['status' => 'lost', 'reason' => 'Price', 'proposals_lost' => ['Whole exterior'], 'from_rehash' => true]);

    // The salesperson it came from is told.
    expect($this->sam->notifications()->sole()->data['message'])->toBe('A lead that went from you to rehash was marked lost by the Contact Center.');
});

test('who may mark a rehash lead lost', function (Closure $who, bool $allowed) {
    pendingBid($this);
    releaseToRehash($this);

    $mark = fn () => app(MarkLeadLost::class)->fromRehash($this->lead->fresh(), ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $who($this));

    if ($allowed) {
        $mark();

        expect($this->lead->fresh()->status)->toBe(LeadStatus::Lost);

        return;
    }

    expect($mark)->toThrow(AuthorizationException::class);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
})->with([
    'a Call Center Agent' => [fn ($test) => $test->agent, true],
    'a manager at the location' => [fn ($test) => $test->manager, true],
    'the salesperson it was released from' => [fn ($test) => $test->sam, false],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('marking lost from rehash is refused without a reason, and for a lead that is not in rehash', function () {
    pendingBid($this);

    // Still with its salesperson: this is not the call center's to say.
    expect(fn () => app(MarkLeadLost::class)->fromRehash($this->lead->fresh(), ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $this->agent))
        ->toThrow(ValidationException::class, 'This lead is no longer in the rehash queue.');

    releaseToRehash($this);

    try {
        app(MarkLeadLost::class)->fromRehash($this->lead->fresh(), ['objectionId' => $this->thinking->id], $this->agent);

        $this->fail('It was accepted without a reason.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe(['lossReasonId']);
    }

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect($this->lead->fresh()->isInRehash())->toBeTrue();

    // An ordinary Lead in the call queue is not a rehash.
    $ordinary = dueLead($this->lakeview);

    expect(fn () => app(MarkLeadLost::class)->fromRehash($ordinary, ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $this->agent))
        ->toThrow(ValidationException::class, 'This lead is no longer in the rehash queue.');
});

test('a manager undoes it, and the lead is back in the rehash queue with its bid pending', function () {
    $proposal = pendingBid($this);
    releaseToRehash($this);

    app(MarkLeadLost::class)->fromRehash($this->lead->fresh(), ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $this->agent);

    $this->travelTo('2026-10-19 15:00:00');
    app(UndoLeadLost::class)->handle($this->lead->fresh(), 'They called back after all.', $this->manager);

    $lead = $this->lead->fresh();

    // Nobody had it, so it is the call center's again, not a Lead left with no owner.
    expect($lead->only(['status', 'assigned_user_id', 'released_from_user_id']))->toBe(['status' => LeadStatus::Qualified, 'assigned_user_id' => null, 'released_from_user_id' => $this->sam->id]);
    expect($lead->isInRehash())->toBeTrue();
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->only(['queue', 'state']))->toBe(['queue' => CallQueue::Rehash, 'state' => CadenceState::InQueue]);
    expect($proposal->fresh()->result)->toBe(ProposalResult::Pending);

    $event = AuditEvent::query()->where('action', AuditAction::LeadLostUndone)->sole();

    expect($event->values_after)->toMatchArray(['status' => 'qualified', 'proposals_pending_again' => ['Whole exterior']]);
});
