<?php

use App\Actions\Proposals\SubmitAfterActionReport;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * The after-action report: one short form, filled in straight after an
 * appointment, that says what happened (product-architecture.md, 5.6;
 * docs/slice-7-appointment-results-plan.md, step 7c).
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales. Tests start at 4:35 pm that day, just after it ended, with nothing
 * said about it yet.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->thinking = Objection::query()->where('name', 'Wants to think it over')->sole();

    $this->lead = bookableLead($this->lakeview);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-08 20:35:00');
    $this->lead = $this->lead->fresh();
});

/**
 * Send the report through the action.
 *
 * @param  array<string, mixed>  $report
 */
function submitReport(Lead $lead, Appointment $appointment, User $by, array $report): void
{
    app(SubmitAfterActionReport::class)->handle($lead, $appointment, $report, $by);
}

/**
 * Check that nothing at all was saved by a report that was refused.
 */
function expectNothingSaved(): void
{
    expect(test()->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect(test()->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(test()->lead->proposals()->count())->toBe(0);
    expect(AuditEvent::query()->whereIn('action', [AuditAction::LeadAppointmentRun, AuditAction::LeadAppointmentNoShow, AuditAction::LeadProposalAdded])->count())->toBe(0);
}

test('a no-show is the whole report', function () {
    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'no_show', 'note' => 'Nobody home.', 'presented' => 'yes', 'proposals' => [['name' => 'Ignored', 'price' => '100', 'result' => 'sold']]]);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($this->appointment->fresh()->state_note)->toBe('Nobody home.');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    // Whatever else was sent with a no-show is not used.
    expect(Proposal::query()->count())->toBe(0);
});

test('run with nothing presented ends the report, and the appointment counts as run with no proposal', function () {
    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'no', 'note' => 'They want to wait until spring.']);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->state_note)->toBe('They want to wait until spring.');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
    expect($this->appointment->proposals()->count())->toBe(0);
});

test('run, one proposal, sold: the appointment, the proposal, its result and the lead in one go', function () {
    submitReport($this->lead, $this->appointment, $this->sam, [
        'outcome' => 'run',
        'note' => 'Signed on the spot.',
        'presented' => 'yes',
        'proposals' => [['name' => 'Whole exterior', 'price' => '8,450', 'result' => 'sold', 'soldAmount' => '8,200']],
    ]);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->state_changed_by_name)->toBe('Sam Sales');

    $proposal = Proposal::query()->sole();

    expect($proposal->only(['name', 'price_cents', 'sold_cents', 'created_by_name', 'result_by_name']))
        ->toBe(['name' => 'Whole exterior', 'price_cents' => 845000, 'sold_cents' => 820000, 'created_by_name' => 'Sam Sales', 'result_by_name' => 'Sam Sales']);
    expect($proposal->result)->toBe(ProposalResult::Sold);
    expect($proposal->appointments()->sole()->id)->toBe($this->appointment->id);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);

    // The same records the buttons write, each once.
    foreach ([AuditAction::LeadAppointmentRun, AuditAction::LeadProposalAdded, AuditAction::LeadProposalSold] as $action) {
        expect(AuditEvent::query()->where('action', $action)->count())->toBe(1);
    }
});

