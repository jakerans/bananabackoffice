<?php

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
