<?php

use App\Actions\Appointments\MoveAppointment;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Staff\DeactivateStaffMember;
use App\Enums\AppointmentChangeType;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AppointmentChange;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Appointments\BookingResult;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Giving an upcoming appointment a new time, or another salesperson
 * (docs/slice-6-appointments-plan.md, step 6b; rulebook rules 6 and 7;
 * decisions 88 and 102).
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern. The appointment is
 * for the next day at 3:00 pm Eastern, 90 minutes, with Sam Sales.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->lead = bookableLead($this->lakeview);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;
    $this->lead = $this->lead->fresh();
});

function reschedule(Lead $lead, User $by, string $date = '2026-10-09', string $time = '11:00', int $minutes = 60): BookingResult
{
    return app(RescheduleAppointment::class)->handle($lead, $lead->standingAppointment(), $date, $time, $minutes, $by);
}

function moveTo(Lead $lead, User $salesperson, User $by, ?string $note = null): BookingResult
{
    return app(MoveAppointment::class)->handle($lead, $lead->standingAppointment(), $salesperson, $note, $by);
}

/*
|--------------------------------------------------------------------------
| Reschedule (rule 6)
|--------------------------------------------------------------------------
*/

test('rescheduling gives the appointment its new time and keeps the old one in its history', function () {
    $this->travelTo('2026-10-07 15:00:00');

    reschedule($this->lead, $this->manager);

    $appointment = $this->appointment->fresh();
    $lead = $this->lead->fresh();

    // Friday 9 October, 11:00 am Eastern is 15:00 UTC.
    expect($appointment->starts_at->toDateTimeString())->toBe('2026-10-09 15:00:00');
    expect($appointment->ends_at->toDateTimeString())->toBe('2026-10-09 16:00:00');
    expect($appointment->duration_minutes)->toBe(60);
    expect($appointment->stands())->toBeTrue();

    // The salesperson, the Lead and its issue are as they were (rule 6).
    expect($appointment->salesperson_id)->toBe($this->sam->id);
    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
    expect(Appointment::query()->count())->toBe(1);

    $change = AppointmentChange::query()->sole();

    expect($change->type)->toBe(AppointmentChangeType::Rescheduled);
    expect($change->from_starts_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect($change->from_ends_at->toDateTimeString())->toBe('2026-10-08 20:30:00');
    expect($change->to_starts_at->toDateTimeString())->toBe('2026-10-09 15:00:00');
    expect($change->from_salesperson_name)->toBe('Sam Sales');
    expect($change->to_salesperson_name)->toBe('Sam Sales');
    expect($change->changed_by)->toBe($this->manager->id);
    expect($change->changed_by_name)->toBe('Morgan Manager');

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentRescheduled)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->values_before)->toMatchArray(['duration_minutes' => 90]);
    expect($event->values_after)->toMatchArray(['duration_minutes' => 60, 'salesperson' => 'Sam Sales']);
    expect(CustomerActivity::label(AuditAction::LeadAppointmentRescheduled))->toBe('Appointment rescheduled');
});

test('the salesperson whose appointment it is can reschedule it, and is not told about their own change', function () {
    $before = DatabaseNotification::query()->count();

    Notification::fake();

    reschedule($this->lead, $this->sam);

    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-09 15:00:00');
    Notification::assertNothingSent();
    expect(DatabaseNotification::query()->count())->toBe($before);
});

test('when someone else reschedules, the salesperson is told in the app and by email, with nothing about the customer', function () {
    Notification::fake();

    $result = reschedule($this->lead, $this->manager);

    expect($result->emailSent)->toBeTrue();

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 2);
    Notification::assertNotSentTo($this->manager, AppointmentChanged::class);

    Notification::assertSentTo($this->sam, AppointmentChanged::class, function (AppointmentChanged $notice) {
        if ($notice->channel !== AppointmentChanged::EMAIL) {
            return false;
        }

        $mail = $notice->toMail($this->sam);
        $text = implode(' ', [$mail->subject, $mail->greeting, ...$mail->introLines, ...$mail->outroLines, $mail->actionUrl]);
        $customer = $this->lead->account;

        expect($notice->kind)->toBe(AppointmentChanged::RESCHEDULED);
        expect($text)->toContain('Fri, Oct 9, 2026 at 11:00 AM EDT');
        expect($text)->toContain(route('leads.show', $this->lead->id));
        expect($text)->not->toContain($customer->display_name);
        expect($text)->not->toContain((string) $this->lead->inquiry_phone);

        return true;
    });
});

