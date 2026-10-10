<?php

use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\User;
use App\Support\Navigation\Navigation;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Drawer\Utils;
use Livewire\Livewire;

/*
 * The screens of step 6a (docs/slice-6-appointments-plan.md): the Appointment
 * card on a Lead, Sales > Calendar, and the bell in the header.
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
});

function appointmentCard(User $user, Lead $lead)
{
    return Livewire::actingAs($user)->test('pages::leads.appointment', ['lead' => $lead]);
}

/*
|--------------------------------------------------------------------------
| The Appointment card
|--------------------------------------------------------------------------
*/

test('the lead page shows the appointment card', function () {
    $lead = bookableLead($this->lakeview);

    $this->actingAs($this->manager)->get(route('leads.show', $lead))
        ->assertOk()
        ->assertSeeHtml('data-test="lead-appointment"')
        ->assertSee('Book appointment');
});

test('a lead that is not ready says what is missing and offers no booking', function () {
    $lead = Lead::factory()->at($this->lakeview)->working()->create();

    appointmentCard($this->manager, $lead)
        ->assertSee('No appointment can be booked yet.')
        ->assertSee('Qualify this lead first.')
        ->assertSee('Link this lead to a customer first.')
        ->assertDontSeeHtml('data-test="book-appointment"')
        // Whatever the browser sends, the booking is refused with the reason.
        ->call('openBook')
        ->set('appointmentDate', '2026-10-08')
        ->set('appointmentTime', '15:00')
        ->call('book')
        ->assertHasErrors('booking')
        ->assertSee('An appointment cannot be booked for this lead yet. It still needs: to be qualified; a customer; a property.');

    expect(Appointment::query()->count())->toBe(0);
});

