<?php

use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Proposals\AddProposal;
use App\Actions\Proposals\PresentProposalAgain;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Customers\CustomerActivity;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Proposals: what was pitched and for how much, tied to the run appointment
 * or appointments it was presented at
 * (docs/slice-7-appointment-results-plan.md, step 7b; decisions 121, 124 and
 * 125).
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales, and Sam has marked it run. Tests start at 3:00 pm that day.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    $this->appointment = runAppointment($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * Book the Lead a return visit and mark it run, a few days after the first.
 */
function returnVisit(Lead $lead, User $salesperson, string $date = '2026-10-12'): Appointment
{
    bookAppointment($lead->fresh(), $salesperson, $salesperson, $date, '10:00');

    test()->travelTo($date.' 14:00:00');

    return runAppointment($lead->fresh(), $salesperson);
}

/*
|--------------------------------------------------------------------------
| Adding a proposal
|--------------------------------------------------------------------------
*/

test('adding a proposal records what was pitched, the price and the appointment, and changes nothing about the lead', function () {
    $before = $this->lead->only(['status', 'assigned_user_id', 'lifecycle_version', 'status_changed_at']);

    $proposal = addProposal($this->lead, $this->sam, ['name' => '  Whole exterior  ', 'price' => '8,450']);

    expect($proposal->name)->toBe('Whole exterior');
    expect($proposal->price_cents)->toBe(845000);
    expect($proposal->price())->toBe('$8,450');
    expect($proposal->only(['lead_id', 'location_id', 'account_id', 'property_id']))->toBe([
        'lead_id' => $this->lead->id,
        'location_id' => $this->lakeview->id,
        'account_id' => $this->lead->account_id,
        'property_id' => $this->lead->property_id,
    ]);
    expect($proposal->only(['created_by', 'created_by_name']))->toBe(['created_by' => $this->sam->id, 'created_by_name' => 'Sam Sales']);

    $presentedAt = $proposal->appointments()->sole();

    expect($presentedAt->id)->toBe($this->appointment->id);
    expect($presentedAt->pivot->added_by_name)->toBe('Sam Sales');

    // The Lead is as it was: same status, same person, same revision.
    expect($this->lead->fresh()->only(['status', 'assigned_user_id', 'lifecycle_version', 'status_changed_at']))->toEqual($before);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalAdded)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->values_after)->toBe(['proposal' => 'Whole exterior', 'price' => '$8,450', 'presented_at' => ['2026-10-08T19:00:00+00:00']]);
    expect(CustomerActivity::actions())->toContain(AuditAction::LeadProposalAdded);
    expect(CustomerActivity::label(AuditAction::LeadProposalAdded))->toBe('Proposal added');
});

test('the price is read as it is typed and kept in cents', function (string $typed, int $cents, string $shown) {
    $proposal = addProposal($this->lead, $this->sam, ['price' => $typed]);

    expect($proposal->price_cents)->toBe($cents);
    expect($proposal->price())->toBe($shown);
})->with([
    'whole dollars' => ['8450', 845000, '$8,450'],
    'with a comma' => ['8,450', 845000, '$8,450'],
    'with a dollar sign and cents' => ['$8,450.50', 845050, '$8,450.50'],
    'one decimal place, with spaces around it' => [' 8450.5 ', 845050, '$8,450.50'],
    'one cent' => ['0.01', 1, '$0.01'],
    'the largest amount accepted' => ['10,000,000', Money::MAX_CENTS, '$10,000,000'],
]);

test('a price that is not plainly an amount is refused and nothing is saved', function (?string $typed) {
    expect(fn () => addProposal($this->lead, $this->sam, ['price' => $typed]))
        ->toThrow(ValidationException::class, 'Enter the price in dollars, like 8450 or 8,450.50.');

    expect(Proposal::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalAdded)->count())->toBe(0);
})->with([
    'nothing' => [null],
    'blank' => ['  '],
    'words' => ['about eight thousand'],
    'zero' => ['0'],
    'below zero' => ['-500'],
    'three decimal places' => ['8450.555'],
    'an exponent' => ['1e5'],
    'no dollars before the point' => ['.50'],
    'above the largest amount' => ['10000000.01'],
]);

