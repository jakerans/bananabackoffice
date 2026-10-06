<?php

use App\Actions\Staff\RevokeAccess;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use App\Models\User;
use Livewire\Livewire;

/**
 * The one audit event of a kind, for looking at what it recorded.
 */
function auditEventOf(AuditAction $action): AuditEvent
{
    return AuditEvent::query()->where('action', $action)->sole();
}

test('routing a lead records where it went, where its address suggested, and who routed it', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $lead = Lead::factory()->create(['inquiry_address' => '14 Oak St 30301', 'address_postal_code' => '30301']);

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->set('destinationId', $northside->id)
        ->set('routingReason', 'The customer asked for the Northside crew.')
        ->call('route')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadRouted);
    expect($event->actor->is($agent))->toBeTrue();
    expect($event->actor_name)->toBe('Casey Caller');
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($northside))->toBeTrue();
    expect($event->before())->toBe(['location' => null]);
    expect($event->after()['location'])->toBe('Northside');
    expect($event->after()['suggested_location'])->toBe('Lakeview');
    expect($event->reason)->toBe('The customer asked for the Northside crew.');
});

test('moving an assigned lead records the handoff and the move, each with its cause', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->set('destinationId', $northside->id)
        ->set('routingReason', 'The property is on the north side.')
        ->call('route')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(2);

    $unassigned = auditEventOf(AuditAction::LeadUnassigned);

    expect($unassigned->actor->is($owner))->toBeTrue();
    expect($unassigned->location->is($lakeview))->toBeTrue();
    expect($unassigned->before())->toBe(['assignee' => 'Sam Sales']);
    expect($unassigned->after()['assignee'])->toBeNull();
    expect($unassigned->after()['cause'])->toBe('location_changed');

    $moved = auditEventOf(AuditAction::LeadTransferred);

    expect($moved->location->is($northside))->toBeTrue();
    expect($moved->before())->toBe(['location' => 'Lakeview']);
    expect($moved->after()['location'])->toBe('Northside');
    expect($moved->reason)->toBe('The property is on the north side.');
});

test('returning a lead to central is recorded against the location it left, with the reason', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReturn')
        ->set('returnReason', 'Outside the area we serve.')
        ->call('returnToCentral');

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadReturnedToCentral);
    expect($event->actor->is($manager))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before())->toBe(['location' => 'Lakeview']);
    expect($event->after())->toBe(['location' => null]);
    expect($event->reason)->toBe('Outside the area we serve.');
});

test('assigning, reassigning and releasing a lead are each recorded', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $first = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $second = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Rep']);
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')->set('assigneeId', $first->id)->call('assign')
        ->call('openAssign')->set('assigneeId', $second->id)->call('assign')
        ->call('openAssign')->call('unassign')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(3);

    $assigned = auditEventOf(AuditAction::LeadAssigned);

    expect($assigned->actor->is($manager))->toBeTrue();
    expect($assigned->subject->is($lead))->toBeTrue();
    expect($assigned->location->is($lakeview))->toBeTrue();
    expect($assigned->before())->toBe(['assignee' => null]);
    expect($assigned->after())->toBe(['assignee' => 'Sam Sales']);

    $reassigned = auditEventOf(AuditAction::LeadReassigned);

    expect($reassigned->before())->toBe(['assignee' => 'Sam Sales']);
    expect($reassigned->after())->toBe(['assignee' => 'Roseanne Rep']);

    $released = auditEventOf(AuditAction::LeadUnassigned);

    expect($released->before())->toBe(['assignee' => 'Roseanne Rep']);
    expect($released->after()['cause'])->toBe('released');
});

test('starting work on an unassigned lead records the assignment and the start of work', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(['name' => 'Mona Manager']);
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('startWorking')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(2);
    expect(auditEventOf(AuditAction::LeadAssigned)->after())->toBe(['assignee' => 'Mona Manager']);
    expect(auditEventOf(AuditAction::LeadWorkStarted)->actor->is($manager))->toBeTrue();
});

test('a lead a sales consultant records for themselves is recorded as an assignment like any other', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Rita Referral')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    $event = auditEventOf(AuditAction::LeadAssigned);

    expect($event->actor->is($consultant))->toBeTrue();
    expect($event->subject->is(Lead::query()->sole()))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->after())->toBe(['assignee' => 'Sam Sales']);
});

test('a lead unassigned because its holder lost access is recorded with that cause', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    $this->actingAs($owner);

    app(RevokeAccess::class)->handle($consultant->accessGrants()->sole());

    $event = auditEventOf(AuditAction::LeadUnassigned);

    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->before())->toBe(['assignee' => 'Sam Sales']);
    expect($event->after()['cause'])->toBe('access_removed');
});

test('correcting the received time records the time before and after, who did it and why', function () {
    $this->travelTo('2026-10-05 18:30:00');

    config(['app.display_timezone' => 'America/New_York']);

    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['received_at' => '2026-10-05 17:00:00']);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReceivedTime')
        ->set('correctedReceivedAt', '2026-10-04T21:15')
        ->set('receivedTimeReason', 'The voicemail was left the evening before.')
        ->call('correctReceivedTime')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadReceivedTimeCorrected);
    expect($event->actor->is($manager))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before())->toBe(['received_at' => '2026-10-05T17:00:00+00:00']);
    expect($event->after())->toBe(['received_at' => '2026-10-05T01:15:00+00:00']);
    expect($event->reason)->toBe('The voicemail was left the evening before.');
});

test('a refused routing or assignment records nothing', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $outsider = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')->set('destinationId', $northside->id)->call('route')
        ->call('openReturn')->call('returnToCentral')
        ->call('openAssign')->set('assigneeId', $outsider->id)->call('assign')
        ->call('openAssign')->call('unassign');

    expect(AuditEvent::query()->count())->toBe(0);
});
