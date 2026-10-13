<?php

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\RouteLead;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\EditProposal;
use App\Actions\Proposals\MarkLeadLost;
use App\Actions\Proposals\MarkProposalAddedInError;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Proposals\UndoLeadLost;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * What becomes of a proposal: sold, lost or still pending; correcting it; and
 * a Lead that is sold or lost
 * (docs/slice-7-appointment-results-plan.md, step 7c; decisions 124, 125 and
 * 128).
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales, who marked it run and added one proposal: "Whole exterior", $8,450.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->competitor = SalesLossReason::query()->where('name', 'Chose Another Contractor')->sole();
    $this->otherReason = SalesLossReason::query()->where('name', 'Other')->sole();
    $this->thinking = Objection::query()->where('name', 'Wants to think it over')->sole();
    $this->otherObjection = Objection::query()->where('name', 'Other (see note)')->sole();

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    $this->appointment = runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * Give a proposal a result through the action.
 *
 * @param  array<string, mixed>  $details
 */
function recordResult(Lead $lead, Proposal $proposal, User $by, ?string $result, array $details = []): void
{
    app(RecordProposalResult::class)->handle($lead, $proposal, $result, $details, $by);
}

/**
 * What a lost result needs, for the tests that are not about that.
 *
 * @return array<string, mixed>
 */
function lostForPrice(): array
{
    return ['lossReasonId' => test()->price->id, 'objectionId' => test()->thinking->id];
}

/*
|--------------------------------------------------------------------------
| Sold
|--------------------------------------------------------------------------
*/

test('marking a proposal sold records the amount, and the lead shows Sold on its salesperson\'s record', function () {
    $this->travelTo('2026-10-09 15:00:00');

    recordResult($this->lead, $this->proposal, $this->sam, 'sold', ['note' => 'Signed at the kitchen table.']);

    $proposal = $this->proposal->fresh();

    expect($proposal->result)->toBe(ProposalResult::Sold);
    // The sold amount starts at the price.
    expect($proposal->sold_cents)->toBe(845000);
    expect($proposal->soldFor())->toBe('$8,450');
    expect($proposal->only(['result_by', 'result_by_name', 'result_note']))->toBe(['result_by' => $this->sam->id, 'result_by_name' => 'Sam Sales', 'result_note' => 'Signed at the kitchen table.']);
    expect($proposal->result_at->toDateTimeString())->toBe('2026-10-09 15:00:00');

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Sold);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    // Finished: no longer open, and no longer "with Sales" to be booked or sent back.
    expect($lead->isActive())->toBeFalse();
    expect($lead->isWithSales())->toBeFalse();

    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::Sold);
    expect($change->from_status)->toBe(LeadStatus::AppointmentRun);
    expect($change->to_status)->toBe(LeadStatus::Sold);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalSold)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->reason)->toBe('Signed at the kitchen table.');
    expect($event->values_after)->toMatchArray(['status' => 'sold', 'result' => 'sold', 'proposal' => 'Whole exterior', 'price' => '$8,450', 'sold_for' => '$8,450']);
    expect(CustomerActivity::label(AuditAction::LeadProposalSold))->toBe('Proposal sold');
});

test('the sold amount can differ from the price, and must be an amount', function () {
    expect(fn () => recordResult($this->lead, $this->proposal, $this->sam, 'sold', ['soldAmount' => 'a bit less']))
        ->toThrow(ValidationException::class, 'Enter the sold amount in dollars, like 8450 or 8,450.50.');
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);

    recordResult($this->lead, $this->proposal, $this->sam, 'sold', ['soldAmount' => '7,900']);

    expect($this->proposal->fresh()->sold_cents)->toBe(790000);
    expect($this->proposal->fresh()->price_cents)->toBe(845000);
    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalSold)->sole()->values_after)->toMatchArray(['price' => '$8,450', 'sold_for' => '$7,900']);
});

