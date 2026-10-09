<?php

use App\Actions\Leads\CaptureLead;
use App\Actions\Leads\RouteLead;
use App\Enums\CadenceState;
use App\Enums\QueueEventType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadChannel;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\ProjectSize;
use App\Models\User;
use App\Support\Access\Impersonation;
use App\Support\Leads\ServiceSelection;
use App\Support\Queue\QueueClaim;
use App\Support\Queue\QueueHistory;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

/*
 * Every newly captured Lead enters the phone cadence, in the transaction that
 * creates it. Leads that existed before never enter by themselves.
 */

function phoneCallChannel(): LeadChannel
{
    return LeadChannel::query()->where('name', 'Phone Call')->sole();
}

function captureThroughTheAction(User $by, ?Location $location = null): Lead
{
    return app(CaptureLead::class)->handle(
        $by,
        phoneCallChannel(),
        ['name' => 'Maria Lopez', 'phone' => '555-201-3344'],
        ProjectSize::forKey(ProjectSize::SMALL),
        ServiceSelection::fromIds([serviceNamed('Interior Painting')->id]),
        $location,
    );
}

test('a lead captured unrouted inside Eastern calling hours is due at once', function () {
    // Wednesday 7 October 2026, 10:00 am Eastern.
    $this->travelTo('2026-10-07 14:00:00');

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Avery Agent']);

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('phone', '555-201-3344')
        ->set('channelId', phoneCallChannel()->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();
    $cadence = LeadCadence::query()->sole();
    $event = LeadQueueEvent::query()->sole();

    expect($cadence->lead_id)->toBe($lead->id);
    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->cycle)->toBe(1);
    expect($cadence->attempts_made)->toBe(0);
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect($cadence->entered_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect($cadence->last_counted_attempt_at)->toBeNull();
    expect($cadence->held_by_user_id)->toBeNull();

    expect($event->type)->toBe(QueueEventType::Entered);
    expect($event->lead_id)->toBe($lead->id);
    expect($event->lead_cadence_id)->toBe($cadence->id);
    expect($event->cycle)->toBe(1);
    expect($event->step)->toBe(1);
    expect($event->location_id)->toBeNull();
    expect($event->actor_id)->toBe($agent->id);
    expect($event->actor_name)->toBe('Avery Agent');
    expect($event->impersonator_id)->toBeNull();
});

test('a lead captured at a location is scheduled in that location\'s timezone', function () {
    // 12:30 UTC: 8:30 am Eastern, 5:30 am Pacific.
    $this->travelTo('2026-10-07 12:30:00');

    $portland = Location::factory()->create(['name' => 'Portland', 'timezone' => 'America/Los_Angeles']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $portland)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallChannel()->id)
        ->set('locationId', (string) $portland->id)
        ->call('saveAndAddAnother')
        ->assertHasNoErrors();

    $cadence = LeadCadence::query()->sole();
    $event = LeadQueueEvent::query()->sole();

    // 8:00 am Pacific that morning.
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 15:00:00');
    expect($event->location_id)->toBe($portland->id);
    expect($event->location_name)->toBe('Portland');
});

test('a lead captured after closing waits for the next opening', function () {
    // 9:00 pm Eastern exactly.
    $this->travelTo('2026-10-08 01:00:00');

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    captureThroughTheAction($agent);

    expect(LeadCadence::query()->sole()->due_at->toDateTimeString())->toBe('2026-10-08 12:00:00');
});

test('a sales consultant\'s own capture is assigned to them and still enters the shared queue', function () {
    $this->travelTo('2026-10-07 14:00:00');

    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = captureThroughTheAction($consultant, $lakeview);

    expect($lead->fresh()->assigned_user_id)->toBe($consultant->id);
    expect(LeadCadence::query()->where('lead_id', $lead->id)->count())->toBe(1);
    expect(queueHistory($lead))->toBe(['entered']);
});

