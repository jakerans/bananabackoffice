<?php

use App\Actions\ContactCenter\ChangeContactCenterSettings;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\EditProposal;
use App\Actions\Proposals\MarkLeadLost;
use App\Actions\Proposals\MarkProposalAddedInError;
use App\Actions\Proposals\PresentProposalAgain;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Proposals\UndoLeadLost;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ContactCenterSettings;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Proposals\ProposalValidity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * The clock on a pending proposal (docs/slice-9-pending-rehash-plan.md, step
 * 9a; product-architecture.md, 5.5; decision 137): a proposal is valid for
 * seven days from the day it was last presented, a salesperson may move the
 * day, and the Lead stays with them until the last pending one expires. What
 * happens when it does is step 9b, in RehashReleaseTest.
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales. Tests start at 4:35 pm that day, with the appointment marked run.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-08 20:35:00');
    runAppointment($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * The day a proposal is valid until, as "2026-10-15".
 */
function validUntil(Proposal $proposal): ?string
{
    return $proposal->fresh()->valid_until?->toDateString();
}

/**
 * Change only the day a proposal is valid until.
 */
function moveValidUntil(Proposal $proposal, string $day, User $by): void
{
    $proposal = $proposal->fresh();

    app(EditProposal::class)->handle($proposal->lead, $proposal, $proposal->name, ltrim($proposal->price(), '$'), $by, validUntil: $day);
}

/*
|--------------------------------------------------------------------------
| The day a proposal is valid until
|--------------------------------------------------------------------------
*/

test('a proposal is valid for seven days from the day it was presented', function () {
    $proposal = addProposal($this->lead, $this->sam);

    expect(validUntil($proposal))->toBe('2026-10-15');
    expect(ProposalValidity::daysLeft($proposal->fresh(), $this->lakeview))->toBe(7);
    expect(ProposalValidity::ownershipUntil($this->lead)->toDateString())->toBe('2026-10-15');
});

test('the number of days is the one in the settings when it is presented, and a proposal already pending keeps its day', function () {
    $first = addProposal($this->lead, $this->sam);

    saveQueueSettings(['proposal_valid_days' => 10]);

    $second = addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);

    expect([validUntil($first), validUntil($second)])->toBe(['2026-10-15', '2026-10-18']);
});

test('a proposal typed in late is valid until today at the earliest, so it is never expired the moment it is saved', function () {
    // Twelve days after the appointment: seven days from the visit has already passed.
    $this->travelTo('2026-10-20 15:00:00');

    $proposal = addProposal($this->lead, $this->sam);

    expect(validUntil($proposal))->toBe('2026-10-20');
    expect(ProposalValidity::daysLeft($proposal->fresh(), $this->lakeview))->toBe(0);
});

test('the last day ends at midnight in the location\'s own time', function () {
    $proposal = addProposal($this->lead, $this->sam);

    // 11:30 pm on the 15th at Lakeview is already the 16th in UTC.
    $this->travelTo('2026-10-16 03:30:00');

    expect(ProposalValidity::daysLeft($proposal->fresh(), $this->lakeview))->toBe(0);
    expect(ProposalValidity::label(ProposalValidity::of($proposal->fresh()), $this->lakeview))->toBe('Valid until today, Thu, Oct 15 · last day');

    $this->travelTo('2026-10-16 04:30:00');

    expect(ProposalValidity::daysLeft($proposal->fresh(), $this->lakeview))->toBe(-1);
    expect(ProposalValidity::label(ProposalValidity::of($proposal->fresh()), $this->lakeview))->toBe('Expired yesterday, Thu, Oct 15');
});