test('a sale is refused while the lead still has an appointment booked', function () {
    bookAppointment($this->lead, $this->sam, $this->sam, '2026-10-12', '10:00');

    expect(fn () => recordResult($this->lead->fresh(), $this->proposal, $this->sam, 'sold'))
        ->toThrow(ValidationException::class, 'This lead still has an appointment booked.');

    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

test('after one proposal is sold the others stay as they are and can still be answered', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);
    $deck = addProposal($this->lead, $this->sam, ['name' => 'Deck', 'price' => '1800']);

    recordResult($this->lead, $this->proposal, $this->sam, 'sold');

    expect($cabinets->fresh()->result)->toBe(ProposalResult::Pending);
    expect($deck->fresh()->result)->toBe(ProposalResult::Pending);

    // The salesperson still answers the rest on their sold Lead.
    recordResult($this->lead->fresh(), $cabinets, $this->sam, 'lost', lostForPrice());
    recordResult($this->lead->fresh(), $deck, $this->sam, 'sold');

    expect($cabinets->fresh()->result)->toBe(ProposalResult::Lost);
    expect($deck->fresh()->result)->toBe(ProposalResult::Sold);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
    // The Lead was sold once: one entry in its history.
    expect(LeadStatusChange::query()->where('lead_id', $this->lead->id)->where('event', LeadStatusEvent::Sold)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Lost
|--------------------------------------------------------------------------
*/

test('marking a proposal lost records why and the main objection, and leaves the lead as it is', function () {
    $before = $this->lead->only(['status', 'assigned_user_id', 'lifecycle_version']);

    recordResult($this->lead, $this->proposal, $this->sam, 'lost', lostForPrice() + ['note' => 'Two thousand over their budget.']);

    $proposal = $this->proposal->fresh();

    expect($proposal->result)->toBe(ProposalResult::Lost);
    expect($proposal->only(['loss_reason_id', 'loss_reason_name', 'lost_to', 'objection_id', 'objection_name', 'result_note', 'sold_cents']))->toBe([
        'loss_reason_id' => $this->price->id,
        'loss_reason_name' => 'Price',
        'lost_to' => null,
        'objection_id' => $this->thinking->id,
        'objection_name' => 'Wants to think it over',
        'result_note' => 'Two thousand over their budget.',
        'sold_cents' => null,
    ]);

    // One lost proposal does not make the Lead lost (decision 125, answer 9).
    expect($this->lead->fresh()->only(['status', 'assigned_user_id', 'lifecycle_version']))->toEqual($before);

    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalLost)->sole()->values_after)
        ->toMatchArray(['result' => 'lost', 'proposal' => 'Whole exterior', 'reason' => 'Price', 'objection' => 'Wants to think it over']);
});

test('a lost result is refused without what it needs', function (Closure $details, string $message) {
    expect(fn () => recordResult($this->lead, $this->proposal, $this->sam, 'lost', $details($this)))->toThrow(ValidationException::class, $message);

    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalLost)->count())->toBe(0);
})->with([
    'no reason' => [fn ($test) => ['objectionId' => $test->thinking->id], 'Choose why it was lost.'],
    'no objection' => [fn ($test) => ['lossReasonId' => $test->price->id], 'Choose the main objection.'],
    'a reason that needs a note, without one' => [fn ($test) => ['lossReasonId' => $test->otherReason->id, 'objectionId' => $test->thinking->id, 'note' => '  '], 'Add a note that says what happened.'],
    'an objection that needs a note, without one' => [fn ($test) => ['lossReasonId' => $test->price->id, 'objectionId' => $test->otherObjection->id], 'Add a note that says what happened.'],
    'a reason no longer offered' => [
        function ($test) {
            $test->price->forceFill(['is_active' => false])->save();

            return ['lossReasonId' => $test->price->id, 'objectionId' => $test->thinking->id];
        },
        'Price is no longer offered. Choose another.',
    ],
    'an objection no longer offered' => [
        fn ($test) => ['lossReasonId' => $test->price->id, 'objectionId' => Objection::factory()->inactive()->create(['name' => 'Old objection'])->id],
        'Old objection is no longer offered. Choose another.',
    ],
    'a reason that does not exist' => [fn ($test) => ['lossReasonId' => 987654, 'objectionId' => $test->thinking->id], 'Choose why it was lost.'],
]);

