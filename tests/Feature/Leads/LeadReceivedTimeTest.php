<?php

use App\Actions\Leads\RouteLead;
use App\Actions\Leads\StartWorkingLead;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.display_timezone' => 'America/New_York']);

    $this->travelTo('2026-10-05 18:30:00');
});

test('the received time can be corrected, typed in the company timezone, with a reason', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create(['received_at' => '2026-10-05 17:00:00']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->assertSet('correctedReceivedAt', '2026-10-05T13:00')
        ->set('correctedReceivedAt', '2026-10-04T21:15')
        ->set('receivedTimeReason', 'The voicemail was left the evening before.')
        ->call('correctReceivedTime')
        ->assertHasNoErrors();

    expect($lead->fresh()->received_at->toDateTimeString())->toBe('2026-10-05 01:15:00');
});

test('a correction to the received time is refused without a reason, or in the future', function (string $time, string $reason, string $error) {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['received_at' => '2026-10-05 17:00:00']);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->set('correctedReceivedAt', $time)
        ->set('receivedTimeReason', $reason)
        ->call('correctReceivedTime')
        ->assertHasErrors($error);

    expect($lead->fresh()->received_at->toDateTimeString())->toBe('2026-10-05 17:00:00');
    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'no reason' => ['2026-10-04T21:15', '   ', 'receivedTimeReason'],
    'a future time' => ['2026-10-05T15:00', 'Typed the wrong hour.', 'correctedReceivedAt'],
    'not a time' => ['yesterday', 'Typed the wrong hour.', 'correctedReceivedAt'],
]);

test('saving the same received time changes nothing and records nothing', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['received_at' => '2026-10-05 17:00:42']);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->set('receivedTimeReason', 'Just checking.')
        ->call('correctReceivedTime')
        ->assertHasNoErrors();

    expect($lead->fresh()->received_at->toDateTimeString())->toBe('2026-10-05 17:00:42');
    expect(AuditEvent::query()->count())->toBe(0);
});

test('someone who may not correct the lead cannot correct its received time', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['received_at' => '2026-10-05 17:00:00']);

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $consultant->accessGrants()->delete();
    $consultant->refresh();

    $page->set('correctedReceivedAt', '2026-10-04T21:15')
        ->set('receivedTimeReason', 'Trying anyway.')
        ->call('correctReceivedTime')
        ->assertForbidden();

    expect($lead->fresh()->received_at->toDateTimeString())->toBe('2026-10-05 17:00:00');
});

/*
|--------------------------------------------------------------------------
| A lead is received before anyone acts on it
|--------------------------------------------------------------------------
*/

test('the received time cannot be moved after something staff already did with the lead', function (Closure $lead, string $named) {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    // The first thing staff did with this lead was at 11:00 AM on October 2, New York time.
    $lead = $lead($lakeview, $manager);
    $before = $lead->fresh()->getAttributes();

    $page = Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->set('correctedReceivedAt', '2026-10-02T11:30')
        ->set('receivedTimeReason', 'It actually arrived later that morning.')
        ->call('correctReceivedTime')
        ->assertHasErrors('correctedReceivedAt')
        ->assertSee("The received time cannot be after the lead was {$named} (Oct 2, 2026 11:00 AM EDT).");

    // Refused outright: nothing was moved to make it fit, and nothing was recorded.
    expect($lead->fresh()->getAttributes())->toBe($before);
    expect(AuditEvent::query()->count())->toBe(0);

    // Up to that moment is still allowed, and changes only the received time.
    $page->set('correctedReceivedAt', '2026-10-02T11:00')->call('correctReceivedTime')->assertHasNoErrors();

    $corrected = $lead->fresh();

    expect($corrected->received_at->toDateTimeString())->toBe('2026-10-02 15:00:00');
    expect(array_diff_key($corrected->getAttributes(), ['received_at' => true, 'updated_at' => true]))
        ->toBe(array_diff_key($before, ['received_at' => true, 'updated_at' => true]));
})->with([
    'first routed' => [
        fn (Location $lakeview) => Lead::factory()->at($lakeview)->create([
            'received_at' => '2026-10-01 14:00:00', 'first_routed_at' => '2026-10-02 15:00:00', 'routed_at' => '2026-10-04 09:00:00', 'created_at' => '2026-10-03 09:00:00',
        ]),
        'first routed to a location',
    ],
    'first assigned, even to someone who no longer has it' => [
        function (Location $lakeview, User $manager) {
            $lead = Lead::factory()->at($lakeview)->create([
                'received_at' => '2026-10-01 14:00:00', 'created_at' => '2026-10-03 09:00:00', 'first_routed_at' => '2026-10-03 09:00:00', 'routed_at' => '2026-10-03 09:00:00',
            ]);

            $lead->assignments()->forceCreate(['user_id' => $manager->id, 'assigned_at' => '2026-10-02 15:00:00', 'ended_at' => '2026-10-02 16:00:00', 'end_reason' => 'released']);

            return $lead;
        },
        'first assigned',
    ],
    'first worked' => [
        fn (Location $lakeview) => Lead::factory()->at($lakeview)->working()->create([
            'received_at' => '2026-10-01 14:00:00', 'work_started_at' => '2026-10-02 15:00:00', 'created_at' => '2026-10-03 09:00:00', 'first_routed_at' => '2026-10-03 09:00:00', 'routed_at' => '2026-10-03 09:00:00',
        ]),
        'first worked',
    ],
]);

