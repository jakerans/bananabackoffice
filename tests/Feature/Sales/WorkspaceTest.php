<?php

use App\Actions\Agreements\SignAgreement;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\AgreementWording;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

/*
 * Sales > Workspace > Today, step 34a (docs/slice-34-sales-workspace-plan.md;
 * decision 212): the salesperson's day, today and the next seven days, with
 * each estimate and what is left to do.
 *
 * It is Thursday 8 October 2026; times are Eastern. Sam Sales has an
 * appointment at 3:00 pm today with an estimate presented and pending, one
 * tomorrow with no estimate, and one in ten days, which is not shown.
 */

beforeEach(function () {
    $this->travelTo('2026-10-06 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Sales']);

    $this->today = bookableLead($this->lakeview);
    $this->todayAppointment = bookAppointment($this->today, $this->sam, $this->manager, '2026-10-08', '15:00')->appointment;
    $this->estimate = estimateFor($this->today->fresh(), $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);

    $this->tomorrow = bookableLead($this->lakeview);
    $this->tomorrowAppointment = bookAppointment($this->tomorrow, $this->sam, $this->manager, '2026-10-09', '10:00')->appointment;

    $this->later = bookableLead($this->lakeview);
    $this->laterAppointment = bookAppointment($this->later, $this->sam, $this->manager, '2026-10-18', '10:00')->appointment;

    $this->rileys = bookableLead($this->lakeview);
    $this->rileysAppointment = bookAppointment($this->rileys, $this->riley, $this->manager, '2026-10-08', '11:00')->appointment;

    $this->travelTo('2026-10-08 14:00:00');
});

test('the salesperson sees today, then the coming days, with each estimate and what is left to do', function () {
    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()
        ->assertSeeInOrder(['Today', '3:00 PM', 'Upcoming', 'Residential Exterior estimate', '$3,200 to $8,450', 'Built, not presented', 'Tomorrow', '10:00 AM', 'No estimate yet.'])
        ->assertSee(route('leads.estimate.present', [$this->today, $this->estimate]), false)
        ->assertSee('data-test="workspace-appointment-'.$this->todayAppointment->id.'"', false)
        ->assertDontSee('data-test="workspace-appointment-'.$this->laterAppointment->id.'"', false)
        // Someone else's appointment is not theirs to see.
        ->assertDontSee('data-test="workspace-appointment-'.$this->rileysAppointment->id.'"', false)
        ->assertDontSee('data-test="workspace-who"', false);
});

test('the day follows the appointment: going on, report due, and the rest after signing', function () {
    $this->travelTo('2026-10-08 19:30:00');

    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()->assertSee('Going on now');

    $this->travelTo('2026-10-08 21:00:00');

    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()
        ->assertSee('Report due')
        ->assertSee('data-test="workspace-report-'.$this->todayAppointment->id.'"', false);

    $this->travelTo('2026-10-08 19:30:00');
    AgreementWording::factory()->create();
    app(SignAgreement::class)->handle($this->today->fresh(), $this->estimate, $this->estimate->options->last(), 'Pat Customer', true, [array_fill(0, 12, [0.5, 0.5])], $this->sam);

    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()
        ->assertSeeInOrder(['Signed. Finish the report', 'Signed: Front only, $3,200'])
        ->assertSee(route('leads.report', [$this->today, $this->todayAppointment]), false)
        ->assertDontSee('data-test="workspace-present-'.$this->estimate->id.'"', false);
});

test('a report left from an earlier day is listed first', function () {
    $this->travelTo('2026-10-09 18:00:00');

    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()
        ->assertSeeInOrder(['Reports still due from earlier days', 'Thu, Oct 8 at 3:00 PM', 'Today', '10:00 AM']);
});

test('an estimate sold without signing, presented or lost reads so', function () {
    $this->travelTo('2026-10-08 21:00:00');
    runAppointment($this->today->fresh(), $this->sam);
    $proposal = presentEstimate($this->estimate, $this->sam);

    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()->assertSee('Presented, pending')->assertSee('Run');

    app(RecordProposalResult::class)->handle($this->today->fresh(), $proposal, 'sold', ['optionId' => $this->estimate->options->first()->id], $this->sam);

    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()->assertSee('Sold: Whole exterior, $8,450');
});

test('a manager sees their own day, everyone\'s at their Locations, or one salesperson\'s', function () {
    Livewire::actingAs($this->manager)->test('pages::sales.workspace')
        ->assertSeeHtml('data-test="workspace-who"')
        ->assertSee('Sam Sales')
        ->assertSeeHtml('data-test="workspace-empty"')
        ->set('who', 'everyone')
        ->assertSeeHtml('data-test="workspace-appointment-'.$this->todayAppointment->id.'"')
        ->assertSeeHtml('data-test="workspace-appointment-'.$this->rileysAppointment->id.'"')
        ->set('who', (string) $this->riley->id)
        ->assertSeeHtml('data-test="workspace-appointment-'.$this->rileysAppointment->id.'"')
        ->assertDontSeeHtml('data-test="workspace-appointment-'.$this->todayAppointment->id.'"');
});

test('a salesperson cannot look at someone else\'s day, and the page is for Sales only', function () {
    Livewire::actingAs($this->sam)->test('pages::sales.workspace', ['who' => (string) $this->riley->id])
        ->assertSeeHtml('data-test="workspace-appointment-'.$this->todayAppointment->id.'"')
        ->assertDontSeeHtml('data-test="workspace-appointment-'.$this->rileysAppointment->id.'"');

    // Another Location's manager sees none of these.
    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();
    Livewire::actingAs($elsewhere)->test('pages::sales.workspace', ['who' => 'everyone'])->assertSeeHtml('data-test="workspace-empty"');

    $this->actingAs(User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create())->get(route('sales.workspace'))->assertForbidden();
});

test('Sales opens the Workspace on Today, and Commissions opens My pay', function () {
    $this->actingAs($this->sam)->get(route('sales.workspace'))->assertOk()
        ->assertSee(route('pay'), false)
        ->assertSee('Commissions');
});