test('presented again at a return visit, a pending proposal gets seven more days from that visit, and never fewer', function () {
    $exterior = addProposal($this->lead, $this->sam);
    $deck = addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);

    // A manager gave the deck a long time; the return visit must not cut it short.
    moveValidUntil($deck, '2026-12-01', $this->manager);

    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00');
    $this->travelTo('2026-10-12 16:00:00');
    $visit = runAppointment($this->lead->fresh(), $this->sam);

    app(PresentProposalAgain::class)->handle($this->lead->fresh(), $exterior, $visit->id, $this->sam);
    app(PresentProposalAgain::class)->handle($this->lead->fresh(), $deck, $visit->id, $this->sam);

    expect([validUntil($exterior), validUntil($deck)])->toBe(['2026-10-19', '2026-12-01']);
});

test('a lead stays with its salesperson until the last of its pending proposals expires', function () {
    $exterior = addProposal($this->lead, $this->sam);
    $deck = addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);

    moveValidUntil($deck, '2026-10-22', $this->sam);

    expect(ProposalValidity::ownershipUntil($this->lead)->toDateString())->toBe('2026-10-22');

    // Lost, the deck no longer holds the Lead: only what is pending does.
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $deck, 'lost', [
        'lossReasonId' => SalesLossReason::query()->where('name', 'Price')->sole()->id,
        'objectionId' => Objection::query()->where('name', 'Wants to think it over')->sole()->id,
    ], $this->sam);

    expect(ProposalValidity::ownershipUntil($this->lead)->toDateString())->toBe('2026-10-15');

    // One marked "added in error" counts for nothing here either. With nothing pending the Lead still has
    // the same seven days, from when its last proposal was answered (decision 137, answer 5).
    app(MarkProposalAddedInError::class)->handle($this->lead->fresh(), $exterior, 'Wrong lead.', $this->manager);

    expect(ProposalValidity::latestPending($this->lead))->toBeNull();
    expect(ProposalValidity::ownershipUntil($this->lead)->toDateString())->toBe('2026-10-15');
});

/*
|--------------------------------------------------------------------------
| Changing the day
|--------------------------------------------------------------------------
*/

test('the salesperson moves the day, and what it was is kept', function () {
    $proposal = addProposal($this->lead, $this->sam);
    $version = $this->lead->fresh()->lifecycle_version;

    moveValidUntil($proposal, '2026-10-30', $this->sam);

    expect(validUntil($proposal))->toBe('2026-10-30');
    // Nothing about the Lead changes.
    expect($this->lead->fresh()->lifecycle_version)->toBe($version);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->values_before)->toMatchArray(['valid_until' => '2026-10-15']);
    expect($event->values_after)->toMatchArray(['valid_until' => '2026-10-30']);

    // The same day again writes nothing.
    moveValidUntil($proposal, '2026-10-30', $this->sam);

    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->count())->toBe(1);
});

test('a day is refused when it is not a day, is in the past, or is further than a salesperson may go', function (string $day, string $message) {
    $proposal = addProposal($this->lead, $this->sam);

    try {
        moveValidUntil($proposal, $day, $this->sam);

        $this->fail('The day was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['proposalValidUntil' => [$message]]);
    }

    expect(validUntil($proposal))->toBe('2026-10-15');
    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->count())->toBe(0);
})->with([
    'words' => ['next week', 'Choose the day the proposal is valid until.'],
    'a day that does not exist' => ['2026-02-31', 'Choose the day the proposal is valid until.'],
    'yesterday' => ['2026-10-07', 'Choose today or a later day.'],
    'thirty-one days ahead' => ['2026-11-08', 'You can move it up to 30 days ahead, to Nov 7, 2026. A manager can set a later day.'],
]);

