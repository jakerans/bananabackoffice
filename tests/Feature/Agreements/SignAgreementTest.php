<?php

use App\Actions\Agreements\SignAgreement;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\Agreement;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Estimate;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Signing the agreement, step 33b (docs/slice-33-agreement-plan.md;
 * decisions 210 and 211). Made-up wording and prices only.
 *
 * Sam Sales's appointment with the customer is on Thursday 8 October 2026 at
 * 3:00 pm Eastern; the tests start at 3:30 pm, while it is going on, before
 * anyone has marked it run. The estimate has two options, $8,450 and $3,200;
 * Lakeview asks for a 50% deposit.
 */

/**
 * A few lines drawn in the signature box.
 *
 * @return list<list<array{0: float, 1: float}>>
 */
function drawnSignature(): array
{
    return [
        array_map(fn (int $step): array => [round(0.1 + $step * 0.05, 2), round(0.5 + ($step % 2) * 0.1, 2)], range(0, 9)),
        [[0.7, 0.4], [0.8, 0.6]],
    ];
}

function signFor(Estimate $estimate, int $optionId, User $by, array $overrides = []): Agreement
{
    $lead = $estimate->lead()->firstOrFail();

    return app(SignAgreement::class)->handle(
        $lead,
        $estimate,
        $estimate->options()->findOrFail($optionId),
        $overrides['name'] ?? 'Pat Customer',
        $overrides['agreed'] ?? true,
        array_key_exists('signature', $overrides) ? $overrides['signature'] : drawnSignature(),
        $by,
    );
}

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;
    $this->estimate = estimateFor($this->lead->fresh(), $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);
    [$this->wholeExterior, $this->frontOnly] = $this->estimate->options->all();
    $this->wording = AgreementWording::factory()->create();

    $this->travelTo('2026-10-08 19:30:00');
    $this->lead = $this->lead->fresh();
});

test('signing during the appointment marks it run, sells the estimate for that option and keeps what the customer signed', function () {
    $agreement = signFor($this->estimate, $this->frontOnly->id, $this->sam);

    $proposal = Proposal::query()->sole();
    $appointment = $this->appointment->fresh();

    expect($appointment->state)->toBe(AppointmentState::Run)
        ->and($appointment->rest_of_report_due)->toBeTrue()
        ->and($proposal->only(['estimate_id', 'estimate_option_id', 'sold_cents']))->toBe(['estimate_id' => $this->estimate->id, 'estimate_option_id' => $this->frontOnly->id, 'sold_cents' => 320000])
        ->and($proposal->result)->toBe(ProposalResult::Sold)
        ->and($proposal->appointments->modelKeys())->toBe([$this->appointment->id])
        ->and($proposal->project)->not->toBeNull()
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::Sold)
        ->and($this->estimate->fresh()->isSettled())->toBeTrue();

    expect($agreement->fresh()->only(['proposal_id', 'appointment_id', 'agreement_wording_id', 'total_cents', 'deposit_cents', 'signer_name', 'signed_by_name']))->toBe([
        'proposal_id' => $proposal->id,
        'appointment_id' => $this->appointment->id,
        'agreement_wording_id' => $this->wording->id,
        'total_cents' => 320000,
        'deposit_cents' => 160000,
        'signer_name' => 'Pat Customer',
        'signed_by_name' => 'Sam Sales',
    ])
        ->and($agreement->fresh()->details)->toMatchArray([
            'customer' => $this->lead->displayName(),
            'property' => $this->lead->property->address,
            'option' => ['name' => 'Front only', 'description' => null],
            'total_cents' => 320000,
            'deposit_percent' => 50,
            'deposit_cents' => 160000,
        ])
        ->and($agreement->fresh()->signature)->toEqual(drawnSignature());

    $event = AuditEvent::query()->where('action', AuditAction::LeadAgreementSigned)->sole();

    expect($event->after())->toMatchArray(['option' => 'Front only', 'total' => '$3,200', 'deposit_due' => '$1,600', 'wording_version' => 1]);
});

