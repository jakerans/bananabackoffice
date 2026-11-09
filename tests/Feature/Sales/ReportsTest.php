<?php

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AppointmentState;
use App\Enums\ReportRange;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Reports\AppointmentsReport;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Sales > Reports > Appointments, step 32a (docs/slice-32-reports-plan.md;
 * decisions 218 and 219): how many appointments were set, how many were on
 * the calendar, and how the ones that ran went.
 *
 * It is Thursday 8 October 2026 at Lakeview, in Eastern time. On Tuesday the
 * manager booked Sam for 3:00 pm today. This morning Alex in the Contact
 * Center booked Riley for 1:00 pm today and Sam for tomorrow. By 6:00 pm
 * Sam's appointment ran and sold for $8,450, and Riley's customer was not
 * there.
 */

beforeEach(function () {
    $this->travelTo('2026-10-06 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create(['name' => 'Alex Agent']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Sales']);

    $this->sold = bookableLead($this->lakeview);
    $this->sold->services()->sync([serviceNamed('Exterior Painting')->id => ['is_primary' => true]]);
    $this->soldAppointment = bookAppointment($this->sold, $this->sam, $this->manager, '2026-10-08', '15:00')->appointment;

    $this->travelTo('2026-10-08 13:00:00');

    $this->missed = bookableLead($this->lakeview);
    $this->missedAppointment = bookAppointment($this->missed, $this->riley, $this->agent, '2026-10-08', '13:00')->appointment;

    $this->tomorrow = bookableLead($this->lakeview);
    $this->tomorrowAppointment = bookAppointment($this->tomorrow, $this->sam, $this->agent, '2026-10-09', '10:00')->appointment;

    $this->travelTo('2026-10-08 21:00:00');

    runAppointment($this->sold->fresh(), $this->sam);
    $estimate = estimateFor($this->sold->fresh(), $this->sam);
    $proposal = presentEstimate($estimate, $this->sam);
    app(RecordProposalResult::class)->handle($this->sold->fresh(), $proposal, 'sold', ['optionId' => $estimate->options->first()->id], $this->sam);

    app(CloseOutAppointment::class)->handle($this->missed->fresh(), $this->missedAppointment, AppointmentState::NoShow->value, null, $this->riley);

    $this->travelTo('2026-10-08 22:00:00');
});

test('a manager sees today\'s appointments set, on the calendar, and how they went', function () {
    Livewire::actingAs($this->manager)->test('pages::sales.reports')
        ->assertOk()
        ->assertSeeHtml('data-test="sales-reports"')
        ->assertSee('Today: Thursday, October 8, 2026, at Lakeview.')
        ->assertSeeInOrder(['Appointments set', '2', 'Appointments ran', '1', 'Of 2 on the calendar.', 'Sold at them', '$8,450', '1 appointment sold'])
        ->assertSeeInOrder(['Ran: 1', 'No-show: 1', 'Cancelled: 0', 'Report due: 0', 'Still to come: 0'])
        ->assertSeeInOrder(['Sold: 1', 'Signed: 0', 'Pending: 0', 'Lost: 0', 'No demo: 0'])
        ->assertSeeInOrder(['Set, by who booked them', 'Alex Agent', '2']);

    $report = AppointmentsReport::for(collect([$this->lakeview]), ReportRange::Today);

    expect($report->bySalesperson()->mapWithKeys(fn (array $row): array => [$row['name'] => [$row['counts']['set'], $row['counts']['calendar'], $row['counts']['ran'], $row['counts']['no_show'], $row['counts']['sold_cents']]])->all())
        ->toBe(['Riley Sales' => [1, 1, 0, 1, 0], 'Sam Sales' => [1, 1, 1, 0, 845000]]);
});

test('each count opens its appointments, with the customer and the work', function () {
    $customer = $this->sold->fresh()->account->display_name;

    Livewire::actingAs($this->manager)->test('pages::sales.reports')
        ->call('listCount', AppointmentsReport::SOLD)
        ->assertSee('Sold: 1')
        ->assertSeeHtml('data-test="listed-'.$this->soldAppointment->id.'"')
        ->assertDontSeeHtml('data-test="listed-'.$this->missedAppointment->id.'"')
        ->assertSee($customer)
        ->assertSee('Exterior Painting')
        ->assertSee('Sold, $8,450')
        ->assertSee(route('leads.show', $this->sold), false)
        // One salesperson's set appointments: Sam's today was for tomorrow.
        ->call('listCount', AppointmentsReport::SET, (string) $this->sam->id)
        ->assertSee('Set, Sam Sales: 1')
        ->assertSeeHtml('data-test="listed-'.$this->tomorrowAppointment->id.'"')
        ->assertDontSeeHtml('data-test="listed-'.$this->soldAppointment->id.'"');
});

