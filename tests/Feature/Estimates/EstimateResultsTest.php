<?php

use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\MarkProposalAddedInError;
use App\Actions\Proposals\PresentEstimate;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Support\Audit\AuditLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Estimates take over from proposals, step 31e
 * (docs/slice-31-estimating-plan.md; decision 207): an estimate presented at
 * a run appointment is the record a proposal always was, at its options'
 * range of prices; sold for the option chosen; changeable while pending,
 * with its price following; kept as it was once sold or lost. Made-up prices
 * only.
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales, and was run. The estimate has two options, $8,450 and $3,200.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->estimate = estimateFor($this->lead->fresh(), $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);
    [$this->wholeExterior, $this->frontOnly] = $this->estimate->options->all();

    $this->travelTo('2026-10-08 19:00:00');
    $this->appointment = runAppointment($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

test('presenting an estimate records it at the appointment, at its options\' range, and changes nothing about the lead', function () {
    $version = $this->lead->lifecycle_version;

    $proposal = presentEstimate($this->estimate, $this->sam);

    expect($proposal->only(['estimate_id', 'name', 'price_from_cents', 'price_cents', 'property_id', 'created_by_name']))
        ->toBe(['estimate_id' => $this->estimate->id, 'name' => 'Residential Exterior estimate', 'price_from_cents' => 320000, 'price_cents' => 845000, 'property_id' => $this->lead->property_id, 'created_by_name' => 'Sam Sales']);
    expect($proposal->price())->toBe('$3,200 to $8,450');
    expect($proposal->result)->toBe(ProposalResult::Pending);
    expect($proposal->valid_until)->not->toBeNull();
    expect($proposal->appointments()->sole()->id)->toBe($this->appointment->id);
    expect($this->estimate->fresh()->proposal->id)->toBe($proposal->id);

    // The Lead stays as it was, with its salesperson.
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
    expect($this->lead->fresh()->lifecycle_version)->toBe($version);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalAdded)->sole();
    expect($event->after())->toMatchArray(['proposal' => 'Residential Exterior estimate', 'price' => '$3,200 to $8,450']);
});

test('an estimate is presented once, only on its own lead, and not while the lead is lost', function () {
    presentEstimate($this->estimate, $this->sam);

    expect(fn () => presentEstimate($this->estimate, $this->sam))
        ->toThrow(ValidationException::class, 'This estimate is already recorded as presented.');

    $other = bookableLead($this->lakeview);
    expect(fn () => app(PresentEstimate::class)->handle($other, $this->estimate, [$this->appointment->id], $this->manager))
        ->toThrow(ValidationException::class, 'This estimate belongs to another lead.');

    expect(Proposal::query()->count())->toBe(1);
});

test('only the salesperson the lead is with, or a manager, presents an estimate', function () {
    expect(fn () => presentEstimate($this->estimate, $this->agent))->toThrow(AuthorizationException::class);
    expect(fn () => presentEstimate($this->estimate, User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create()))->toThrow(AuthorizationException::class);

    expect(Proposal::query()->count())->toBe(0);

    presentEstimate($this->estimate, $this->manager);

    expect(Proposal::query()->count())->toBe(1);
});

test('opening an estimate presented counts it as a demo; marked added in error, it can be presented again', function () {
    $proposal = presentEstimate($this->estimate, $this->sam);

    expect($this->appointment->proposals()->counted()->count())->toBe(1);

    app(MarkProposalAddedInError::class)->handle($this->lead->fresh(), $proposal, 'Ticked by mistake.', $this->manager);

    expect($this->appointment->proposals()->counted()->count())->toBe(0);
    expect($this->estimate->fresh()->proposal)->toBeNull();

    $again = presentEstimate($this->estimate, $this->sam);

    expect($again->id)->not->toBe($proposal->id);
    expect($this->appointment->proposals()->counted()->count())->toBe(1);
});

