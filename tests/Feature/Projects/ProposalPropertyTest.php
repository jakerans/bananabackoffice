<?php

use App\Actions\Proposals\EditProposal;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Project;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * One proposal is for one of the customer's properties, and its Project is for
 * that property (docs/slice-8-projects-plan.md, step 8b; decision 132).
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales at 123 Oak St, and was run. The customer also owns 9 Lake Rd.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->property->forceFill(['address_line1' => '123 Oak St', 'label' => 'Home'])->save();
    $this->lakeHouse = Property::factory()->of($this->lead->account)->create(['address_line1' => '9 Lake Rd', 'label' => 'Lake house']);
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    $this->appointment = runAppointment($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

test('a proposal is for the lead\'s property unless another of the customer\'s is chosen', function () {
    $home = addProposal($this->lead, $this->sam);
    $lake = addProposal($this->lead, $this->sam, ['name' => 'Lake house deck', 'price' => '1800', 'property' => $this->lakeHouse->id]);

    expect($home->property_id)->toBe($this->lead->property_id);
    expect($lake->property_id)->toBe($this->lakeHouse->id);
});

test('a proposal is refused for a property that is not this customer\'s, or is archived', function (Closure $property) {
    expect(fn () => addProposal($this->lead, $this->sam, ['property' => $property($this)]))
        ->toThrow(ValidationException::class, 'Choose one of this customer\'s properties.');

    expect(Proposal::query()->count())->toBe(0);
})->with([
    'another customer\'s property' => [fn ($test) => bookableLead($test->lakeview)->property_id],
    'an archived property of theirs' => [function ($test) {
        $test->lakeHouse->forceFill(['archived_at' => now()])->save();

        return $test->lakeHouse->id;
    }],
    'a property that does not exist' => [fn ($test) => 987654],
]);

test('a project is for its proposal\'s property, and two houses are two proposals and two projects', function () {
    $home = addProposal($this->lead, $this->sam);
    $lake = addProposal($this->lead, $this->sam, ['name' => 'Lake house deck', 'price' => '1800', 'property' => $this->lakeHouse->id]);

    app(RecordProposalResult::class)->handle($this->lead, $home, 'sold', [], $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $lake, 'sold', [], $this->sam);

    $atHome = Project::query()->where('proposal_id', $home->id)->sole();
    $atLake = Project::query()->where('proposal_id', $lake->id)->sole();

    expect($atHome->property_id)->toBe($this->lead->property_id);
    expect($atLake->property_id)->toBe($this->lakeHouse->id);

    // Each is named for what was sold and its own address (decision 134).
    expect($atHome->title)->toBe($home->name.' — 123 Oak St');
    expect($atLake->title)->toBe('Lake house deck — 9 Lake Rd');
});

test('a pending proposal\'s property can be changed, and what it was is kept', function () {
    $proposal = addProposal($this->lead, $this->sam);

    app(EditProposal::class)->handle($this->lead, $proposal, 'Whole exterior', '8450', $this->sam, propertyId: $this->lakeHouse->id);

    expect($proposal->fresh()->property_id)->toBe($this->lakeHouse->id);

    $event = AuditEvent::query()->where('action', AuditAction::LeadProposalEdited)->sole();

    expect($event->values_before)->toMatchArray(['property_id' => $this->lead->property_id]);
    expect($event->values_after)->toMatchArray(['property_id' => $this->lakeHouse->id]);

    // Saying nothing about the property leaves it where it is.
    app(EditProposal::class)->handle($this->lead, $proposal, 'Whole exterior', '8,900', $this->sam);

    expect($proposal->fresh()->only(['property_id', 'price_cents']))->toBe(['property_id' => $this->lakeHouse->id, 'price_cents' => 890000]);

    expect(fn () => app(EditProposal::class)->handle($this->lead, $proposal, 'Whole exterior', '8900', $this->sam, propertyId: bookableLead($this->lakeview)->property_id))
        ->toThrow(ValidationException::class, 'Choose one of this customer\'s properties.');
});

test('the card asks which property only when the customer has more than one', function () {
    $proposal = presentEstimate(estimateFor($this->lead, $this->sam, ['Lake house deck' => '1800']), $this->sam);

    // Presented for the Lead's own property; moved to another of the customer's from the card.
    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openEdit', $proposal->id)
        ->assertSet('proposalPropertyId', $this->lead->property_id)
        ->assertSeeHtml('data-test="edit-proposal-property"')
        ->assertDontSeeHtml('data-test="edit-proposal-name"')
        ->set('proposalPropertyId', $this->lakeHouse->id)
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('For: Lake house — 9 Lake Rd');

    expect($proposal->fresh()->property_id)->toBe($this->lakeHouse->id);

    // A customer with one property is not asked.
    $single = bookableLead($this->lakeview);
    bookAppointment($single, $this->sam, $this->manager, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 14:00:00');
    runAppointment($single->fresh(), $this->sam);
    $theirs = presentEstimate(estimateFor($single->fresh(), $this->sam), $this->sam);

    proposalsCard($this->sam, $single->fresh())
        ->assertDontSeeHtml('data-test="proposal-property"')
        ->call('openEdit', $theirs->id)
        ->assertDontSeeHtml('data-test="edit-proposal-property"');
});

test('the card refuses another customer\'s property, whatever the browser sends', function () {
    $proposal = presentEstimate(estimateFor($this->lead, $this->sam), $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openEdit', $proposal->id)
        ->set('proposalPropertyId', bookableLead($this->lakeview)->property_id)
        ->call('saveEdit')
        ->assertHasErrors('proposalPropertyId');

    expect($proposal->fresh()->property_id)->toBe($this->lead->property_id);
});

test('an estimate presented in the report is for the lead\'s property, and its project takes the one it is moved to', function () {
    $lead = bookableLead($this->lakeview);
    $other = Property::factory()->of($lead->account)->create(['address_line1' => '4 Hill Ct']);
    $appointment = bookAppointment($lead, $this->sam, $this->manager, '2026-10-09', '10:00')->appointment;
    $estimate = estimateFor($lead->fresh(), $this->sam, ['Hill Ct interior' => '4000']);
    $this->travelTo('2026-10-09 16:00:00');

    Livewire::actingAs($this->sam)->test('pages::leads.report', ['lead' => $lead->fresh(), 'appointment' => $appointment])
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->set('estimates.'.$estimate->id.'.presented', true)
        ->set('estimates.'.$estimate->id.'.result', 'pending')
        ->set('estimates.'.$estimate->id.'.objectionId', (string) Objection::query()->where('name', 'Wants to think it over')->sole()->id)
        ->call('submit')
        ->assertHasNoErrors();

    $proposal = Proposal::query()->where('estimate_id', $estimate->id)->sole();
    expect($proposal->property_id)->toBe($lead->property_id);

    app(EditProposal::class)->handle($lead->fresh(), $proposal, null, null, $this->sam, propertyId: $other->id);
    app(RecordProposalResult::class)->handle($lead->fresh(), $proposal, 'sold', ['optionId' => $estimate->options->sole()->id], $this->sam);

    expect(Project::query()->where('lead_id', $lead->id)->sole()->property_id)->toBe($other->id);
});
