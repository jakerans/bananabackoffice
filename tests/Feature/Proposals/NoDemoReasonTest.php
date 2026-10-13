<?php

use App\Actions\Proposals\RecordNoDemoReason;
use App\Actions\Proposals\SubmitAfterActionReport;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Why nothing was presented at an appointment that was run (decision 133: a
 * no-demo "records the coaching reason"; decision 134). The salesperson's own
 * words. It counts as a no-demo whatever they are.
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

    $this->lead = bookableLead($this->lakeview);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-08 20:35:00');
    $this->lead = $this->lead->fresh();
});

test('a report of "run, nothing presented" is refused without the reason, and nothing is saved', function (?string $reason) {
    $report = ['outcome' => 'run', 'presented' => 'no', 'noDemoReason' => $reason];

    try {
        app(SubmitAfterActionReport::class)->handle($this->lead, $this->appointment, $report, $this->sam);

        $this->fail('The report was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['noDemoReason' => ['Say why nothing was presented.']]);
    }

    // Not even the appointment's answer: the report is saved whole or not at all.
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(AuditEvent::query()->whereIn('action', [AuditAction::LeadAppointmentRun, AuditAction::LeadAppointmentNoDemoReason])->count())->toBe(0);
})->with([
    'nothing' => [null],
    'only spaces' => ['   '],
]);

test('the reason is kept with the appointment, with who gave it and when', function () {
    app(SubmitAfterActionReport::class)->handle($this->lead, $this->appointment, ['outcome' => 'run', 'presented' => 'no', 'noDemoReason' => '  Only one of the two owners was home.  '], $this->sam);

    $appointment = $this->appointment->fresh();

    expect($appointment->state)->toBe(AppointmentState::Run);
    expect($appointment->only(['no_demo_reason', 'no_demo_reason_by', 'no_demo_reason_by_name']))
        ->toBe(['no_demo_reason' => 'Only one of the two owners was home.', 'no_demo_reason_by' => $this->sam->id, 'no_demo_reason_by_name' => 'Sam Sales']);
    expect($appointment->no_demo_reason_at->toDateTimeString())->toBe('2026-10-08 20:35:00');

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentNoDemoReason)->sole();

    expect($event->subject_id)->toBe($this->lead->id);
    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->location_id)->toBe($this->lakeview->id);
    expect($event->values_before)->toBeNull();
    expect($event->values_after)->toEqual(['reason' => 'Only one of the two owners was home.', 'starts_at' => $appointment->starts_at->toIso8601String()]);

    // It is coaching between Sales and its managers, not part of the customer's story.
    expect(CustomerActivity::actions())->not->toContain(AuditAction::LeadAppointmentNoDemoReason);
});

test('it is still a no-demo whatever the reason (decision 133)', function () {
    app(SubmitAfterActionReport::class)->handle($this->lead, $this->appointment, ['outcome' => 'run', 'presented' => 'no', 'noDemoReason' => 'The customer only wanted a colour consultation.'], $this->sam);

    $october = SalesFigures::for($this->lakeview, CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'), $this->sam->id);

    expect([$october->appointmentsRun, $october->appointmentsPresented, $october->demoRate()])->toBe([1, 0, 0]);
    // It counts against Demo Rate only: Close Rate is demo to close, and there was no demo (decision 135).
    expect([$october->leadsDemoed, $october->closeRate()])->toBe([0, null]);

    // A proposal added later is what moves it, as before (decision 124).
    addProposal($this->lead->fresh(), $this->sam);

    expect(SalesFigures::for($this->lakeview, CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'), $this->sam->id)->demoRate())->toBe(100);
    // What was said is kept.
    expect($this->appointment->fresh()->no_demo_reason)->toBe('The customer only wanted a colour consultation.');
});

test('an appointment marked run from the lead page has its reason given afterwards, from the proposals card', function () {
    runAppointment($this->lead, $this->sam);

    $version = $this->lead->fresh()->lifecycle_version;

    $card = proposalsCard($this->sam, $this->lead->fresh())
        ->assertSee('has no proposal yet')
        ->assertSee('Say why nothing was presented')
        ->assertDontSeeHtml('data-test="no-demo-reason"')
        ->call('openNoDemoReason', $this->appointment->id)
        ->assertSet('noDemoReason', '')
        ->call('saveNoDemoReason')
        ->assertHasErrors(['noDemoReason'])
        ->assertSee('Say why nothing was presented.')
        ->set('noDemoReason', 'Ran out of time before pricing.')
        ->call('saveNoDemoReason')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="no-demo-reason"')
        ->assertSee('Ran out of time before pricing.')
        ->assertSee('Sam Sales')
        ->assertSee('Change the reason');

    // Saying why changes nothing about the Lead.
    expect($this->lead->fresh()->lifecycle_version)->toBe($version);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);

    // A manager puts it better; what it was is kept in the audit record.
    $this->travelTo('2026-10-09 14:00:00');

    proposalsCard($this->manager, $this->lead->fresh())
        ->call('openNoDemoReason', $this->appointment->id)
        ->assertSet('noDemoReason', 'Ran out of time before pricing.')
        ->set('noDemoReason', 'Arrived 40 minutes late, so there was no time to price it.')
        ->call('saveNoDemoReason')
        ->assertHasNoErrors();

    expect($this->appointment->fresh()->only(['no_demo_reason', 'no_demo_reason_by_name']))
        ->toBe(['no_demo_reason' => 'Arrived 40 minutes late, so there was no time to price it.', 'no_demo_reason_by_name' => 'Morgan Manager']);

    $events = AuditEvent::query()->where('action', AuditAction::LeadAppointmentNoDemoReason)->orderBy('id')->get();

    expect($events)->toHaveCount(2);
    expect($events[1]->values_before)->toEqual(['reason' => 'Ran out of time before pricing.']);

    // Saving the same words again writes nothing.
    app(RecordNoDemoReason::class)->handle($this->lead->fresh(), $this->appointment, 'Arrived 40 minutes late, so there was no time to price it.', $this->sam);

    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentNoDemoReason)->count())->toBe(2);

    // Once the appointment has a proposal it is no longer asked.
    addProposal($this->lead->fresh(), $this->sam);

    $card = proposalsCard($this->sam, $this->lead->fresh())
        ->assertDontSee('Say why nothing was presented')
        ->assertDontSeeHtml('data-test="no-demo-reason"');

    $card->call('openNoDemoReason', $this->appointment->id)->assertStatus(409);
});