test('while pending, the estimate can still be changed and its presented price follows, kept in the Audit Log', function () {
    $proposal = presentEstimate($this->estimate, $this->sam);

    app(SaveEstimateLineItem::class)->handle($this->estimate, $this->frontOnly, null, ['name' => 'Gutters', 'pricing' => 'price', 'price' => '300'], $this->sam);

    expect($proposal->fresh()->only(['price_from_cents', 'price_cents']))->toBe(['price_from_cents' => 350000, 'price_cents' => 845000]);
    expect($proposal->fresh()->price())->toBe('$3,500 to $8,450');

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->sole();
    expect($event->before())->toMatchArray(['price' => '$3,200 to $8,450']);
    expect($event->after())->toMatchArray(['price' => '$3,500 to $8,450']);

    // A change that leaves the range as it was records nothing more.
    app(SaveEstimateOption::class)->handle($this->estimate, $this->frontOnly, ['name' => 'Front and gutters', 'description' => ''], $this->sam);

    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->count())->toBe(1);
});

test('sold for the option chosen: its price, its Project named for it, and the estimate kept as it was', function () {
    $proposal = presentEstimate($this->estimate, $this->sam);

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', ['optionId' => (string) $this->frontOnly->id, 'soldAmount' => '1'], $this->sam);

    $proposal = $proposal->fresh();
    // A typed amount is not used: an estimate sells at the option's price.
    expect($proposal->only(['estimate_option_id', 'sold_cents']))->toBe(['estimate_option_id' => $this->frontOnly->id, 'sold_cents' => 320000]);
    expect($proposal->price())->toBe('$3,200');
    expect($proposal->project->title)->toStartWith('Front only');
    expect($proposal->project->sold_cents)->toBe(320000);
    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalSold)->sole()->after())->toMatchArray(['option' => 'Front only', 'sold_for' => '$3,200']);

    expect(fn () => app(SaveEstimateOption::class)->handle($this->estimate, $this->frontOnly, ['name' => 'Changed', 'description' => ''], $this->sam))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveEstimateOption::class)->handle($this->estimate, $this->frontOnly, ['name' => 'Changed', 'description' => ''], $this->manager))
        ->toThrow(AuthorizationException::class);
    expect($this->frontOnly->fresh()->name)->toBe('Front only');

    // The page says so and offers no changes; it can still be presented.
    Livewire::actingAs($this->sam)->test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $this->estimate])
        ->assertSeeHtml('data-test="estimate-settled"')
        ->assertSee('Sold: Front only, $3,200. It can no longer be changed.')
        ->assertDontSeeHtml('data-test="copy-option"')
        ->assertDontSeeHtml('data-test="reprice"')
        ->assertSeeHtml('data-test="present"');

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSee('Sold: Front only, $3,200');
});

test('lost, it is kept as it was until a manager corrects the result', function () {
    $proposal = presentEstimate($this->estimate, $this->sam);

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'lost', [
        'lossReasonId' => SalesLossReason::query()->where('name', 'Price')->sole()->id,
        'objectionId' => Objection::query()->where('name', 'Wants to think it over')->sole()->id,
    ], $this->sam);

    expect(fn () => app(SaveEstimateOption::class)->copy($this->estimate, $this->frontOnly, $this->sam))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->sam)->test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $this->estimate])
        ->assertSee('Lost. It can no longer be changed unless a manager corrects the result.');

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $proposal, 'Recorded on the wrong estimate.', $this->manager);

    app(SaveEstimateOption::class)->copy($this->estimate, $this->frontOnly, $this->sam);

    expect($this->estimate->options()->count())->toBe(3);
});

test('a sale taken back clears the option chosen', function () {
    $proposal = presentEstimate($this->estimate, $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', ['optionId' => $this->wholeExterior->id], $this->sam);

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $proposal, 'The customer changed their mind the same day.', $this->manager);

    expect($proposal->fresh()->only(['result', 'estimate_option_id', 'sold_cents']))->toBe(['result' => ProposalResult::Pending, 'estimate_option_id' => null, 'sold_cents' => null]);
    expect($proposal->fresh()->price())->toBe('$3,200 to $8,450');
});

test('people read "Estimate" in the Audit Log, though the stored values keep "proposal"', function () {
    presentEstimate($this->estimate, $this->sam);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalAdded)->sole();

    expect($event->action->value)->toBe('lead.proposal_added');
    expect(AuditAction::LeadProposalAdded->label())->toBe('Lead · Estimate presented');
    expect(AuditAction::LeadProposalResultCorrected->label())->toBe('Lead · Estimate result corrected');
    expect(collect(AuditLine::changes($event))->pluck('field')->all())->toContain('Estimate');
    expect(collect(AuditLine::changes($event))->pluck('field')->all())->not->toContain('Proposal');
});
