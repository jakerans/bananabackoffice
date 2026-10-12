<?php

use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

/*
 * The Appointments section of the customer page (product-architecture.md, 11).
 * Found missing by the plan check after slice 6 (docs/decisions.md, 122).
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Harbor Sales']);

    $this->lead = bookableLead($this->lakeview);
    $this->account = $this->lead->account;
});

function customerPage(User $user, $account)
{
    return Livewire::actingAs($user)->test('pages::customers.show', ['account' => $account]);
}

test('a customer with no appointments says so', function () {
    customerPage($this->manager, $this->account)
        ->assertSeeHtml('data-test="customer-appointments"')
        ->assertSee('No appointments to show.');
});

test('the customer page lists the customer\'s appointments, latest first, each in its own state', function () {
    // One that will have passed, one cancelled, one booked in error, one upcoming.
    $passed = bookAppointment($this->lead, $this->sam, $this->manager, '2026-10-08', '09:00')->appointment;

    $this->travelTo('2026-10-09 14:00:00');

    $cancelledLead = Lead::factory()->at($this->lakeview)->from($this->account, $this->account->primaryContact(), Property::factory()->of($this->account)->create())->qualified()->create();
    bookAppointment($cancelledLead, $this->sam, $this->manager, '2026-10-12', '10:00');
    cancelAppointment($cancelledLead->fresh(), $this->manager);

    $undoneLead = Lead::factory()->at($this->lakeview)->from($this->account, $this->account->primaryContact(), $this->lead->property)->qualified()->create();
    $undone = bookAppointment($undoneLead, $this->sam, $this->manager, '2026-10-13', '10:00')->appointment;
    app(MarkAppointmentBookedInError::class)->handle($undoneLead->fresh(), $undone, 'Wrong customer.', $this->manager);

    // The passed one has no answer yet, so the upcoming one belongs to another Lead of the customer (decision 126).
    $upcomingLead = Lead::factory()->at($this->lakeview)->from($this->account, $this->account->primaryContact(), $this->lead->property)->qualified()->create();
    bookAppointment($upcomingLead, $this->sam, $this->manager, '2026-10-14', '15:00');

    $page = customerPage($this->manager, $this->account)
        ->assertDontSee('No appointments to show.')
        ->assertSeeInOrder([
            'Wed, Oct 14, 2026', '3:00 PM', '4:30 PM EDT', 'Upcoming',
            'Tue, Oct 13, 2026', 'Booked in error',
            'Mon, Oct 12, 2026', 'Cancelled: No longer needed',
            'Thu, Oct 8, 2026', 'Time has passed',
        ])
        ->assertSee('Sam Sales')
        ->assertSee('Lakeview');

    expect(substr_count($page->html(), 'data-test="customer-appointment"'))->toBe(4);

    // Each row opens its Lead.
    $page->assertSeeHtml(route('leads.show', $passed->lead_id));
});

test('each appointment is shown in its own location\'s time', function () {
    $harborLead = Lead::factory()->at($this->harbor)->from($this->account, $this->account->primaryContact(), $this->lead->property)->qualified()->create();
    bookAppointment($harborLead, $this->harborSales, $this->owner, '2026-10-08', '15:00');

    customerPage($this->owner, $this->account)
        ->assertSee('3:00 PM')
        ->assertSee('4:30 PM PDT')
        ->assertSee('Harbor Sales');
});

test('an appointment for a lead the person cannot see is not listed or hinted at', function () {
    bookAppointment($this->lead, $this->sam, $this->manager, '2026-10-08', '15:00');

    $harborLead = Lead::factory()->at($this->harbor)->from($this->account, $this->account->primaryContact(), $this->lead->property)->qualified()->create();
    bookAppointment($harborLead, $this->harborSales, $this->owner, '2026-10-09', '15:00');

    // The Lakeview manager sees only the Lakeview appointment.
    $page = customerPage($this->manager, $this->account)
        ->assertSee('Thu, Oct 8, 2026')
        ->assertDontSee('Fri, Oct 9, 2026')
        ->assertDontSee('Harbor Sales');

    expect(substr_count($page->html(), 'data-test="customer-appointment"'))->toBe(1);

    // The Owner sees both.
    expect(substr_count(customerPage($this->owner, $this->account)->html(), 'data-test="customer-appointment"'))->toBe(2);
});

test('an appointment follows its lead: once the lead moves somewhere the person cannot see, it is no longer listed', function () {
    bookAppointment($this->lead, $this->sam, $this->manager, '2026-10-08', '09:00');

    // A second Lead of the customer stays at Lakeview, so the manager can still open the customer.
    Lead::factory()->at($this->lakeview)->from($this->account)->create();

    $this->travelTo('2026-10-09 14:00:00');

    customerPage($this->manager, $this->account)->assertSee('Thu, Oct 8, 2026');

    $this->lead->fresh()->forceFill(['location_id' => $this->harbor->id])->save();

    customerPage($this->manager, $this->account)
        ->assertDontSee('Thu, Oct 8, 2026')
        ->assertSee('No appointments to show.');
});

test('someone who sees customers but not leads is shown no appointments section at all', function () {
    bookAppointment($this->lead, $this->sam, $this->manager, '2026-10-08', '15:00');

    $finance = User::factory()->withRoleEverywhere(Role::Finance)->create();

    $this->actingAs($finance)
        ->get(route('customers.show', $this->account))
        ->assertOk()
        ->assertDontSee('data-test="customer-appointments"', false)
        ->assertDontSee('data-test="customer-appointment"', false)
        ->assertDontSee('No appointments to show.')
        ->assertDontSee('Thu, Oct 8, 2026');
});

test('another customer\'s appointments are not shown', function () {
    $other = bookableLead($this->lakeview);
    bookAppointment($other, $this->sam, $this->manager, '2026-10-08', '15:00');

    customerPage($this->manager, $this->account)->assertSee('No appointments to show.');
    customerPage($this->manager, $other->account)->assertSee('Thu, Oct 8, 2026');
});