test('a proposal needs a name of a sensible length', function (?string $name, string $message) {
    expect(fn () => addProposal($this->lead, $this->sam, ['name' => $name]))->toThrow(ValidationException::class, $message);

    expect(Proposal::query()->count())->toBe(0);
})->with([
    'nothing' => [null, 'Say what was pitched.'],
    'only spaces' => ['   ', 'Say what was pitched.'],
    'too long' => [str_repeat('a', 121), 'Keep the name to 120 characters or fewer.'],
]);

test('a proposal is tied to at least one run appointment of its own lead (decision 125)', function (Closure $appointments, string $message) {
    expect(fn () => addProposal($this->lead, $this->sam, ['appointments' => $appointments($this)]))->toThrow(ValidationException::class, $message);

    expect(Proposal::query()->count())->toBe(0);
})->with([
    'no appointment' => [fn ($test) => [], 'Choose the appointment it was presented at.'],
    'an appointment still to come' => [
        fn ($test) => [bookAppointment($test->lead, $test->sam, $test->sam, '2026-10-12', '10:00')->appointment->id],
        'Choose from this lead\'s appointments that were run.',
    ],
    'a no-show' => [
        function ($test) {
            $missed = bookAppointment($test->lead, $test->sam, $test->sam, '2026-10-12', '10:00')->appointment;
            $missed->forceFill(['state' => AppointmentState::NoShow])->save();

            return [$missed->id];
        },
        'Choose from this lead\'s appointments that were run.',
    ],
    'a run appointment of another lead' => [
        function ($test) {
            $other = bookableLead($test->lakeview);
            $theirs = Appointment::factory()->forLead($other)->with($test->sam)->create(['state' => AppointmentState::Run]);

            return [$test->appointment->id, $theirs->id];
        },
        'Choose from this lead\'s appointments that were run.',
    ],
    'an appointment that does not exist' => [fn ($test) => [987654], 'Choose from this lead\'s appointments that were run.'],
]);