test('the signed agreement shows what was signed, the wording and the signature, and keeps it after the wording changes', function () {
    $agreement = signFor($this->estimate, $this->frontOnly->id, $this->sam);
    AgreementWording::factory()->create(['title' => 'A NEWER AGREEMENT']);

    $this->actingAs($this->sam)->get(route('leads.agreement', [$this->lead, $agreement]))->assertOk()
        ->assertSeeInOrder(['CUSTOMER AGREEMENT', 'Signed by Pat Customer on Oct 8, 2026 at 3:30 PM EDT. Presented by Sam Sales.', 'Front only', '$3,200', 'Deposit due on acceptance: $1,600 (50% of the price).', '1. SCOPE OF WORK', 'Accepted and signed'])
        ->assertSee('d="M60.0 100.0 L90.0 120.0', false)
        ->assertSee('version 1 of the wording')
        ->assertDontSee('A NEWER AGREEMENT')
        ->assertSee('data-test="print-agreement"', false);

    // The Project's people may read it from the Project.
    $this->actingAs($this->sam)->get(route('projects.show', $agreement->proposal->project))->assertOk()
        ->assertSee(route('leads.agreement', [$this->lead, $agreement]), false)
        ->assertSee('Signed by Pat Customer');

    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();
    $this->actingAs($elsewhere)->get(route('leads.agreement', [$this->lead, $agreement]))->assertForbidden();

    // An agreement is only found under its own lead.
    $this->actingAs($this->sam)->get(route('leads.agreement', [bookableLead($this->lakeview), $agreement]))->assertNotFound();
});

test('the salesperson takes the option chosen from the presentation to the agreement, and the customer signs there', function () {
    $this->actingAs($this->sam)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertOk()
        ->assertSee('data-test="accept-'.$this->frontOnly->id.'"', false)
        ->assertSee(route('leads.estimate.sign', [$this->lead, $this->estimate, $this->frontOnly]), false);

    $page = Livewire::actingAs($this->sam)->test('pages::leads.estimate-sign', ['lead' => $this->lead, 'estimate' => $this->estimate, 'option' => $this->frontOnly])
        ->assertSeeInOrder(['Customer agreement', $this->lead->displayName(), 'Front only', '$3,200', 'Deposit due on acceptance: $1,600', '1. SCOPE OF WORK', 'Sign to accept'])
        ->assertSeeHtml('data-test="signature-pad"')
        ->call('sign', drawnSignature())
        ->assertSee('The customer types their full name.')
        ->set('signerName', 'Pat Customer')
        ->set('agreed', true)
        ->call('sign', [])
        ->assertSee('The customer signs in the box.')
        ->call('sign', drawnSignature())
        ->assertHasNoErrors();

    $agreement = Agreement::query()->sole();
    $page->assertRedirect(route('leads.agreement', [$this->lead, $agreement]));
});

test('accepting is not offered to someone who may not sell, after the appointment\'s day, or without wording', function () {
    $present = fn (User $user) => $this->actingAs($user)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertOk();

    $present($this->manager)->assertSee('data-test="accept-', false);
    $present($this->agent)->assertDontSee('data-test="accept-', false);
    $this->actingAs($this->agent)->get(route('leads.estimate.sign', [$this->lead, $this->estimate, $this->frontOnly]))->assertForbidden();

    AgreementWording::query()->delete();
    $present($this->sam)->assertDontSee('data-test="accept-', false);
    AgreementWording::factory()->create();

    $this->travelTo('2026-10-09 15:00:00');
    $present($this->sam)->assertDontSee('data-test="accept-', false);
    Livewire::actingAs($this->sam)->test('pages::leads.estimate-sign', ['lead' => $this->lead, 'estimate' => $this->estimate, 'option' => $this->frontOnly])
        ->assertSeeHtml('data-test="sign-unavailable"')
        ->assertDontSeeHtml('data-test="signature-pad"');
});