test('the competitor is kept only for a reason that asks for one', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);

    recordResult($this->lead, $this->proposal, $this->sam, 'lost', ['lossReasonId' => $this->competitor->id, 'objectionId' => $this->thinking->id, 'lostTo' => ' Brightside Painters ']);
    recordResult($this->lead, $cabinets, $this->sam, 'lost', lostForPrice() + ['lostTo' => 'Brightside Painters']);

    expect($this->proposal->fresh()->lost_to)->toBe('Brightside Painters');
    expect($cabinets->fresh()->lost_to)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Still pending
|--------------------------------------------------------------------------
*/

test('still pending records the main objection, and can be said again as things change', function () {
    $version = $this->lead->lifecycle_version;

    recordResult($this->lead, $this->proposal, $this->sam, 'pending', ['objectionId' => $this->thinking->id]);

    $proposal = $this->proposal->fresh();

    expect($proposal->result)->toBe(ProposalResult::Pending);
    expect($proposal->objection_name)->toBe('Wants to think it over');
    expect($proposal->result_by_name)->toBe('Sam Sales');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
    expect($this->lead->fresh()->lifecycle_version)->toBe($version);

    recordResult($this->lead, $proposal, $this->sam, 'pending', ['objectionId' => $this->otherObjection->id, 'note' => 'Waiting on the HOA.']);

    expect($this->proposal->fresh()->only(['objection_name', 'result_note']))->toBe(['objection_name' => 'Other (see note)', 'result_note' => 'Waiting on the HOA.']);
    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalStillPending)->count())->toBe(2);
});

test('still pending is refused without the objection, or without the note an objection needs', function () {
    expect(fn () => recordResult($this->lead, $this->proposal, $this->sam, 'pending'))
        ->toThrow(ValidationException::class, 'Choose the main objection, so the reason it is still open is written down.');
    expect(fn () => recordResult($this->lead, $this->proposal, $this->sam, 'pending', ['objectionId' => $this->otherObjection->id]))
        ->toThrow(ValidationException::class, 'Add a note that says what the objection is.');

    expect($this->proposal->fresh()->objection_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The rules around any result
|--------------------------------------------------------------------------
*/

test('a result is sold, lost or pending and nothing else', function (?string $result) {
    expect(fn () => recordResult($this->lead, $this->proposal, $this->sam, $result))
        ->toThrow(ValidationException::class, 'Say whether it is sold, lost or still pending.');
})->with([null, '', 'won', 'SOLD ']);

test('a result is given once, and changing it takes a manager', function () {
    recordResult($this->lead, $this->proposal, $this->sam, 'sold');

    expect(fn () => recordResult($this->lead->fresh(), $this->proposal, $this->sam, 'lost', lostForPrice()))
        ->toThrow(ValidationException::class, 'This proposal is already sold. A manager can correct that.');
    expect(fn () => recordResult($this->lead->fresh(), $this->proposal, $this->manager, 'pending', ['objectionId' => $this->thinking->id]))
        ->toThrow(ValidationException::class, 'This proposal is already sold. A manager can correct that.');

    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Sold);
});

test('who may give a proposal its result', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        recordResult($this->lead, $this->proposal, $actor, 'sold');

        expect($this->proposal->fresh()->result_by)->toBe($actor->id);

        return;
    }

    expect(fn () => recordResult($this->lead, $this->proposal, $actor, 'sold'))->toThrow(AuthorizationException::class);
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
})->with([
    'the salesperson the lead is with' => [fn ($test) => $test->sam, true],
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('a proposal of another lead is refused, and so is a lead that changed since the dialog opened', function () {
    $other = bookableLead($this->lakeview);
    Appointment::factory()->forLead($other)->with($this->sam)->create(['state' => AppointmentState::Run]);
    $theirs = Proposal::factory()->forLead($other)->create();

    expect(fn () => recordResult($this->lead, $theirs, $this->manager, 'sold'))->toThrow(ValidationException::class, 'This proposal belongs to another lead.');
    expect(fn () => app(RecordProposalResult::class)->handle($this->lead, $this->proposal, 'sold', [], $this->sam, $this->lead->lifecycle_version - 1))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expect($theirs->fresh()->result)->toBe(ProposalResult::Pending);
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
});

test('once the lead has left Sales its proposals are no longer given a result', function () {
    app(SendLeadBackToContactCenter::class)->handle($this->lead, StopCountingLeadAsIssued::ANOTHER_REASON, 'The customer wants a call first.', $this->manager);

    expect(fn () => recordResult($this->lead->fresh(), $this->proposal, $this->manager, 'sold'))
        ->toThrow(ValidationException::class, 'This lead is no longer with Sales, so its proposals cannot be given a result.');
});

/*
|--------------------------------------------------------------------------
| A manager corrects a result
|--------------------------------------------------------------------------
*/

test('a manager corrects a sold proposal back to pending, and the lead goes back to appointment run', function () {
    recordResult($this->lead, $this->proposal, $this->sam, 'sold', ['soldAmount' => '7900']);

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'Sam marked the wrong lead.', $this->manager);

    $proposal = $this->proposal->fresh();

    expect($proposal->result)->toBe(ProposalResult::Pending);
    expect($proposal->only(['sold_cents', 'result_note', 'result_by_name']))->toBe(['sold_cents' => null, 'result_note' => null, 'result_by_name' => 'Morgan Manager']);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentRun);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect(LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->event)->toBe(LeadStatusEvent::SaleCorrected);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalResultCorrected)->sole();

    expect($event->reason)->toBe('Sam marked the wrong lead.');
    expect($event->values_before)->toMatchArray(['status' => 'sold', 'result' => 'sold', 'sold_for' => '$7,900']);
    expect($event->values_after)->toMatchArray(['status' => 'appointment_run', 'result' => 'pending']);

    // It can be answered again.
    recordResult($lead, $proposal, $this->sam, 'lost', lostForPrice());

    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Lost);
});