test('a salesperson may go thirty days ahead and a manager further (decision 137)', function () {
    $proposal = addProposal($this->lead, $this->sam);

    moveValidUntil($proposal, '2026-11-07', $this->sam);

    expect(validUntil($proposal))->toBe('2026-11-07');

    moveValidUntil($proposal, '2027-01-15', $this->manager);

    expect(validUntil($proposal))->toBe('2027-01-15');

    // The salesperson can still change the name while the manager's later day stands.
    app(EditProposal::class)->handle($this->lead->fresh(), $proposal->fresh(), 'Whole exterior, two coats', '8450', $this->sam, validUntil: '2027-01-15');

    expect($proposal->fresh()->only(['name']))->toBe(['name' => 'Whole exterior, two coats']);
    expect(validUntil($proposal))->toBe('2027-01-15');

    // But cannot move it to another day that far out.
    expect(fn () => moveValidUntil($proposal, '2027-01-16', $this->sam))->toThrow(ValidationException::class, 'You can move it up to 30 days ahead');
});

test('only the lead\'s salesperson or a manager there may move the day, and only while it is pending', function () {
    $proposal = addProposal($this->lead, $this->sam);
    $other = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    expect(fn () => moveValidUntil($proposal, '2026-10-20', $other))->toThrow(AuthorizationException::class);

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', [], $this->sam);

    expect(fn () => moveValidUntil($proposal, '2026-10-20', $this->manager))->toThrow(ValidationException::class, 'Only a proposal that is still pending can be changed.');
    expect(validUntil($proposal))->toBe('2026-10-15');
});

test('a proposal that a correction makes pending again starts its days again from that day', function () {
    $proposal = addProposal($this->lead, $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', [], $this->sam);

    $this->travelTo('2026-10-20 15:00:00');
    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $proposal, 'The customer backed out.', $this->manager);

    expect(validUntil($proposal))->toBe('2026-10-27');

    // The same when a Lead that was marked lost is not lost after all.
    app(MarkLeadLost::class)->handle($this->lead->fresh(), [
        'lossReasonId' => SalesLossReason::query()->where('name', 'Price')->sole()->id,
        'objectionId' => Objection::query()->where('name', 'Wants to think it over')->sole()->id,
    ], $this->sam);

    $this->travelTo('2026-11-03 15:00:00');
    app(UndoLeadLost::class)->handle($this->lead->fresh(), 'They called back.', $this->manager);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
    expect(validUntil($proposal))->toBe('2026-11-10');
});

/*
|--------------------------------------------------------------------------
| The warning
|--------------------------------------------------------------------------
*/

test('the salesperson is warned in the bell two days before the last day, once', function () {
    addProposal($this->lead, $this->sam);
    $this->sam->notifications()->delete();

    // Three days left: too early.
    $this->travelTo('2026-10-12 15:00:00');

    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(0);

    $this->travelTo('2026-10-13 15:00:00');

    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(1);

    $notice = $this->sam->notifications()->sole();

    expect($notice->data['kind'])->toBe(AppointmentChanged::PENDING_RELEASE_WARNING);
    expect($notice->data['message'])->toBe('A lead of yours stays with you for 2 more days. It then goes to the Contact Center for rehash, unless a return visit is booked or it is sold or lost.');
    expect($notice->data['url'])->toBe(route('leads.show', $this->lead));
    // Nothing about the customer.
    expect($notice->data['message'])->not->toContain('Pat Customer');
    expect($this->lead->fresh()->pending_warned_for->toDateString())->toBe('2026-10-15');

    // Not again the next minute, the next day or on the last day.
    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(0);

    $this->travelTo('2026-10-15 15:00:00');

    expect(app(RunQueueTick::class)->handle())->toMatchArray(['warned' => 0, 'released' => 0, 'failed' => 0]);
    expect($this->sam->notifications()->count())->toBe(1);
    // On its last day the Lead is still Sam's. What happens the day after is in RehashReleaseTest.
    expect($this->lead->fresh()->only(['status', 'assigned_user_id']))->toBe(['status' => LeadStatus::AppointmentRun, 'assigned_user_id' => $this->sam->id]);
});

