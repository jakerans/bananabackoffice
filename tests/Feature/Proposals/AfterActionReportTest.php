<?php

use App\Actions\Estimates\StartEstimate;
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
 * said about it yet. What was presented is ticked from the Lead's estimates
 * (step 31e; decision 207).
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

    // Built before the visit, as it would be: two options, $8,450 and $3,200 (step 31e).
    $this->exterior = estimateFor($this->lead->fresh(), $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);
    [$this->wholeExterior, $this->frontOnly] = $this->exterior->options->all();

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
    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'no_show', 'note' => 'Nobody home.', 'presented' => 'yes', 'estimates' => [$this->exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => $this->wholeExterior->id]]]);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($this->appointment->fresh()->state_note)->toBe('Nobody home.');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    // Whatever else was sent with a no-show is not used.
    expect(Proposal::query()->count())->toBe(0);
});

test('run with nothing presented ends the report, and the appointment counts as run with no proposal', function () {
    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'no', 'noDemoReason' => 'Only one of the two owners was home.', 'note' => 'They want to wait until spring.']);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->state_note)->toBe('They want to wait until spring.');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
    expect($this->appointment->proposals()->count())->toBe(0);
    // Why nothing was presented is kept with the appointment (decision 133).
    expect($this->appointment->fresh()->no_demo_reason)->toBe('Only one of the two owners was home.');
});

test('run, one estimate presented, sold: the appointment, the estimate, the option chosen and the lead in one go', function () {
    submitReport($this->lead, $this->appointment, $this->sam, [
        'outcome' => 'run',
        'note' => 'Signed on the spot.',
        'presented' => 'yes',
        'estimates' => [$this->exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => $this->frontOnly->id]],
    ]);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->state_changed_by_name)->toBe('Sam Sales');

    $proposal = Proposal::query()->sole();

    // The record of the estimate: its options' range while pending, the option chosen once sold.
    expect($proposal->only(['estimate_id', 'estimate_option_id', 'name', 'price_from_cents', 'price_cents', 'sold_cents', 'created_by_name', 'result_by_name']))
        ->toBe(['estimate_id' => $this->exterior->id, 'estimate_option_id' => $this->frontOnly->id, 'name' => 'Residential Exterior estimate', 'price_from_cents' => 320000, 'price_cents' => 845000, 'sold_cents' => 320000, 'created_by_name' => 'Sam Sales', 'result_by_name' => 'Sam Sales']);
    expect($proposal->result)->toBe(ProposalResult::Sold);
    expect($proposal->price())->toBe('$3,200');
    expect($proposal->appointments()->sole()->id)->toBe($this->appointment->id);
    expect($proposal->project->title)->toStartWith('Front only');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);

    // The same records the buttons write, each once.
    foreach ([AuditAction::LeadAppointmentRun, AuditAction::LeadProposalAdded, AuditAction::LeadProposalSold] as $action) {
        expect(AuditEvent::query()->where('action', $action)->count())->toBe(1);
    }
});