test('a lead with another sold proposal stays Sold when one is corrected', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);

    recordResult($this->lead, $this->proposal, $this->sam, 'sold');
    recordResult($this->lead->fresh(), $cabinets, $this->sam, 'sold');

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $cabinets, 'They only bought the exterior.', $this->manager);

    expect($cabinets->fresh()->result)->toBe(ProposalResult::Pending);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('a lost proposal is corrected back to pending and the lead is untouched', function () {
    recordResult($this->lead, $this->proposal, $this->sam, 'lost', lostForPrice());
    $version = $this->lead->fresh()->lifecycle_version;

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'They called back.', $this->manager);

    expect($this->proposal->fresh()->only(['loss_reason_id', 'loss_reason_name', 'objection_id', 'objection_name']))
        ->toBe(['loss_reason_id' => null, 'loss_reason_name' => null, 'objection_id' => null, 'objection_name' => null]);
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect($this->lead->fresh()->lifecycle_version)->toBe($version);
});

test('only a manager corrects a result, with a note, and only a result that was given', function () {
    expect(fn () => app(CorrectProposalResult::class)->handle($this->lead, $this->proposal, 'No result yet.', $this->manager))
        ->toThrow(ValidationException::class, 'This proposal has no result to correct.');

    recordResult($this->lead, $this->proposal, $this->sam, 'sold');
    $lead = $this->lead->fresh();

    expect(fn () => app(CorrectProposalResult::class)->handle($lead, $this->proposal, 'I pressed the wrong one.', $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => app(CorrectProposalResult::class)->handle($lead, $this->proposal, '   ', $this->manager))->toThrow(ValidationException::class, 'Say why this is being corrected.');

    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Sold);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

/*
|--------------------------------------------------------------------------
| Changing a proposal that is still pending (decision 128)
|--------------------------------------------------------------------------
*/

test('the salesperson changes a pending proposal\'s name and price, and what it was is kept', function () {
    $version = $this->lead->lifecycle_version;

    app(EditProposal::class)->handle($this->lead, $this->proposal, ' Whole exterior, two coats ', '9,100.50', $this->sam);

    expect($this->proposal->fresh()->only(['name', 'price_cents']))->toBe(['name' => 'Whole exterior, two coats', 'price_cents' => 910050]);
    expect($this->lead->fresh()->lifecycle_version)->toBe($version);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->values_before)->toEqual(['proposal' => 'Whole exterior', 'price' => '$8,450', 'property_id' => $this->lead->property_id]);
    expect($event->values_after)->toEqual(['proposal' => 'Whole exterior, two coats', 'price' => '$9,100.50', 'property_id' => $this->lead->property_id]);

    // Saving it unchanged records nothing.
    app(EditProposal::class)->handle($this->lead, $this->proposal, 'Whole exterior, two coats', '9100.50', $this->sam);

    expect(AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->count())->toBe(1);
});

test('a change is refused without a name or a price, and to people who may not', function () {
    expect(fn () => app(EditProposal::class)->handle($this->lead, $this->proposal, '  ', '8450', $this->sam))->toThrow(ValidationException::class, 'Say what was pitched.');
    expect(fn () => app(EditProposal::class)->handle($this->lead, $this->proposal, 'Whole exterior', 'free', $this->sam))->toThrow(ValidationException::class, 'Enter the price in dollars');
    expect(fn () => app(EditProposal::class)->handle($this->lead, $this->proposal, 'Cabinets', '100', $this->agent))->toThrow(AuthorizationException::class);
    expect(fn () => app(EditProposal::class)->handle($this->lead, $this->proposal, 'Cabinets', '100', $this->riley))->toThrow(AuthorizationException::class);

    expect($this->proposal->fresh()->only(['name', 'price_cents']))->toBe(['name' => 'Whole exterior', 'price_cents' => 845000]);
});

test('a proposal that is sold, lost or added in error is no longer changed', function (Closure $finish) {
    $finish($this);

    expect(fn () => app(EditProposal::class)->handle($this->lead->fresh(), $this->proposal, 'Cabinets', '100', $this->manager))
        ->toThrow(ValidationException::class, 'Only a proposal that is still pending can be changed.');

    expect($this->proposal->fresh()->name)->toBe('Whole exterior');
})->with([
    'sold' => [fn ($test) => recordResult($test->lead, $test->proposal, $test->sam, 'sold')],
    'lost' => [fn ($test) => recordResult($test->lead, $test->proposal, $test->sam, 'lost', lostForPrice())],
    'added in error' => [fn ($test) => app(MarkProposalAddedInError::class)->handle($test->lead, $test->proposal, 'Wrong customer.', $test->manager)],
]);

/*
|--------------------------------------------------------------------------
| Added in error (decision 128)
|--------------------------------------------------------------------------
*/

test('a manager marks a proposal added in error: it stays on the record and counts for nothing', function () {
    $presented = fn (): int => Appointment::query()->wereRun()->whereHas('proposals', fn ($proposals) => $proposals->counted())->count();

    expect($presented())->toBe(1);

    app(MarkProposalAddedInError::class)->handle($this->lead, $this->proposal, 'Sam added it to the wrong lead.', $this->manager);

    $proposal = $this->proposal->fresh();

    expect($proposal->isAddedInError())->toBeTrue();
    expect($proposal->only(['added_in_error_by', 'added_in_error_by_name', 'added_in_error_note']))->toBe([
        'added_in_error_by' => $this->manager->id,
        'added_in_error_by_name' => 'Morgan Manager',
        'added_in_error_note' => 'Sam added it to the wrong lead.',
    ]);

    // Still there, never deleted; but the appointment is again run with nothing presented.
    expect(Proposal::query()->count())->toBe(1);
    expect($this->lead->proposals()->counted()->count())->toBe(0);
    expect($presented())->toBe(0);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalAddedInError)->sole();

    expect($event->reason)->toBe('Sam added it to the wrong lead.');
    expect($event->values_before)->toEqual(['proposal' => 'Whole exterior', 'price' => '$8,450']);

    // It has no result and cannot be marked twice.
    expect(fn () => recordResult($this->lead, $proposal, $this->manager, 'sold'))->toThrow(ValidationException::class, 'This proposal was marked "added in error" and has no result.');
    expect(fn () => app(MarkProposalAddedInError::class)->handle($this->lead, $proposal, 'Again.', $this->manager))->toThrow(ValidationException::class, 'This proposal is no longer here to be marked.');
});