test('when the day is moved on, the salesperson is warned again as the new one comes near', function () {
    $proposal = addProposal($this->lead, $this->sam);
    $this->sam->notifications()->delete();

    $this->travelTo('2026-10-14 15:00:00');

    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(1);
    expect($this->sam->notifications()->sole()->data['message'])->toStartWith('Tomorrow is the last day a lead of yours stays with you.');

    moveValidUntil($proposal, '2026-10-24', $this->sam);

    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(0);

    $this->travelTo('2026-10-24 15:00:00');

    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(1);
    expect($this->sam->notifications()->latest('created_at')->first()->data['message'])->toStartWith('Today is the last day a lead of yours stays with you.');
});

test('nobody is warned about a lead that is not about to leave them', function (Closure $arrange) {
    $proposal = addProposal($this->lead, $this->sam);

    $arrange($this, $proposal);

    $this->sam->notifications()->delete();
    $this->travelTo('2026-10-14 15:00:00');

    expect(app(RunQueueTick::class)->handle()['warned'])->toBe(0);
    expect($this->sam->notifications()->get()->where('data.kind', AppointmentChanged::PENDING_RELEASE_WARNING)->count())->toBe(0);
})->with([
    'a return visit is booked' => [fn ($test, $proposal) => bookAppointment($test->lead->fresh(), $test->sam, $test->sam, '2026-10-20', '10:00')],
    'it is sold' => [fn ($test, $proposal) => app(RecordProposalResult::class)->handle($test->lead->fresh(), $proposal, 'sold', [], $test->sam)],
    'another pending proposal runs later' => [fn ($test, $proposal) => moveValidUntil(addProposal($test->lead->fresh(), $test->sam, ['name' => 'Deck', 'price' => '1800']), '2026-10-30', $test->sam)],
]);

/*
|--------------------------------------------------------------------------
| The setting, and proposals that were pending before the clock existed
|--------------------------------------------------------------------------
*/

test('the number of days can be changed by the people who change the contact center settings, within its limits', function () {
    $change = app(ChangeContactCenterSettings::class);
    $version = ContactCenterSettings::current()->version;

    expect(ContactCenterSettings::current()->proposal_valid_days)->toBe(7);
    expect(fn () => $change->proposalValidDays($this->sam, $version, 10))->toThrow(AuthorizationException::class);

    foreach ([0, 91, 'soon', 7.5] as $days) {
        expect(fn () => $change->proposalValidDays($this->leader, $version, $days))
            ->toThrow(ValidationException::class, 'The number of days must be a whole number from 1 to 90.');
    }

    expect($change->proposalValidDays($this->leader, $version, 7))->toBeFalse();
    expect($change->proposalValidDays($this->leader, $version, 10))->toBeTrue();

    $settings = ContactCenterSettings::current();

    expect([$settings->version, $settings->proposal_valid_days])->toBe([$version + 1, 10]);

    $event = AuditEvent::query()->where('action', AuditAction::ContactCenterProposalValidDaysChanged)->sole();

    expect($event->values_before)->toBe(['proposal_valid_days' => 7]);
    expect($event->values_after)->toBe(['proposal_valid_days' => 10]);

    // Saving another section afterwards keeps the number.
    $change->holdLength($this->leader, $settings->version, 9);

    expect(ContactCenterSettings::current()->proposal_valid_days)->toBe(10);
});

test('proposals that were pending before the clock existed are given seven days from the day it is installed', function () {
    $pending = addProposal($this->lead, $this->sam);
    $sold = addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $sold, 'sold', [], $this->sam);

    // As they were before this step.
    DB::table('proposals')->update(['valid_until' => null]);

    $this->travelTo('2026-10-21 15:00:00');

    $step = require database_path('migrations/2026_10_13_172038_add_proposal_validity.php');
    $step->up();

    expect(validUntil($pending))->toBe('2026-10-28');
    // One that is not pending has nothing to expire.
    expect(validUntil($sold))->toBeNull();

    // Run again, it changes nothing.
    $this->travelTo('2026-10-23 15:00:00');
    $step->up();

    expect(validUntil($pending))->toBe('2026-10-28');
});