test('several estimates, each with its own result, and one not ticked is not presented', function () {
    $cabinets = estimateFor($this->lead, $this->sam, ['Cabinets only' => '3200']);
    $deck = estimateFor($this->lead, $this->sam, ['Deck' => '1800']);

    submitReport($this->lead, $this->appointment, $this->sam, [
        'outcome' => 'run',
        'presented' => 'yes',
        'estimates' => [
            $this->exterior->id => ['presented' => true, 'result' => 'pending', 'objectionId' => $this->thinking->id],
            $cabinets->id => ['presented' => true, 'result' => 'lost', 'lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id, 'note' => 'Too much for cabinets.'],
            // Not presented: whatever else is sent, it stays unpresented.
            $deck->id => ['presented' => false, 'result' => 'sold'],
        ],
    ]);

    $exterior = Proposal::query()->where('estimate_id', $this->exterior->id)->sole();
    $lost = Proposal::query()->where('estimate_id', $cabinets->id)->sole();

    expect($exterior->result)->toBe(ProposalResult::Pending);
    expect($exterior->objection_name)->toBe('Wants to think it over');
    expect($exterior->price())->toBe('$3,200 to $8,450');
    expect($lost->result)->toBe(ProposalResult::Lost);
    expect($lost->only(['loss_reason_name', 'result_note']))->toBe(['loss_reason_name' => 'Price', 'result_note' => 'Too much for cabinets.']);
    expect(Proposal::query()->where('estimate_id', $deck->id)->exists())->toBeFalse();
    expect($this->appointment->proposals()->count())->toBe(2);
    // Nothing sold and the Lead not marked lost: it stays with its salesperson.
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('a return visit: an estimate from the first visit is presented again and sold, beside a new one', function () {
    $deck = estimateFor($this->lead, $this->sam, ['Deck' => '1800']);

    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [
        $this->exterior->id => ['presented' => true, 'result' => 'pending', 'objectionId' => $this->thinking->id],
        $deck->id => ['presented' => true, 'result' => 'pending', 'objectionId' => $this->thinking->id],
    ]]);

    $exterior = Proposal::query()->where('estimate_id', $this->exterior->id)->sole();
    $deckProposal = Proposal::query()->where('estimate_id', $deck->id)->sole();

    $second = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00')->appointment;
    $gutters = estimateFor($this->lead->fresh(), $this->sam, ['Gutters' => '900']);
    $this->travelTo('2026-10-12 15:35:00');

    submitReport($this->lead->fresh(), $second, $this->sam, [
        'outcome' => 'run',
        'presented' => 'yes',
        'existing' => [
            $exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => $this->wholeExterior->id],
            // Not presented at this visit: left exactly as it was, whatever else is sent.
            $deckProposal->id => ['presented' => false, 'result' => 'sold'],
        ],
        'estimates' => [$gutters->id => ['presented' => true, 'result' => 'sold', 'optionId' => $gutters->options->sole()->id]],
    ]);

    expect($exterior->fresh()->result)->toBe(ProposalResult::Sold);
    expect($exterior->fresh()->sold_cents)->toBe(845000);
    expect($exterior->appointments()->pluck('appointments.id')->all())->toBe([$this->appointment->id, $second->id]);
    expect($deckProposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect($deckProposal->appointments()->count())->toBe(1);
    expect(Proposal::query()->where('estimate_id', $gutters->id)->sole()->result)->toBe(ProposalResult::Sold);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('an estimate presented before is not presented again as new', function () {
    $proposal = presentEstimate($this->exterior, $this->sam, [Appointment::factory()->forLead($this->lead)->with($this->sam)->create(['state' => AppointmentState::Run, 'starts_at' => now()->subDays(3)])->id]);

    expect(fn () => submitReport($this->lead->fresh(), $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$this->exterior->id => ['presented' => true, 'result' => 'pending', 'objectionId' => $this->thinking->id]]]))
        ->toThrow(ValidationException::class, 'This estimate is already recorded as presented.');

    expect(Proposal::query()->count())->toBe(1);
    expect($proposal->appointments()->count())->toBe(1);
});

test('the report is saved whole or not at all', function (Closure $report, Closure $field, string $message) {
    try {
        submitReport($this->lead, $this->appointment, $this->sam, $report($this));

        $this->fail('The report was not refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toHaveKey($field($this));
        expect($refusal->errors()[$field($this)][0])->toContain($message);
    }

    expectNothingSaved();
})->with([
    'no answer about the appointment' => [fn ($test) => ['presented' => 'no'], fn ($test) => 'closeOutOutcome', 'Say whether the appointment was run or was a no-show.'],
    'run, and nothing said about what was presented' => [fn ($test) => ['outcome' => 'run'], fn ($test) => 'presented', 'Say whether anything was presented.'],
    'something was presented, and nothing is ticked' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$test->exterior->id => ['presented' => false]]],
        fn ($test) => 'presented', 'Tick the estimate that was presented, or answer that nothing was.',
    ],
    'an estimate with no result' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$test->exterior->id => ['presented' => true]]],
        fn ($test) => "estimates.{$test->exterior->id}.proposalResult", 'Say whether it is sold, lost or still pending.',
    ],
    'sold, with no option chosen' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$test->exterior->id => ['presented' => true, 'result' => 'sold']]],
        fn ($test) => "estimates.{$test->exterior->id}.optionId", 'Choose the option the customer chose.',
    ],
    'sold, with an option of another estimate' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$test->exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => estimateFor($test->lead, $test->sam)->options->sole()->id]]],
        fn ($test) => "estimates.{$test->exterior->id}.optionId", 'Choose the option the customer chose.',
    ],
    'still pending with no objection' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$test->exterior->id => ['presented' => true, 'result' => 'pending']]],
        fn ($test) => "estimates.{$test->exterior->id}.objectionId", 'Choose the main objection',
    ],
    'lost with no reason, after an estimate that was fine' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [
            $test->exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => $test->wholeExterior->id],
            ($test->cabinets = estimateFor($test->lead, $test->sam, ['Cabinets only' => '3200']))->id => ['presented' => true, 'result' => 'lost', 'objectionId' => $test->thinking->id],
        ]],
        fn ($test) => "estimates.{$test->cabinets->id}.lossReasonId", 'Choose why it was lost.',
    ],
    'an estimate with no price yet' => [
        fn ($test) => ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [($test->empty = app(StartEstimate::class)->handle($test->lead, 'residential_interior', $test->sam))->id => ['presented' => true, 'result' => 'pending', 'objectionId' => $test->thinking->id]]],
        fn ($test) => "estimates.{$test->empty->id}.estimate", 'This estimate has no price yet.',
    ],
]);

test('an estimate or a proposal from another lead cannot be reported on', function () {
    $other = bookableLead($this->lakeview);
    Appointment::factory()->forLead($other)->with($this->sam)->create(['state' => AppointmentState::Run]);
    $theirs = Proposal::factory()->forLead($other)->create();
    $theirEstimate = estimateFor($other, $this->manager);

    expect(fn () => submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'yes', 'existing' => [$theirs->id => ['presented' => true, 'result' => 'sold']]]))
        ->toThrow(ValidationException::class, 'This estimate is no longer here.');
    expect(fn () => submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$theirEstimate->id => ['presented' => true, 'result' => 'sold', 'optionId' => $theirEstimate->options->sole()->id]]]))
        ->toThrow(ValidationException::class, 'This estimate is no longer here.');

    expectNothingSaved();
    expect($theirs->fresh()->result)->toBe(ProposalResult::Pending);
    expect(Proposal::query()->where('estimate_id', $theirEstimate->id)->exists())->toBeFalse();
});

test('who may send the report', function (Closure $person, bool $allowed) {
    $report = ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [$this->exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => $this->wholeExterior->id]]];
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

    submitReport($this->lead, $this->appointment, $this->sam, ['outcome' => 'run', 'presented' => 'no', 'noDemoReason' => 'Customer had to leave.']);

    expect(fn () => submitReport($this->lead->fresh(), $this->appointment, $this->sam, ['outcome' => 'no_show']))
        ->toThrow(ValidationException::class, 'This appointment is no longer waiting for an answer.');
});

test('a lead that changed since the report was opened is refused', function () {
    expect(fn () => app(SubmitAfterActionReport::class)->handle($this->lead, $this->appointment, ['outcome' => 'run', 'presented' => 'no'], $this->sam, $this->lead->lifecycle_version - 1))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expectNothingSaved();
});