test('only a manager marks one added in error, with a note, and only while it is pending', function () {
    expect(fn () => app(MarkProposalAddedInError::class)->handle($this->lead, $this->proposal, 'I added the wrong one.', $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => app(MarkProposalAddedInError::class)->handle($this->lead, $this->proposal, ' ', $this->manager))->toThrow(ValidationException::class, 'Say why this proposal should not be here.');

    recordResult($this->lead, $this->proposal, $this->sam, 'sold');

    expect(fn () => app(MarkProposalAddedInError::class)->handle($this->lead->fresh(), $this->proposal, 'Wrong lead.', $this->manager))
        ->toThrow(ValidationException::class, 'This proposal is sold. Correct its result first.');

    expect($this->proposal->fresh()->isAddedInError())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A Lead that is lost (decision 125, answer 9)
|--------------------------------------------------------------------------
*/

test('marking the lead lost turns every pending proposal lost with the same reason, and the lead shows Lost', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);
    $deck = addProposal($this->lead, $this->sam, ['name' => 'Deck', 'price' => '1800']);

    // One was already lost, for its own reason.
    recordResult($this->lead, $deck, $this->sam, 'lost', lostForPrice());

    $this->travelTo('2026-10-10 15:00:00');

    app(MarkLeadLost::class)->handle($this->lead->fresh(), ['lossReasonId' => $this->competitor->id, 'lostTo' => 'Brightside Painters', 'objectionId' => $this->thinking->id, 'note' => 'They signed with someone else.'], $this->sam);

    foreach ([$this->proposal, $cabinets] as $proposal) {
        expect($proposal->fresh()->only(['loss_reason_name', 'lost_to', 'objection_name', 'result_note', 'result_by_name']))->toBe([
            'loss_reason_name' => 'Chose Another Contractor',
            'lost_to' => 'Brightside Painters',
            'objection_name' => 'Wants to think it over',
            'result_note' => 'They signed with someone else.',
            'result_by_name' => 'Sam Sales',
        ]);
        expect($proposal->fresh()->result)->toBe(ProposalResult::Lost);
    }

    expect($deck->fresh()->loss_reason_name)->toBe('Price');

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Lost);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->isActive())->toBeFalse();
    expect($lead->only(['lost_by_name', 'lost_reason_name', 'lost_to', 'lost_objection_name', 'lost_note']))->toBe([
        'lost_by_name' => 'Sam Sales',
        'lost_reason_name' => 'Chose Another Contractor',
        'lost_to' => 'Brightside Painters',
        'lost_objection_name' => 'Wants to think it over',
        'lost_note' => 'They signed with someone else.',
    ]);
    expect($lead->lost_at->toDateTimeString())->toBe('2026-10-10 15:00:00');

    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::Lost);
    expect($change->from_status)->toBe(LeadStatus::AppointmentRun);

    expect(AuditEvent::query()->where('action', AuditAction::LeadMarkedLost)->sole()->values_after)
        ->toMatchArray(['status' => 'lost', 'reason' => 'Chose Another Contractor', 'objection' => 'Wants to think it over', 'proposals_lost' => ['Whole exterior', 'Cabinets only']]);
    expect(CustomerActivity::label(AuditAction::LeadMarkedLost))->toBe('Lead marked lost');
});