test('a cancellation booked again counts twice as set, the second a re-booking', function () {
    cancelAppointment($this->tomorrow->fresh(), $this->agent);
    $again = bookAppointment($this->tomorrow->fresh(), $this->sam, $this->agent, '2026-10-10', '10:00')->appointment;

    $report = AppointmentsReport::for(collect([$this->lakeview]), ReportRange::Today);

    expect($report->totals()['set'])->toBe(3)
        ->and($report->totals()['rebookings'])->toBe(1)
        ->and($report->isRebooking($again))->toBeTrue()
        ->and($report->isRebooking($this->tomorrowAppointment))->toBeFalse()
        // Cancelled for tomorrow, so not on today's calendar.
        ->and($report->totals()['cancelled'])->toBe(0);

    Livewire::actingAs($this->manager)->test('pages::sales.reports')
        ->assertSee('Booked in these days, for any day; 1 a re-booking.')
        ->call('listCount', AppointmentsReport::SET)
        ->assertSee('Re-booking');
});

test('yesterday, and any two days, are each counted', function () {
    Livewire::actingAs($this->manager)->test('pages::sales.reports', ['range' => 'yesterday'])
        ->assertSee('Yesterday: Wednesday, October 7, 2026')
        ->assertSeeInOrder(['Appointments set', '0', 'Appointments ran', '0']);

    // Days given the wrong way round are read in order.
    Livewire::actingAs($this->manager)->test('pages::sales.reports', ['range' => 'custom', 'from' => '2026-10-09', 'to' => '2026-10-06'])
        ->assertSee('Oct 6, 2026 to Oct 9, 2026')
        ->assertSeeInOrder(['Appointments set', '3', 'Appointments ran', '1', 'Of 3 on the calendar.']);
});

test('a month reads the same as Sales > Performance', function () {
    $report = AppointmentsReport::for(collect([$this->lakeview]), ReportRange::ThisMonth);
    $figures = SalesFigures::for($this->lakeview, CarbonImmutable::parse('2026-10-01', 'America/New_York'));

    expect($report->totals()['ran'])->toBe($figures->appointmentsRun)
        ->and($report->totals()['no_show'])->toBe($figures->appointmentsNoShow)
        ->and($report->totals()['calendar'])->toBe($figures->appointmentsBooked);
});

test('today is each Location\'s own day', function () {
    $harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $harbor)->create(['name' => 'Harper Sales']);

    // 1:30 am Friday in Lakeview, still 10:30 pm Thursday in Harbor.
    $this->travelTo('2026-10-09 05:30:00');
    $late = bookAppointment(bookableLead($harbor), $harborSales, $leader, '2026-10-12', '10:00')->appointment;

    $report = AppointmentsReport::for(collect([$this->lakeview, $harbor]), ReportRange::Today);

    // Lakeview's today is Friday, with nothing set yet; Harbor's is Thursday.
    expect($report->set->modelKeys())->toBe([$late->id]);

    Livewire::actingAs($leader)->test('pages::sales.reports')
        ->assertSee('at 2 locations, each in its own time.')
        ->set('locationId', (string) $this->lakeview->id)
        ->assertSee('at Lakeview.')
        ->assertSeeInOrder(['Appointments set', '0']);
});

test('a manager sees only their own Locations, whatever is asked for', function () {
    $elsewhere = Location::factory()->create(['name' => 'Elsewhere']);
    $theirManager = User::factory()->withRoleAt(Role::LocationManager, $elsewhere)->create();

    Livewire::actingAs($theirManager)->test('pages::sales.reports', ['location' => (string) $this->lakeview->id])
        ->assertSee('at Elsewhere.')
        ->assertDontSee('Lakeview')
        ->assertSeeInOrder(['Appointments set', '0']);
});

test('Reports is for managers: salespeople and the Contact Center cannot open it', function () {
    $this->actingAs($this->manager)->get(route('sales.reports'))->assertOk();
    $this->actingAs($this->manager)->get(route('sales.performance'))->assertSee(route('sales.reports'), false);

    $this->actingAs($this->sam)->get(route('sales.reports'))->assertForbidden();
    $this->actingAs($this->sam)->get(route('sales.performance'))->assertDontSee(route('sales.reports'), false);
    $this->actingAs($this->agent)->get(route('sales.reports'))->assertForbidden();
});

test('the table by salesperson downloads as a spreadsheet, with counts and staff names only', function () {
    $download = Livewire::actingAs($this->manager)->test('pages::sales.reports')->call('download', 'salespeople');

    $download->assertFileDownloaded('appointments-salespeople-today.csv', "Salesperson,Set,\"On the calendar\",Ran,No-show,Cancelled,\"Report due\",\"Still to come\",Sold,Signed,Pending,Lost,\"No demo\",\"Sold revenue\"\n"
        ."\"Riley Sales\",1,1,0,1,0,0,0,0,0,0,0,0,0.00\n"
        ."\"Sam Sales\",1,1,1,0,0,0,0,1,0,0,0,0,8450.00\n"
        ."Total,2,2,1,1,0,0,0,1,0,0,0,0,8450.00\n");
});