test('signing is refused without a name, a tick, a signature, wording or an appointment going on, and nothing is saved', function (array $overrides, ?Closure $before, string $message) {
    if ($before !== null) {
        $before->call($this);
    }

    expect(fn () => signFor($this->estimate, $this->frontOnly->id, $this->sam, $overrides))->toThrow(ValidationException::class, $message);

    expect(Agreement::query()->count())->toBe(0)
        ->and(Proposal::query()->count())->toBe(0)
        ->and($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
})->with([
    'no name' => [['name' => '  '], null, 'types their full name'],
    'not ticked' => [['agreed' => false], null, 'ticks that they have read'],
    'no signature' => [['signature' => []], null, 'signs in the box'],
    'a tap' => [['signature' => [[[0.5, 0.5]]]], null, 'signs in the box'],
    'outside the box' => [['signature' => [array_fill(0, 12, [1.5, 0.5])]], null, 'signs in the box'],
    'not numbers' => [['signature' => [array_fill(0, 12, ['<b>', 0.5])]], null, 'signs in the box'],
    'no wording' => [[], fn () => AgreementWording::query()->delete(), 'no agreement wording yet'],
    'before the appointment' => [[], fn () => $this->travelTo('2026-10-08 18:00:00'), 'signs during the appointment'],
    'the day after' => [[], fn () => $this->travelTo('2026-10-09 15:00:00'), 'signs during the appointment'],
]);

test('only the appointment\'s salesperson or a manager may have the customer sign', function () {
    $other = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    expect(fn () => signFor($this->estimate, $this->frontOnly->id, $this->agent))->toThrow(AuthorizationException::class)
        ->and(fn () => signFor($this->estimate, $this->frontOnly->id, $other))->toThrow(AuthorizationException::class);

    signFor($this->estimate, $this->wholeExterior->id, $this->manager);

    expect(Proposal::query()->sole()->sold_cents)->toBe(845000);
});

test('an estimate already sold cannot be signed for again', function () {
    signFor($this->estimate, $this->frontOnly->id, $this->sam);

    expect(fn () => signFor($this->estimate, $this->wholeExterior->id, $this->sam))->toThrow(ValidationException::class, 'already sold');

    expect(Agreement::query()->count())->toBe(1);
});

test('an estimate presented at an earlier visit is signed for at the return visit, presented again there', function () {
    runAppointment($this->lead->fresh(), $this->sam);
    $proposal = presentEstimate($this->estimate, $this->sam, [$this->appointment->id]);

    $returnVisit = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-09', '15:00')->appointment;
    $this->travelTo('2026-10-09 19:30:00');

    signFor($this->estimate, $this->wholeExterior->id, $this->sam);

    expect($proposal->fresh()->result)->toBe(ProposalResult::Sold)
        ->and($proposal->appointments()->orderBy('starts_at')->pluck('appointments.id')->all())->toBe([$this->appointment->id, $returnVisit->id])
        ->and(Agreement::query()->sole()->appointment_id)->toBe($returnVisit->id);
});

test('after signing, the report asks only the rest: other estimates and notes', function () {
    $other = estimateFor($this->lead->fresh(), $this->sam, ['Fence' => '900']);
    signFor($this->estimate, $this->frontOnly->id, $this->sam);

    $this->actingAs($this->sam)->get(route('leads.show', $this->lead))->assertOk()
        ->assertSee('data-test="rest-of-report"', false)
        ->assertSee(route('leads.report', [$this->lead, $this->appointment]), false);

    Livewire::actingAs($this->sam)->test('pages::leads.report', ['lead' => $this->lead->fresh(), 'appointment' => $this->appointment])
        ->assertSeeHtml('data-test="report-signed"')
        ->assertSee('Residential Exterior estimate: sold for Front only, $3,200.')
        ->assertDontSeeHtml('data-test="report-outcome"')
        ->assertDontSeeHtml('data-test="report-presented"')
        ->assertSeeHtml('data-test="report-estimate-'.$other->id.'"')
        ->set("estimates.{$other->id}.presented", true)
        ->set("estimates.{$other->id}.result", 'pending')
        ->set("estimates.{$other->id}.objectionId", (string) Objection::factory()->create()->id)
        ->set('note', 'Signed for the front; thinking about the fence.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    $appointment = $this->appointment->fresh();

    expect($appointment->rest_of_report_due)->toBeFalse()
        ->and($appointment->state_note)->toBe('Signed for the front; thinking about the fence.')
        ->and($other->fresh()->proposal->result)->toBe(ProposalResult::Pending)
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::Sold);

    $this->actingAs($this->sam)->get(route('leads.show', $this->lead))->assertOk()->assertDontSee('data-test="rest-of-report"', false);
});

test('the rest of the report can be sent with nothing more ticked', function () {
    signFor($this->estimate, $this->frontOnly->id, $this->sam);

    Livewire::actingAs($this->sam)->test('pages::leads.report', ['lead' => $this->lead->fresh(), 'appointment' => $this->appointment])
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->appointment->fresh()->rest_of_report_due)->toBeFalse();
});