test('a lead can be lost with nothing presented', function () {
    app(MarkProposalAddedInError::class)->handle($this->lead, $this->proposal, 'Wrong lead.', $this->manager);

    app(MarkLeadLost::class)->handle($this->lead->fresh(), lostForPrice(), $this->sam);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Lost);
    // The one added in error is left exactly as it was.
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect(AuditEvent::query()->where('action', AuditAction::LeadMarkedLost)->sole()->values_after['proposals_lost'])->toBe([]);
});

test('a lead is marked lost only when it can be', function (Closure $arrange, string $message) {
    $lead = $arrange($this);

    expect(fn () => app(MarkLeadLost::class)->handle($lead, lostForPrice(), $this->manager))->toThrow(ValidationException::class, $message);
    expect($lead->fresh()->status)->not->toBe(LeadStatus::Lost);
})->with([
    'its appointment has not been run' => [
        function ($test) {
            $waiting = bookableLead($test->lakeview);
            bookAppointment($waiting, $test->sam, $test->manager, '2026-10-12', '10:00');

            return $waiting->fresh();
        },
        'Only a lead that is with Sales and has had an appointment run can be marked lost.',
    ],
    'it has gone back to the contact center' => [
        function ($test) {
            app(SendLeadBackToContactCenter::class)->handle($test->lead, StopCountingLeadAsIssued::ANOTHER_REASON, 'Wants a call first.', $test->manager);

            return $test->lead->fresh();
        },
        'Only a lead that is with Sales and has had an appointment run can be marked lost.',
    ],
    'it still has an appointment booked' => [
        function ($test) {
            bookAppointment($test->lead, $test->sam, $test->sam, '2026-10-12', '10:00');

            return $test->lead->fresh();
        },
        'This lead still has an appointment booked.',
    ],
]);