test('who may reschedule', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        reschedule($this->lead, $actor);

        expect(AppointmentChange::query()->count())->toBe(1);

        return;
    }

    expect(fn () => reschedule($this->lead, $actor))->toThrow(AuthorizationException::class);
    expect(AppointmentChange::query()->count())->toBe(0);
    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
})->with([
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'Leadership' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'the salesperson whose appointment it is' => [fn ($test) => $test->sam, true],
    'a Call Center Agent (decision 88)' => [fn ($test) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), false],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('a reschedule is checked like a booking', function (string $date, string $time, int $minutes, string $message) {
    expect(fn () => reschedule($this->lead, $this->manager, $date, $time, $minutes))->toThrow(ValidationException::class, $message);

    expect(AppointmentChange::query()->count())->toBe(0);
    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
})->with([
    'a time already past' => ['2026-10-07', '09:00', 60, 'The appointment must be in the future.'],
    'not on the quarter hour' => ['2026-10-09', '11:05', 60, 'Choose a time on the quarter hour.'],
    'too long' => ['2026-10-09', '11:00', 300, 'Choose a length between 30 minutes and 4 hours'],
    'the time it already has' => ['2026-10-08', '15:00', 90, 'That is the time this appointment already has.'],
]);

test('a reschedule cannot overlap another appointment of the salesperson, but may overlap its own old time', function () {
    $other = bookableLead($this->lakeview);
    bookAppointment($other, $this->sam, $this->manager, time: '17:00');

    expect(fn () => reschedule($this->lead, $this->manager, '2026-10-08', '16:30', 90))
        ->toThrow(ValidationException::class, 'Sam Sales already has an appointment from 5:00 PM to 6:30 PM EDT then. Choose another time.');

    // Half an hour later than it was: it overlaps only itself, and ends as the other starts.
    reschedule($this->lead, $this->manager, '2026-10-08', '15:30', 90);

    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-08 19:30:00');
});

test('an appointment whose time has passed cannot be rescheduled (decision 102)', function () {
    $this->travelTo('2026-10-08 21:00:00');

    expect(fn () => reschedule($this->lead->fresh(), $this->manager, '2026-10-12', '11:00'))
        ->toThrow(ValidationException::class, 'This appointment is no longer upcoming');

    expect(AppointmentChange::query()->count())->toBe(0);
});

test('a lead that changed since the dialog opened is refused', function () {
    $stale = $this->lead->lifecycle_version - 1;

    expect(fn () => app(RescheduleAppointment::class)->handle($this->lead, $this->appointment, '2026-10-09', '11:00', 60, $this->manager, $stale))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expect(AppointmentChange::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Move to another salesperson (rule 7)
|--------------------------------------------------------------------------
*/

test('moving hands the appointment and the lead to the new salesperson and keeps both people in its history', function () {
    moveTo($this->lead, $this->riley, $this->manager, 'Sam is out sick.');

    $appointment = $this->appointment->fresh();
    $lead = $this->lead->fresh();

    expect($appointment->salesperson_id)->toBe($this->riley->id);
    expect($appointment->salesperson_name)->toBe('Riley Rep');
    expect($appointment->starts_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect($appointment->stands())->toBeTrue();

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->riley->id);
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->whereNull('ended_at')->sole()->user_id)->toBe($this->riley->id);

    $change = AppointmentChange::query()->sole();

    expect($change->type)->toBe(AppointmentChangeType::Moved);
    expect($change->from_salesperson_id)->toBe($this->sam->id);
    expect($change->from_salesperson_name)->toBe('Sam Sales');
    expect($change->to_salesperson_id)->toBe($this->riley->id);
    expect($change->to_salesperson_name)->toBe('Riley Rep');
    expect($change->note)->toBe('Sam is out sick.');
    expect($change->from_starts_at->equalTo($change->to_starts_at))->toBeTrue();

    // The issue is untouched: which salesperson it counts for after a move is still open.
    $issue = LeadIssue::query()->sole();

    expect($issue->counts())->toBeTrue();
    expect($issue->salesperson_id)->toBe($this->sam->id);

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentMoved)->sole();

    expect($event->values_before)->toMatchArray(['salesperson' => 'Sam Sales']);
    expect($event->values_after)->toMatchArray(['salesperson' => 'Riley Rep']);
    expect($event->reason)->toBe('Sam is out sick.');
    expect(CustomerActivity::label(AuditAction::LeadAppointmentMoved))->toBe('Appointment moved to another salesperson');
});

test('the new salesperson is told in the app and by email, the earlier one in the app only', function () {
    Notification::fake();

    $result = moveTo($this->lead, $this->riley, $this->manager);

    expect($result->emailSent)->toBeTrue();

    Notification::assertSentToTimes($this->riley, AppointmentChanged::class, 2);
    Notification::assertSentTo($this->riley, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::MOVED_TO_YOU && $notice->channel === AppointmentChanged::EMAIL);
    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::MOVED_AWAY && $notice->channel === AppointmentChanged::IN_APP);
});

test('only a manager may move an appointment', function (Closure $person) {
    expect(fn () => moveTo($this->lead, $this->riley, $person($this)))->toThrow(AuthorizationException::class);

    expect(AppointmentChange::query()->count())->toBe(0);
    expect($this->appointment->fresh()->salesperson_id)->toBe($this->sam->id);
})->with([
    'the salesperson whose appointment it is' => [fn ($test) => $test->sam],
    'the salesperson it would go to' => [fn ($test) => $test->riley],
    'a Call Center Agent (decision 88)' => [fn ($test) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create()],
]);

test('the new salesperson must have access there, be someone else, and be free then', function () {
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create(['name' => 'Harbor']))->create(['name' => 'Away Sales']);

    expect(fn () => moveTo($this->lead, $elsewhere, $this->manager))
        ->toThrow(ValidationException::class, 'Away Sales does not have Sales Consultant access at Lakeview.');

    expect(fn () => moveTo($this->lead, $this->sam, $this->manager))
        ->toThrow(ValidationException::class, 'Sam Sales already has this appointment.');

    $other = bookableLead($this->lakeview);
    bookAppointment($other, $this->riley, $this->manager, time: '16:00');

    expect(fn () => moveTo($this->lead, $this->riley, $this->manager))
        ->toThrow(ValidationException::class, 'Riley Rep already has an appointment from 4:00 PM to 5:30 PM EDT then. Choose someone else, or reschedule this appointment first.');

    expect(AppointmentChange::query()->count())->toBe(0);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
});