test('a repeat inquiry recorded for an existing customer enters the cadence too', function () {
    $this->travelTo('2026-10-07 14:00:00');

    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $maria = Contact::factory()->named('Maria', 'Lopez')->create();
    $account = Account::factory()->withPrimaryContact($maria)->create();
    $earlier = Lead::factory()->at($lakeview)->from($account)->create();

    Livewire::actingAs($consultant)
        ->withQueryParams(['customer' => $account->id])
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('channelId', phoneCallChannel()->id)
        ->set('locationId', (string) $lakeview->id)
        ->call('save')
        ->assertHasNoErrors();

    $new = Lead::query()->whereKeyNot($earlier->id)->sole();

    expect($new->account_id)->toBe($account->id);
    expect(LeadCadence::query()->sole()->lead_id)->toBe($new->id);
    expect(queueHistory($new))->toBe(['entered']);
    expect(queueHistory($earlier))->toBe([]);
});

test('pressing save twice records one lead, one cadence and one entered event', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallChannel()->id)
        ->call('save')
        ->call('save')
        ->call('saveAndAddAnother');

    expect(Lead::query()->count())->toBe(1);
    expect(LeadCadence::query()->count())->toBe(1);
    expect(LeadQueueEvent::query()->count())->toBe(1);
});

test('the lead and its cadence are saved together or not at all', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->mock(QueueHistory::class)
        ->shouldReceive('record')
        ->andThrow(new RuntimeException('The queue history could not be written.'));

    expect(fn () => captureThroughTheAction($agent))->toThrow(RuntimeException::class);

    expect(Lead::query()->count())->toBe(0);
    expect(LeadCadence::query()->count())->toBe(0);
    expect(LeadQueueEvent::query()->count())->toBe(0);
});

test('the database allows one cadence per lead', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = captureThroughTheAction($agent);

    expect(fn () => enterQueue($lead))->toThrow(UniqueConstraintViolationException::class);
});

test('a capture made while impersonating names the owner on the entered event', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Roseanne Smith']);

    app(Impersonation::class)->serveAs($owner, $agent);

    try {
        captureThroughTheAction($agent);
    } finally {
        app(Impersonation::class)->forgetRequest();
    }

    $event = LeadQueueEvent::query()->sole();

    expect($event->actor_name)->toBe('Roseanne Smith');
    expect($event->impersonator_id)->toBe($owner->id);
    expect($event->impersonator_name)->toBe('Jacob Ransom');
});

test('a lead that existed before has no cadence and is never offered', function () {
    $this->travelTo('2026-10-07 14:00:00');

    $lakeview = Location::factory()->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Lead::factory()->at($lakeview)->create();
    Lead::factory()->create();

    expect(LeadCadence::query()->count())->toBe(0);
    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(LeadCadence::query()->count())->toBe(0);
    expect(LeadQueueEvent::query()->count())->toBe(0);
});

test('an unrouted lead uses Eastern, then its location after routing, and its due time never moves', function () {
    // 11:30 UTC: 7:30 am Eastern, 4:30 am Pacific.
    $this->travelTo('2026-10-07 11:30:00');

    $portland = Location::factory()->create(['timezone' => 'America/Los_Angeles']);
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $lead = captureThroughTheAction($agent);
    $cadence = LeadCadence::query()->sole();

    // 8:00 am Eastern.
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 12:00:00');

    app(RouteLead::class)->handle($lead->fresh(), $portland, null, $agent);

    expect($cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-07 12:00:00');

    // 8:30 am Eastern is 5:30 am in Portland: due, but not offered.
    $this->travelTo('2026-10-07 12:30:00');
    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);

    // 8:00 am in Portland.
    $this->travelTo('2026-10-07 15:00:00');
    $claim = nextLead($agent);

    expect($claim->lead?->id)->toBe($lead->id);
    expect($cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-07 12:00:00');

    // The next attempt is scheduled in Portland's time: 8:50 pm there, plus 30 minutes, is closed.
    $this->travelTo('2026-10-08 03:50:00');
    logAttempt($lead, $agent);

    expect($cadence->fresh()->due_at->toDateTimeString())->toBe('2026-10-08 15:00:00');
});