test('marking a lead lost needs the reason and the objection, from someone who may', function () {
    expect(fn () => app(MarkLeadLost::class)->handle($this->lead, ['objectionId' => $this->thinking->id], $this->sam))->toThrow(ValidationException::class, 'Choose why it was lost.');
    expect(fn () => app(MarkLeadLost::class)->handle($this->lead, ['lossReasonId' => $this->price->id], $this->sam))->toThrow(ValidationException::class, 'Choose the main objection.');
    expect(fn () => app(MarkLeadLost::class)->handle($this->lead, lostForPrice(), $this->agent))->toThrow(AuthorizationException::class);
    expect(fn () => app(MarkLeadLost::class)->handle($this->lead, lostForPrice(), $this->riley))->toThrow(AuthorizationException::class);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
});

test('a sold or lost lead is finished: it is not booked, sent back, moved or disqualified', function (Closure $finish) {
    $finish($this);

    $lead = $this->lead->fresh();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    expect(fn () => bookAppointment($lead, $this->sam, $this->manager, '2026-10-13', '10:00'))->toThrow(ValidationException::class);
    expect(fn () => app(SendLeadBackToContactCenter::class)->handle($lead, StopCountingLeadAsIssued::ANOTHER_REASON, 'Call them.', $this->manager))->toThrow(ValidationException::class);
    expect(fn () => app(RouteLead::class)->handle($lead, Location::factory()->create(), 'Wrong office.', $owner))->toThrow(ValidationException::class);
    expect(fn () => app(DisqualifyLead::class)->handle($lead, LeadDisqualificationReason::factory()->create(), 'Not real.', $owner))->toThrow(ValidationException::class);
    expect(fn () => app(MarkLeadLost::class)->handle($lead, lostForPrice(), $this->manager))->toThrow(ValidationException::class);

    expect($this->lead->fresh()->only(['status', 'location_id', 'assigned_user_id']))->toEqual($lead->only(['status', 'location_id', 'assigned_user_id']));
    expect(Appointment::query()->where('lead_id', $lead->id)->count())->toBe(1);
})->with([
    'sold' => [fn ($test) => recordResult($test->lead, $test->proposal, $test->sam, 'sold')],
    'lost' => [fn ($test) => app(MarkLeadLost::class)->handle($test->lead, lostForPrice(), $test->sam)],
]);

test('a lead that was sold after a no-show return visit is never released to the contact center', function () {
    // A return visit is booked and the customer is not there: the Lead waits to be booked again.
    bookAppointment($this->lead, $this->sam, $this->sam, '2026-10-12', '10:00');
    $this->travelTo('2026-10-12 14:00:00');
    app(CloseOutAppointment::class)->handle($this->lead->fresh(), $this->lead->fresh()->standingAppointment(), 'no_show', null, $this->sam);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);

    // Then they sign.
    recordResult($this->lead->fresh(), $this->proposal, $this->sam, 'sold');

    $this->travelTo('2026-11-15 14:00:00');

    expect(app(RunQueueTick::class)->handle()['released'])->toBe(0);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
});

/*
|--------------------------------------------------------------------------
| A manager undoes a lost Lead
|--------------------------------------------------------------------------
*/