test('a manager gives an appointment a salesperson again after its own lost access (decision 95)', function () {
    app(DeactivateStaffMember::class)->handle($this->sam);

    expect($this->lead->fresh()->assigned_user_id)->toBeNull();

    moveTo($this->lead->fresh(), $this->riley, $this->manager);

    expect($this->appointment->fresh()->salesperson_id)->toBe($this->riley->id);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->riley->id);
});

test('an appointment whose time has passed cannot be moved (decision 102)', function () {
    $this->travelTo('2026-10-08 21:00:00');

    expect(fn () => moveTo($this->lead->fresh(), $this->riley, $this->manager))
        ->toThrow(ValidationException::class, 'This appointment is no longer upcoming');
});

test('a failure part-way through a move saves none of it', function () {
    // The assignment cannot be written, so the whole move is undone.
    LeadAssignment::creating(fn () => throw new RuntimeException('The database refused.'));

    try {
        moveTo($this->lead, $this->riley, $this->manager);
    } catch (RuntimeException) {
        // Expected.
    } finally {
        LeadAssignment::flushEventListeners();
    }

    expect(AppointmentChange::query()->count())->toBe(0);
    expect($this->appointment->fresh()->salesperson_id)->toBe($this->sam->id);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentMoved)->count())->toBe(0);
});