test('the reason is refused when it is too long, for an appointment that was not run, and for another lead\'s appointment', function () {
    $give = fn ($lead, $appointment, ?string $reason) => fn () => app(RecordNoDemoReason::class)->handle($lead->fresh(), $appointment, $reason, $this->sam);

    // Still booked: nothing has been said about it.
    expect($give($this->lead, $this->appointment, 'Too early.'))->toThrow(ValidationException::class, 'Only an appointment of this lead that was run has this to say.');

    runAppointment($this->lead, $this->sam);

    expect($give($this->lead, $this->appointment, str_repeat('a', RecordNoDemoReason::LENGTH + 1)))->toThrow(ValidationException::class, 'Keep this to 1000 characters or fewer.');

    $other = bookableLead($this->lakeview);
    bookAppointment($other, $this->sam, $this->manager, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 16:00:00');
    $theirs = runAppointment($other->fresh(), $this->sam);

    expect($give($this->lead, $theirs, 'Wrong lead.'))->toThrow(ValidationException::class, 'Only an appointment of this lead that was run has this to say.');

    // None of the refusals wrote anything.
    expect($this->appointment->fresh()->no_demo_reason)->toBeNull();
    expect($theirs->fresh()->no_demo_reason)->toBeNull();
    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentNoDemoReason)->count())->toBe(0);
});

test('only the lead\'s salesperson or a manager there may give the reason', function (Closure $who, bool $allowed) {
    runAppointment($this->lead, $this->sam);

    $person = $who($this);
    $give = fn () => app(RecordNoDemoReason::class)->handle($this->lead->fresh(), $this->appointment, 'Customer had to leave.', $person);

    if ($allowed) {
        $give();

        expect($this->appointment->fresh()->no_demo_reason_by)->toBe($person->id);

        return;
    }

    expect($give)->toThrow(AuthorizationException::class);
    expect($this->appointment->fresh()->no_demo_reason)->toBeNull();

    // And they are not offered it.
    if ($person->can('view', $this->lead->fresh())) {
        proposalsCard($person, $this->lead->fresh())->assertDontSee('Say why nothing was presented');
    }
})->with([
    'the salesperson the lead is with' => [fn ($test) => $test->sam, true],
    'a manager at the location' => [fn ($test) => $test->manager, true],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
    'another Sales Consultant there' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->create(), false],
    'a manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('the report page asks why, shows the refusal at the question, and saves it', function () {
    Livewire::actingAs($this->sam)->test('pages::leads.report', ['lead' => $this->lead, 'appointment' => $this->appointment])
        ->set('outcome', 'run')
        ->assertDontSeeHtml('data-test="report-no-demo-reason"')
        ->set('presented', 'no')
        ->assertSeeHtml('data-test="report-no-demo-reason"')
        ->call('submit')
        ->assertHasErrors(['noDemoReason'])
        ->assertSee('Say why nothing was presented.')
        ->set('noDemoReason', 'Customer was called away ten minutes in.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->no_demo_reason)->toBe('Customer was called away ten minutes in.');
});