test('who may add a proposal', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        expect(addProposal($this->lead, $actor)->created_by)->toBe($actor->id);

        return;
    }

    expect(fn () => addProposal($this->lead, $actor))->toThrow(AuthorizationException::class);
    expect(Proposal::query()->count())->toBe(0);
})->with([
    'the salesperson the lead is with' => [fn ($test) => $test->sam, true],
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('once the lead has left the salesperson only a manager can still add what was pitched', function () {
    app(SendLeadBackToContactCenter::class)->handle($this->lead, StopCountingLeadAsIssued::ANOTHER_REASON, 'The customer wants a call first.', $this->manager);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect(fn () => addProposal($lead, $this->sam))->toThrow(AuthorizationException::class);

    expect(addProposal($lead, $this->manager)->appointments()->sole()->id)->toBe($this->appointment->id);
});

test('a lead that changed since the dialog was opened is refused', function () {
    expect(fn () => app(AddProposal::class)->handle($this->lead, 'Whole exterior', '8450', [$this->appointment->id], $this->sam, $this->lead->lifecycle_version - 1))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expect(Proposal::query()->count())->toBe(0);
});

test('a proposal cannot be deleted', function () {
    $proposal = addProposal($this->lead, $this->sam);

    expect(fn () => $proposal->delete())->toThrow(LogicException::class, 'A proposal cannot be deleted.');
    expect(Proposal::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Several proposals, several appointments (decision 121)
|--------------------------------------------------------------------------
*/

test('one appointment can have several proposals, and one proposal several appointments', function () {
    $second = returnVisit($this->lead, $this->sam);

    $exterior = addProposal($this->lead->fresh(), $this->sam, ['appointments' => [$this->appointment->id, $second->id]]);
    $cabinets = addProposal($this->lead->fresh(), $this->sam, ['name' => 'Cabinets only', 'price' => '3200', 'appointments' => [$this->appointment->id]]);

    expect($exterior->appointments()->pluck('appointments.id')->all())->toBe([$this->appointment->id, $second->id]);
    expect($this->appointment->proposals()->pluck('proposals.id')->all())->toBe([$exterior->id, $cabinets->id]);
    expect($second->proposals()->pluck('proposals.id')->all())->toBe([$exterior->id]);
    expect($this->lead->proposals()->pluck('name')->all())->toBe(['Whole exterior', 'Cabinets only']);
});

test('a proposal is recorded at a return visit later, once', function () {
    $proposal = addProposal($this->lead, $this->sam);
    $second = returnVisit($this->lead, $this->sam);

    app(PresentProposalAgain::class)->handle($this->lead->fresh(), $proposal, $second->id, $this->sam);

    expect($proposal->appointments()->pluck('appointments.id')->all())->toBe([$this->appointment->id, $second->id]);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalPresentedAgain)->sole();

    expect($event->values_after)->toBe(['proposal' => 'Whole exterior', 'presented_at' => '2026-10-12T14:00:00+00:00']);
    expect(CustomerActivity::label(AuditAction::LeadProposalPresentedAgain))->toBe('Proposal presented again');

    // The same pair is not kept twice.
    expect(fn () => app(PresentProposalAgain::class)->handle($this->lead->fresh(), $proposal, $second->id, $this->sam))
        ->toThrow(ValidationException::class, 'This proposal is already recorded at that appointment.');
    expect($proposal->appointments()->count())->toBe(2);
});

test('a proposal is recorded again only at a run appointment of its own lead, by someone who may', function () {
    $proposal = addProposal($this->lead, $this->sam);
    $upcoming = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00')->appointment;
    $lead = $this->lead->fresh();

    $other = bookableLead($this->lakeview);
    $theirs = Appointment::factory()->forLead($other)->with($this->sam)->create(['state' => AppointmentState::Run]);
    $refusal = 'Choose one of this lead\'s appointments that were run.';

    expect(fn () => app(PresentProposalAgain::class)->handle($lead, $proposal, $upcoming->id, $this->sam))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(PresentProposalAgain::class)->handle($lead, $proposal, $theirs->id, $this->sam))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(PresentProposalAgain::class)->handle($lead, $proposal, null, $this->sam))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(PresentProposalAgain::class)->handle($other->fresh(), $proposal, $theirs->id, $this->manager))->toThrow(ValidationException::class, 'This proposal belongs to another lead.');
    expect(fn () => app(PresentProposalAgain::class)->handle($lead, $proposal, $this->appointment->id, $this->agent))->toThrow(AuthorizationException::class);

    expect($proposal->appointments()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What Demo Rate will read (decision 124)
|--------------------------------------------------------------------------
*/

test('a run appointment counts as presented from the moment a proposal is added, even a day late', function () {
    $presented = fn (): int => Appointment::query()->wereRun()->has('proposals')->count();

    expect(Appointment::query()->wereRun()->count())->toBe(1);
    expect($presented())->toBe(0);

    $this->travelTo('2026-10-09 15:00:00');
    addProposal($this->lead, $this->sam);

    expect($presented())->toBe(1);

    // A second proposal from the same appointment does not make it count twice.
    addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);

    expect($presented())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The salesperson is told when someone else records one (decision 126)
|--------------------------------------------------------------------------
*/

test('the salesperson is told in the app when a manager records a proposal at their appointment', function () {
    Notification::fake();

    $proposal = addProposal($this->lead, $this->manager);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertNotSentTo($this->manager, AppointmentChanged::class);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::PROPOSAL_RECORDED
        && $notice->channel === AppointmentChanged::IN_APP
        && $notice->toArray($this->sam)['message'] === 'A manager recorded a proposal at your appointment on Thu, Oct 8, 2026 at 3:00 PM EDT.');

    $second = returnVisit($this->lead, $this->sam);
    app(PresentProposalAgain::class)->handle($this->lead->fresh(), $proposal, $second->id, $this->manager);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 2);
});

test('the salesperson is not told about a proposal they added themselves', function () {
    Notification::fake();

    $proposal = addProposal($this->lead, $this->sam);
    $second = returnVisit($this->lead, $this->sam);
    app(PresentProposalAgain::class)->handle($this->lead->fresh(), $proposal, $second->id, $this->sam);

    Notification::assertNotSentTo($this->sam, AppointmentChanged::class);
});