test('the card links a signed sale to its agreement, and says when a sale was not signed in Backoffice', function () {
    $other = estimateFor($this->lead->fresh(), $this->sam, ['Fence' => '900']);
    $agreement = signFor($this->estimate, $this->frontOnly->id, $this->sam);
    $fence = presentEstimate($other, $this->sam, [$this->appointment->id]);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $fence, 'sold', ['optionId' => $other->options->first()->id], $this->sam);

    $card = Livewire::actingAs($this->sam)->test('pages::leads.proposals', ['lead' => $this->lead->fresh()])
        ->assertSeeHtml('data-test="signed-agreement-link"')
        ->assertSee('Signed agreement: Pat Customer, Oct 8, 2026')
        ->assertSeeHtml('data-test="not-signed"')
        ->assertSee('Not signed in Backoffice');

    $card->assertSeeHtml(route('leads.agreement', [$this->lead, $agreement]));

    $this->actingAs($this->sam)->get(route('projects.show', $fence->fresh()->project))->assertOk()->assertSee('Not signed in Backoffice');
    $this->actingAs($this->sam)->get(route('leads.estimate', [$this->lead, $this->estimate]))->assertOk()->assertSee('data-test="estimate-agreement-link"', false);
});

test('a manager correcting the sale keeps the agreement, marked void with the reason, and the customer can sign again', function () {
    $agreement = signFor($this->estimate, $this->frontOnly->id, $this->sam);
    $proposal = Proposal::query()->sole();

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $proposal, 'The customer chose the whole exterior.', $this->manager);

    $agreement = $agreement->fresh();

    expect($agreement->isVoid())->toBeTrue()
        ->and($agreement->only(['voided_by_name', 'void_reason']))->toBe(['voided_by_name' => 'Morgan Manager', 'void_reason' => 'The customer chose the whole exterior.'])
        ->and($proposal->fresh()->agreement)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::LeadAgreementVoided)->sole()->reason)->toBe('The customer chose the whole exterior.');

    $this->actingAs($this->sam)->get(route('leads.agreement', [$this->lead, $agreement]))->assertOk()
        ->assertSee('data-test="agreement-void"', false)
        ->assertSee('The customer chose the whole exterior.');

    $again = signFor($this->estimate, $this->wholeExterior->id, $this->sam);

    expect($proposal->fresh()->agreement->is($again))->toBeTrue()
        ->and($proposal->fresh()->sold_cents)->toBe(845000)
        ->and(Agreement::query()->count())->toBe(2);
});

test('a signed agreement is never deleted', function () {
    $agreement = signFor($this->estimate, $this->frontOnly->id, $this->sam);

    expect(fn () => $agreement->delete())->toThrow(LogicException::class);
});

test('the audit log reads "Agreement signed"', function () {
    signFor($this->estimate, $this->frontOnly->id, $this->sam);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route('settings.audit_log', ['area' => 'leads']))->assertOk()
        ->assertSee('Lead · Agreement signed');
});