test('a routed lead\'s received time cannot be corrected to after it was routed', function () {
    $lakeview = Location::factory()->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    // Recorded centrally on October 1, routed on October 3, and it is now October 5.
    $this->travelTo('2026-10-01 14:00:00');
    $lead = Lead::factory()->create(['received_at' => '2026-10-01 13:00:00']);

    $this->travelTo('2026-10-03 14:00:00');
    app(RouteLead::class)->handle($lead, $lakeview, null, $agent);

    $this->travelTo('2026-10-05 18:30:00');

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->set('correctedReceivedAt', '2026-10-04T09:00')
        ->set('receivedTimeReason', 'Mistyped the day.')
        ->call('correctReceivedTime')
        ->assertHasErrors('correctedReceivedAt')
        ->assertSee('The received time cannot be after the lead was first routed to a location');

    $unchanged = $lead->fresh();

    expect($unchanged->received_at->toDateTimeString())->toBe('2026-10-01 13:00:00');
    expect($unchanged->first_routed_at->toDateTimeString())->toBe('2026-10-03 14:00:00');
    expect($unchanged->routed_at->toDateTimeString())->toBe('2026-10-03 14:00:00');
});

test('when the lead\'s record was created does not limit the received time', function (Closure $lead) {
    $lakeview = Location::factory()->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    // The record was created on October 1 at 14:00 UTC, and it is now October 5.
    $this->travelTo('2026-10-01 14:00:00');
    $lead = $lead($lakeview, $agent);
    $createdAt = $lead->fresh()->created_at->toDateTimeString();

    $this->travelTo('2026-10-05 18:30:00');

    // October 2 at 9:00 AM New York time: a day after the record was created.
    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->set('correctedReceivedAt', '2026-10-02T09:00')
        ->set('receivedTimeReason', 'The record was started before the inquiry actually arrived.')
        ->call('correctReceivedTime')
        ->assertHasNoErrors();

    $corrected = $lead->fresh();

    expect($corrected->received_at->toDateTimeString())->toBe('2026-10-02 13:00:00');
    // The record's own timestamp is left exactly as it was.
    expect($corrected->created_at->toDateTimeString())->toBe($createdAt);

    $event = AuditEvent::query()->where('action', AuditAction::LeadReceivedTimeCorrected)->sole();

    expect($event->actor->is($agent))->toBeTrue();
    expect($event->reason)->toBe('The record was started before the inquiry actually arrived.');
    expect($event->after())->toBe(['received_at' => '2026-10-02T13:00:00+00:00']);
})->with([
    'a lead nobody has acted on yet' => [fn () => Lead::factory()->create(['received_at' => '2026-10-01 13:00:00'])],
    'a lead that was routed, assigned and worked later still' => [function (Location $lakeview, User $agent) {
        $lead = Lead::factory()->create(['received_at' => '2026-10-01 13:00:00']);

        test()->travelTo('2026-10-03 14:00:00');

        app(RouteLead::class)->handle($lead, $lakeview, null, $agent);
        app(StartWorkingLead::class)->handle($lead, $agent);

        return $lead;
    }],
]);