test('several proposals, each with its own result', function () {
    submitReport($this->lead, $this->appointment, $this->sam, [
        'outcome' => 'run',
        'presented' => 'yes',
        'proposals' => [
            ['name' => 'Whole exterior', 'price' => '8450', 'result' => 'pending', 'objectionId' => $this->thinking->id],
            ['name' => 'Cabinets only', 'price' => '3200', 'result' => 'lost', 'lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id, 'note' => 'Too much for cabinets.'],
        ],
    ]);

    [$exterior, $cabinets] = Proposal::query()->orderBy('id')->get()->all();

    expect($exterior->result)->toBe(ProposalResult::Pending);
    expect($exterior->objection_name)->toBe('Wants to think it over');
    expect($cabinets->result)->toBe(ProposalResult::Lost);
    expect($cabinets->only(['loss_reason_name', 'result_note']))->toBe(['loss_reason_name' => 'Price', 'result_note' => 'Too much for cabinets.']);
    expect($this->appointment->proposals()->count())->toBe(2);
    // Nothing sold and the Lead not marked lost: it stays with its salesperson.
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('a return visit: a proposal from the first visit is presented again and sold, beside a new one', function () {
    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [
        ['name' => 'Whole exterior', 'price' => '8450', 'result' => 'pending', 'objectionId' => $this->thinking->id],
        ['name' => 'Deck', 'price' => '1800', 'result' => 'pending', 'objectionId' => $this->thinking->id],
    ]]);

    [$exterior, $deck] = Proposal::query()->orderBy('id')->get()->all();

    $second = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00')->appointment;
    $this->travelTo('2026-10-12 15:35:00');

    submitReport($this->lead->fresh(), $second, $this->sam, [
        'outcome' => 'run',
        'presented' => 'yes',
        'existing' => [
            $exterior->id => ['presented' => true, 'result' => 'sold'],
            // Not presented at this visit: left exactly as it was, whatever else is sent.
            $deck->id => ['presented' => false, 'result' => 'sold'],
        ],
        'proposals' => [['name' => 'Gutters', 'price' => '900', 'result' => 'sold']],
    ]);

    expect($exterior->fresh()->result)->toBe(ProposalResult::Sold);
    expect($exterior->appointments()->pluck('appointments.id')->all())->toBe([$this->appointment->id, $second->id]);
    expect($deck->fresh()->result)->toBe(ProposalResult::Pending);
    expect($deck->appointments()->count())->toBe(1);
    expect(Proposal::query()->where('name', 'Gutters')->sole()->result)->toBe(ProposalResult::Sold);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('the report is saved whole or not at all', function (Closure $report, string $field, string $message) {
    try {
        submitReport($this->lead, $this->appointment, $this->sam, $report($this));

        $this->fail('The report was not refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toHaveKey($field);
        expect($refusal->errors()[$field][0])->toContain($message);
    }

    expectNothingSaved();
})->with([
    'no answer about the appointment' => [fn ($test) => ['presented' => 'no'], 'closeOutOutcome', 'Say whether the appointment was run or was a no-show.'],
    'run, and nothing said about what was presented' => [fn ($test) => ['outcome' => 'run'], 'presented', 'Say whether anything was presented.'],
    'something was presented, and nothing is listed' => [fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => []], 'presented', 'Add what was presented, or answer that nothing was.'],
    'a proposal with no name' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [['name' => ' ', 'price' => '8450', 'result' => 'sold']]],
        'proposals.0.proposalName', 'Say what was pitched.',
    ],
    'the second proposal has a price that is not an amount' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [
            ['name' => 'Whole exterior', 'price' => '8450', 'result' => 'sold'],
            ['name' => 'Cabinets only', 'price' => 'about three', 'result' => 'sold'],
        ]],
        'proposals.1.proposalPrice', 'Enter the price in dollars',
    ],
    'a proposal with no result' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [['name' => 'Whole exterior', 'price' => '8450']]],
        'proposals.0.proposalResult', 'Say whether it is sold, lost or still pending.',
    ],
    'still pending with no objection' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [['name' => 'Whole exterior', 'price' => '8450', 'result' => 'pending']]],
        'proposals.0.objectionId', 'Choose the main objection',
    ],
    'lost with no reason, after a proposal that was fine' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [
            ['name' => 'Whole exterior', 'price' => '8450', 'result' => 'sold'],
            ['name' => 'Cabinets only', 'price' => '3200', 'result' => 'lost', 'objectionId' => $test->thinking->id],
        ]],
        'proposals.1.lossReasonId', 'Choose why it was lost.',
    ],
    'a sold amount that is not an amount' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [['name' => 'Whole exterior', 'price' => '8450', 'result' => 'sold', 'soldAmount' => 'less']]],
        'proposals.0.soldAmount', 'Enter the sold amount in dollars',
    ],
]);

test('a proposal from another lead cannot be reported on', function () {
    $other = bookableLead($this->lakeview);
    Appointment::factory()->forLead($other)->with($this->sam)->create(['state' => AppointmentState::Run]);
    $theirs = Proposal::factory()->forLead($other)->create();

    expect(fn () => submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'yes', 'existing' => [$theirs->id => ['presented' => true, 'result' => 'sold']]]))
        ->toThrow(ValidationException::class, 'This proposal is no longer here.');

    expectNothingSaved();
    expect($theirs->fresh()->result)->toBe(ProposalResult::Pending);
});

test('who may send the report', function (Closure $person, bool $allowed) {
    $report = ['outcome' => 'run', 'presented' => 'yes', 'proposals' => [['name' => 'Whole exterior', 'price' => '8450', 'result' => 'sold']]];
    $actor = $person($this);

    if ($allowed) {
        submitReport($this->lead, $this->appointment, $actor, $report);

        expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);

        return;
    }

    expect(fn () => submitReport($this->lead, $this->appointment, $actor, $report))->toThrow(AuthorizationException::class);
    expectNothingSaved();
})->with([
    'the salesperson whose appointment it is' => [fn ($test) => $test->sam, true],
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
    'another Sales Consultant there' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->create(), false],
]);

test('the report follows the rules of the buttons: not before the start, once, and the oldest first', function () {
    $this->travelTo('2026-10-08 18:00:00');

    expect(fn () => submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'no']))
        ->toThrow(ValidationException::class, 'This appointment has not started yet.');

    // A later appointment of Sam's, also over, waits for this one.
    $later = bookableLead($this->lakeview);
    $second = bookAppointment($later, $this->sam, $this->manager, '2026-10-08', '17:00', 30)->appointment;
    $this->travelTo('2026-10-08 22:00:00');

    expect(fn () => submitReport($later->fresh(), $second, $this->sam, ['outcome' => 'run', 'presented' => 'no']))
        ->toThrow(ValidationException::class, 'Close out your earlier appointment first');

    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'no']);

    expect(fn () => submitReport($this->lead->fresh(), $this->appointment, $this->sam, ['outcome' => 'no_show']))
        ->toThrow(ValidationException::class, 'This appointment is no longer waiting for an answer.');
});

test('a lead that changed since the report was opened is refused', function () {
    expect(fn () => app(SubmitAfterActionReport::class)->handle($this->lead, $this->appointment, ['outcome' => 'run', 'presented' => 'no'], $this->sam, $this->lead->lifecycle_version - 1))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expectNothingSaved();
});