test('a manager books an appointment from the card and the lead page is told', function () {
    $lead = bookableLead($this->lakeview);

    appointmentCard($this->manager, $lead)
        ->assertSeeHtml('data-test="book-appointment"')
        ->call('openBook')
        // The only salesperson there is already chosen, and the length starts at 90 minutes.
        ->assertSet('salespersonId', $this->sam->id)
        ->assertSet('appointmentMinutes', 90)
        ->assertSee('In Lakeview time: EDT (America/New_York).')
        ->set('appointmentDate', '2026-10-08')
        ->set('appointmentTime', '15:00')
        ->call('book')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSee('Thu, Oct 8, 2026')
        ->assertSee('3:00 PM to 4:30 PM EDT')
        ->assertSee('Sam Sales')
        ->assertSee('Morgan Manager')
        ->assertDontSeeHtml('data-test="book-appointment"')
        ->assertSeeHtml('data-test="appointment-booked-in-error"');

    expect($lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

test('the booking dialog asks for everything and shows each refusal at its field', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    $card = appointmentCard($this->manager, $lead)->call('openBook');

    $card->set('salespersonId', null)->call('book')
        ->assertHasErrors(['salespersonId', 'appointmentDate', 'appointmentTime'])
        ->assertSee('Choose the salesperson.')
        ->assertSee('Choose the day of the appointment.')
        ->assertSee('Choose the time of the appointment.');

    // The same time as Sam's other appointment: refused at the time field, without naming the other customer.
    $card->set('salespersonId', $this->sam->id)->set('appointmentDate', '2026-10-08')->set('appointmentTime', '15:30')->call('book')
        ->assertHasErrors('appointmentTime')
        ->assertSee('Sam Sales already has an appointment from 3:00 PM to 4:30 PM EDT then. Choose another time.');

    $card->set('appointmentTime', '15:10')->call('book')->assertHasErrors('appointmentTime')->assertSee('Choose a time on the quarter hour.');

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

test('only people offered by the page can be booked, whatever the browser sends', function () {
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Harbor Sales']);
    $lead = bookableLead($this->lakeview);

    appointmentCard($this->manager, $lead)
        ->call('openBook')
        ->assertDontSee('Harbor Sales')
        ->set('salespersonId', $elsewhere->id)
        ->set('appointmentDate', '2026-10-08')
        ->set('appointmentTime', '15:00')
        ->call('book')
        ->assertHasErrors('salespersonId')
        ->assertSee('Choose one of the people listed.');

    expect(Appointment::query()->count())->toBe(0);
});

test('a sales consultant sees the booking only on a lead assigned to them, and only themselves to choose', function () {
    User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Casey Colleague']);

    $theirs = bookableLead($this->lakeview, $this->sam);
    $unassigned = bookableLead($this->lakeview);

    appointmentCard($this->sam, $unassigned)
        ->assertDontSeeHtml('data-test="book-appointment"')
        ->assertSee('A manager or the call center books it.')
        ->call('openBook')
        ->assertForbidden();

    appointmentCard($this->sam, $theirs)
        ->assertSeeHtml('data-test="book-appointment"')
        ->call('openBook')
        ->assertSet('salespersonId', $this->sam->id)
        ->assertDontSee('Casey Colleague')
        ->set('appointmentDate', '2026-10-08')
        ->set('appointmentTime', '15:00')
        ->call('book')
        ->assertHasNoErrors();

    expect($theirs->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

test('someone who cannot see the lead cannot open its appointment card', function () {
    $outsider = User::factory()->withRoleAt(Role::LocationManager, $this->harbor)->create();

    appointmentCard($outsider, bookableLead($this->lakeview))->assertForbidden();
});

test('a manager marks an appointment booked in error from the card; the salesperson and an agent cannot', function () {
    $agent = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create();
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    foreach ([$this->sam, $agent] as $person) {
        appointmentCard($person, $lead)
            ->assertSee('3:00 PM to 4:30 PM EDT')
            ->assertDontSeeHtml('data-test="appointment-booked-in-error"')
            ->call('openBookedInError')
            ->assertForbidden();
    }

    appointmentCard($this->manager, $lead)
        ->call('openBookedInError')
        ->call('markBookedInError')
        ->assertHasErrors('bookedInErrorNote')
        ->assertSee('Say why this appointment was booked in error.')
        ->set('bookedInErrorNote', 'Booked for the wrong customer.')
        ->call('markBookedInError')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSee('Booked in error')
        ->assertSee('Booked for the wrong customer.')
        // It can be booked again from the same card.
        ->assertSeeHtml('data-test="book-appointment"');

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect(LeadIssue::query()->counted()->count())->toBe(0);
});

test('the card says when a standing appointment needs a salesperson', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    appointmentCard($this->manager, $lead)->assertDontSeeHtml('data-test="appointment-needs-salesperson"');

    // The salesperson no longer has the Lead, as when their access is removed.
    $lead->forceFill(['assigned_user_id' => null])->save();

    appointmentCard($this->manager, $lead)
        ->assertSeeHtml('data-test="appointment-needs-salesperson"')
        ->assertSee('Sam Sales is no longer working this lead, so this appointment needs a salesperson.');
});

test('the lead page refuses to open move, return and disqualify while an appointment is upcoming', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    $page = Livewire::actingAs($this->owner)->test('pages::leads.show', ['lead' => $lead->fresh()]);

    $page->assertSee('Appointment booked');

    foreach (['openRouting', 'openReturn'] as $opener) {
        $page->call($opener)->assertOk();
    }

    expect($lead->fresh()->location_id)->toBe($this->lakeview->id);
    expect(AuditEvent::query()->whereIn('action', [AuditAction::LeadTransferred, AuditAction::LeadReturnedToCentral, AuditAction::LeadDisqualified])->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Sales > Calendar
|--------------------------------------------------------------------------
*/

test('calendar is a live item in the sales center and opens for people with sales access', function () {
    $navigation = app(Navigation::class);
    $item = $navigation->find('sales.calendar');

    expect($item->isLive())->toBeTrue();
    expect($item->route)->toBe('sales.calendar');

    foreach ([$this->owner, $this->manager, $this->sam] as $person) {
        $this->actingAs($person)->get(route('sales.calendar'))->assertOk()->assertSee('Calendar');
    }
});

test('calendar is refused to people without sales access and to a guest', function (Role $role) {
    $person = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($person)->get(route('sales.calendar'))->assertForbidden();
    Livewire::actingAs($person)->test('pages::sales.calendar')->assertForbidden();
})->with([Role::CallCenterAgent, Role::Finance, Role::ProductionManager]);

test('a guest is sent to sign in from the calendar', function () {
    $this->get(route('sales.calendar'))->assertRedirect(route('login'));
});

test('a salesperson sees their own appointments and a manager sees their locations\'', function () {
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Casey Colleague']);
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Harbor Sales']);

    $sams = bookableLead($this->lakeview);
    $sams->account->forceFill(['display_name' => 'Sam Customer'])->save();
    $colleagues = bookableLead($this->lakeview);
    $colleagues->account->forceFill(['display_name' => 'Colleague Customer'])->save();
    $harbors = bookableLead($this->harbor);
    $harbors->account->forceFill(['display_name' => 'Harbor Customer'])->save();

    bookAppointment($sams, $this->sam, $this->owner);
    bookAppointment($colleagues, $colleague, $this->owner);
    bookAppointment($harbors, $harborSales, $this->owner);

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSee('Your upcoming appointments')
        ->assertSee('Sam Customer')
        ->assertSee('3:00 PM to 4:30 PM EDT')
        ->assertDontSee('Colleague Customer')
        ->assertDontSee('Harbor Customer')
        ->assertDontSeeHtml('data-test="calendar-salesperson"');

    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertSee('Sam Customer')
        ->assertSee('Colleague Customer')
        ->assertSee('Casey Colleague')
        ->assertDontSee('Harbor Customer');

    // Each time is in its own Location's time.
    Livewire::actingAs($this->owner)->test('pages::sales.calendar')
        ->assertSee('Harbor Customer')
        ->assertSee('3:00 PM to 4:30 PM PDT')
        ->assertSee('Thursday, October 8, 2026');
});

test('the calendar leaves out appointments that are over or were booked in error', function () {
    $past = bookableLead($this->lakeview);
    $past->account->forceFill(['display_name' => 'Past Customer'])->save();
    $undone = bookableLead($this->lakeview);
    $undone->account->forceFill(['display_name' => 'Undone Customer'])->save();

    bookAppointment($past, $this->sam, $this->manager, '2026-10-07', '12:00', 60);
    bookAppointment($undone, $this->sam, $this->manager);
    app(MarkAppointmentBookedInError::class)->handle($undone->fresh(), $undone->standingAppointment(), 'Wrong customer.', $this->manager);

    // Later the same day, after the first appointment has finished.
    $this->travelTo('2026-10-07 17:30:00');

    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertSee('No upcoming appointments.')
        ->assertDontSee('Past Customer')
        ->assertDontSee('Undone Customer');
});

test('a salesperson who lost access to a location no longer sees their appointments there', function () {
    $lead = bookableLead($this->lakeview);
    $lead->account->forceFill(['display_name' => 'Lakeview Customer'])->save();
    bookAppointment($lead, $this->sam, $this->manager);

    $this->sam->accessGrants()->delete();
    $this->sam->unsetRelation('accessGrants');
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create();
    $this->sam->accessGrants()->create($elsewhere->accessGrants->first()->only(['role', 'scope', 'location_id']));
    $this->sam->unsetRelation('accessGrants');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertDontSee('Lakeview Customer');
});

/*
|--------------------------------------------------------------------------
| The bell
|--------------------------------------------------------------------------
*/

test('the header shows the bell with a count of what the person has not seen', function () {
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    $this->actingAs($this->sam)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="notification-bell"')
        ->assertSeeHtml('data-test="notification-count"');

    // The manager booked it and has nothing to see.
    $this->actingAs($this->manager)->get(route('dashboard'))
        ->assertSeeHtml('data-test="notification-bell"')
        ->assertDontSeeHtml('data-test="notification-count"');
});

test('opening the bell lists the notices with their link, marks them seen, and still shows which were new', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    $bell = Livewire::actingAs($this->sam)->test('pages::notifications.bell');

    $bell->assertSeeHtml('data-test="notification-count"')
        ->call('open')
        ->assertSee('A new appointment was booked for you on Thu, Oct 8, 2026 at 3:00 PM EDT.')
        ->assertSeeHtml('href="'.route('leads.show', $lead).'"')
        ->assertSeeHtml('data-test="notice-new"')
        ->assertDontSeeHtml('data-test="notification-count"');

    expect($this->sam->unreadNotifications()->count())->toBe(0);
    expect(DatabaseNotification::query()->sole()->read_at)->not->toBeNull();

    // Next time it is listed, and no longer new.
    Livewire::actingAs($this->sam)->test('pages::notifications.bell')
        ->call('open')
        ->assertSee('A new appointment was booked for you')
        ->assertDontSeeHtml('data-test="notice-new"');
});

test('a person sees only their own notices', function () {
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    Livewire::actingAs($colleague)->test('pages::notifications.bell')
        ->assertDontSeeHtml('data-test="notification-count"')
        ->call('open')
        ->assertSee('Nothing yet.')
        ->assertDontSee('A new appointment was booked for you');

    // Sam's notice was not marked seen by someone else looking at their own bell.
    expect($this->sam->unreadNotifications()->count())->toBe(1);
});

test('an owner viewing backoffice as someone does not use up that person\'s notices', function () {
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    impersonate($this->owner, $this->sam);

    // The bell is the component in the header, above the page.
    $response = $this->get(route('dashboard'))->assertOk()->assertSeeHtml('data-test="notification-count"');
    $bell = Utils::extractAttributeDataFromHtml((string) $response->getContent(), 'wire:snapshot');

    sendLivewireAction($bell, 'open')->assertOk();

    expect($this->sam->unreadNotifications()->count())->toBe(1);
});