test('a manager undoes a lost lead: it goes back to appointment run and the proposals it lost are pending again', function () {
    $deck = addProposal($this->lead, $this->sam, ['name' => 'Deck', 'price' => '1800']);
    recordResult($this->lead, $deck, $this->sam, 'lost', lostForPrice());

    $this->travelTo('2026-10-10 15:00:00');
    app(MarkLeadLost::class)->handle($this->lead->fresh(), lostForPrice(), $this->sam);

    $this->travelTo('2026-10-20 15:00:00');
    app(UndoLeadLost::class)->handle($this->lead->fresh(), 'The customer came back.', $this->manager);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentRun);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->only(['lost_at', 'lost_by', 'lost_by_name', 'lost_reason_id', 'lost_reason_name', 'lost_to', 'lost_objection_id', 'lost_objection_name', 'lost_note']))
        ->toBe(array_fill_keys(['lost_at', 'lost_by', 'lost_by_name', 'lost_reason_id', 'lost_reason_name', 'lost_to', 'lost_objection_id', 'lost_objection_name', 'lost_note'], null));

    // The one the Lead's loss turned lost is open again; the one lost before that stays lost.
    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
    expect($this->proposal->fresh()->loss_reason_name)->toBeNull();
    expect($deck->fresh()->result)->toBe(ProposalResult::Lost);

    expect(LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->event)->toBe(LeadStatusEvent::LostUndone);

    $event = AuditEvent::query()->where('action', AuditAction::LeadLostUndone)->sole();

    expect($event->reason)->toBe('The customer came back.');
    expect($event->values_before)->toMatchArray(['status' => 'lost', 'reason' => 'Price']);
    expect($event->values_after)->toMatchArray(['status' => 'appointment_run', 'proposals_pending_again' => ['Whole exterior']]);

    // The Lead is with Sales again: its salesperson can book the return visit.
    expect(bookAppointment($lead, $this->sam, $this->sam, '2026-10-22', '10:00')->appointment->follows_appointment_id)->toBe($this->appointment->id);
});

test('only a manager undoes a lost lead, with a note, and only a lead that is lost', function () {
    expect(fn () => app(UndoLeadLost::class)->handle($this->lead, 'Not lost.', $this->manager))->toThrow(ValidationException::class, 'This lead is not marked lost.');

    app(MarkLeadLost::class)->handle($this->lead, lostForPrice(), $this->sam);
    $lead = $this->lead->fresh();

    expect(fn () => app(UndoLeadLost::class)->handle($lead, 'They called me.', $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => app(UndoLeadLost::class)->handle($lead, '  ', $this->manager))->toThrow(ValidationException::class, 'Say why this is being undone.');
    expect(fn () => app(CorrectProposalResult::class)->handle($lead, $this->proposal, 'They called back.', $this->manager))->toThrow(ValidationException::class, 'This lead is marked lost. Undo that first.');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Lost);
});

/*
|--------------------------------------------------------------------------
| The salesperson is told when a manager changes the record (decision 126)
|--------------------------------------------------------------------------
*/

test('the salesperson is told in the app when a manager changes a proposal or a result on their lead', function (Closure $change) {
    Notification::fake();

    $change($this);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertNotSentTo($this->manager, AppointmentChanged::class);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::SALES_RECORD_CHANGED
        && $notice->channel === AppointmentChanged::IN_APP
        && $notice->toArray($this->sam)['message'] === 'A manager changed the proposals or the result on a lead of yours.'
        && $notice->toArray($this->sam)['url'] === route('leads.show', $this->lead->id));
})->with([
    'a result' => [fn ($test) => recordResult($test->lead, $test->proposal, $test->manager, 'sold')],
    'a change to a proposal' => [fn ($test) => app(EditProposal::class)->handle($test->lead, $test->proposal, 'Whole exterior', '9000', $test->manager)],
    'added in error' => [fn ($test) => app(MarkProposalAddedInError::class)->handle($test->lead, $test->proposal, 'Wrong lead.', $test->manager)],
    'the lead marked lost' => [fn ($test) => app(MarkLeadLost::class)->handle($test->lead, lostForPrice(), $test->manager)],
]);

test('the salesperson is told about a correction and an undo, and never about what they did themselves', function () {
    Notification::fake();

    recordResult($this->lead, $this->proposal, $this->sam, 'sold');
    app(EditProposal::class)->handle($this->lead->fresh(), addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']), 'Deck and rail', '2100', $this->sam);

    Notification::assertNothingSent();

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'Wrong lead.', $this->manager);
    app(MarkLeadLost::class)->handle($this->lead->fresh(), lostForPrice(), $this->sam);
    app(UndoLeadLost::class)->handle($this->lead->fresh(), 'The customer came back.', $this->manager);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 2);
});
