<?php

use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Leads\SaveDiscovery;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\LeadDiscovery;
use App\Models\Location;
use App\Models\ProductionRate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Discovery and a handoff that starts filled in, step 34b
 * (docs/slice-34-sales-workspace-plan.md; decision 212). Sam Sales's
 * appointment is on Thursday 8 October 2026 at 3:00 pm Eastern; the tests run
 * during it. Made-up answers and prices only.
 */

function discoveryAnswers(array $with = []): array
{
    return [
        'wants' => 'A fresh look before the holidays.',
        'needs' => 'The peeling trim on the south side fixed.',
        'budget' => 'Around $8,000.',
        'expectations' => 'Clean lines; the dog stays inside.',
        'timeline' => 'Done by mid-November; family visits on the 20th.',
        ...$with,
    ];
}

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;
    $this->estimate = estimateFor($this->lead->fresh(), $this->sam, ['Whole exterior' => '8450']);

    $this->travelTo('2026-10-08 19:30:00');
    $this->lead = $this->lead->fresh();
});

test('the salesperson writes discovery down during the appointment, and anyone who may see the lead reads it', function () {
    Livewire::actingAs($this->sam)->test('pages::leads.discovery', ['lead' => $this->lead])
        ->assertSee('0 of 5 written down')
        ->call('edit')
        ->set('discovery', discoveryAnswers(['expectations' => '  ']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('4 of 5 written down')
        ->assertSeeInOrder(['Wants', 'A fresh look before the holidays.', 'Budget', 'Around $8,000.', 'Timeline'])
        ->assertSee('Last written by Sam Sales');

    expect($this->lead->discovery->only(['wants', 'expectations']))->toBe(['wants' => 'A fresh look before the holidays.', 'expectations' => null]);

    $event = AuditEvent::query()->where('action', AuditAction::LeadDiscoverySaved)->sole();
    expect($event->after())->toMatchArray(['changed' => 'Wants, Needs, Budget, Timeline', 'answered' => 4])
        // What the customer said is not copied into the Audit Log.
        ->and(json_encode($event->after()))->not->toContain('holidays');

    Livewire::actingAs($this->agent)->test('pages::leads.discovery', ['lead' => $this->lead])
        ->assertSee('A fresh look before the holidays.')
        ->assertDontSeeHtml('data-test="edit-discovery"')
        ->call('edit')
        ->assertForbidden();
});

test('discovery shows on the lead, beside the estimate, and in the Workspace', function () {
    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->sam);

    $this->actingAs($this->sam)->get(route('leads.show', $this->lead))->assertOk()->assertSee('data-test="discovery"', false)->assertSee('Around $8,000.');
    $this->actingAs($this->sam)->get(route('leads.estimate', [$this->lead, $this->estimate]))->assertOk()->assertSee('Around $8,000.');
    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()
        ->assertSee('Discovery: 5 of 5 written down')
        ->assertSee(route('leads.show', $this->lead).'#discovery', false);
});

test('saving the same answers again changes nothing, and an answer that is too long is refused', function () {
    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->sam);
    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->sam);

    expect(AuditEvent::query()->where('action', AuditAction::LeadDiscoverySaved)->count())->toBe(1)
        ->and(fn () => app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(['budget' => str_repeat('a', LeadDiscovery::ANSWER_LENGTH + 1)]), $this->sam))
        ->toThrow(ValidationException::class, 'Keep it to 2000 characters.');
});

test('only those who manage the lead\'s estimates write discovery', function () {
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();

    expect(fn () => app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->agent))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $elsewhere))->toThrow(AuthorizationException::class);

    Livewire::actingAs($elsewhere)->test('pages::leads.discovery', ['lead' => $this->lead])->assertForbidden();

    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->manager);

    expect($this->lead->discovery()->sole()->updated_by_name)->toBe('Morgan Manager');
});

test('after a sale the handoff starts with what is included and what matters to the customer, to be checked', function () {
    $front = app(SaveEstimateArea::class)->handle($this->estimate, $this->estimate->options->first(), null, ['name' => 'Front of house', 'shape' => 'room', 'width' => '30', 'length' => '1', 'height' => '10'], $this->sam);
    app(SaveEstimateSurface::class)->handle($this->estimate, $front, null, ['production_rate_id' => ProductionRate::query()->where('source_key', 'sample-walls-roll')->sole()->id, 'coats' => '2'], $this->sam);
    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(['needs' => '']), $this->sam);

    runAppointment($this->lead->fresh(), $this->sam);
    $proposal = presentEstimate($this->estimate, $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', ['optionId' => $this->estimate->options->first()->id], $this->sam);
    $project = Project::query()->sole();

    $page = Livewire::actingAs($this->sam)->test('pages::projects.handoff', ['project' => $project])
        ->assertSeeHtml('data-test="handoff-prefilled"')
        ->assertSee('what is included, from the option signed for and what matters to the customer, from discovery');

    expect($page->get('handoff.includedScope'))->toBe("Front of house: Sample Walls Roll (2 coats)\nAlso: The work")
        ->and($page->get('handoff.customerPriorities'))->toBe("Wants: A fresh look before the holidays.\nBudget: Around \$8,000.\nExpectations: Clean lines; the dog stays inside.\nTimeline: Done by mid-November; family visits on the 20th.")
        ->and($page->get('handoff.exclusions'))->toBe('');

    // Nothing is saved until the salesperson completes it.
    expect($project->fresh()->handoff)->toBeNull();
});

test('a handoff already completed opens with its own answers, and discovery is then kept as it was', function () {
    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->sam);
    runAppointment($this->lead->fresh(), $this->sam);
    $proposal = presentEstimate($this->estimate, $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', ['optionId' => $this->estimate->options->first()->id], $this->sam);
    $project = Project::query()->sole();

    Livewire::actingAs($this->sam)->test('pages::projects.handoff', ['project' => $project])->assertSeeHtml('data-test="handoff-prefilled"');

    completeHandoff($project, $this->sam, ['customerPriorities' => 'As agreed at the table.']);

    Livewire::actingAs($this->sam)->test('pages::projects.handoff', ['project' => $project->fresh()])
        ->assertDontSeeHtml('data-test="handoff-prefilled"')
        ->assertSet('handoff.customerPriorities', 'As agreed at the table.');

    expect(fn () => app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(['wants' => 'Something else.']), $this->sam))
        ->toThrow(ValidationException::class, 'The handoff to Production is complete');

    Livewire::actingAs($this->sam)->test('pages::leads.discovery', ['lead' => $this->lead])->assertDontSeeHtml('data-test="edit-discovery"');
});

test('a sale recorded by hand starts no scope, and a lead with no discovery starts no priorities', function () {
    runAppointment($this->lead->fresh(), $this->sam);
    $proposal = addProposal($this->lead->fresh(), $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', [], $this->sam);

    Livewire::actingAs($this->sam)->test('pages::projects.handoff', ['project' => Project::query()->sole()])
        ->assertDontSeeHtml('data-test="handoff-prefilled"')
        ->assertSet('handoff.includedScope', '')
        ->assertSet('handoff.customerPriorities', '');
});

test('the audit log reads "Discovery saved"', function () {
    app(SaveDiscovery::class)->handle($this->lead, discoveryAnswers(), $this->sam);

    $this->actingAs(User::factory()->withRoleEverywhere(Role::Owner)->create())->get(route('settings.audit_log', ['area' => 'leads']))->assertOk()
        ->assertSee('Lead · Discovery saved');
});
