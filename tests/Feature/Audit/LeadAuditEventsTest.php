<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * MySQL does not keep the order fields were stored in, so compare them in a fixed order.
 *
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function recorded(array $values): array
{
    ksort($values);

    return $values;
}

test('capturing a lead records who received it, how, and where it was routed', function () {
    $this->travelTo('2026-10-05 18:30:00');

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId(ProjectSize::LARGE))
        ->set('serviceIds', [(string) serviceNamed('Deck / Fence')->id, (string) serviceNamed('Exterior Painting')->id])
        ->set('primaryServiceId', (string) serviceNamed('Deck / Fence')->id)
        ->set('name', 'Maria Lopez')
        ->set('phone', '555-201-3344')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->set('leadSourceId', LeadSource::query()->where('name', 'Google Business Profile')->sole()->id)
        ->set('locationId', (string) $lakeview->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();
    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadReceived);
    expect($event->actor->is($agent))->toBeTrue();
    expect($event->actor_name)->toBe('Casey Caller');
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect(DB::table('audit_events')->value('subject_type'))->toBe('lead');
    expect(recorded($event->after()))->toBe(recorded([
        'lead_channel' => 'Phone Call',
        'lead_source' => 'Google Business Profile',
        'marketing_source' => 'Unknown / Unattributed',
        'services' => ['Exterior Painting', 'Deck / Fence'],
        'primary_service' => 'Deck / Fence',
        'project_size' => 'Large',
        'postal_code' => null,
        'suggested_location' => null,
        'location' => 'Lakeview',
        'received_at' => '2026-10-05T18:30:00+00:00',
    ]));
});

test('capturing an unrouted lead records no location', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->location_id)->toBeNull();
    expect($event->after()['location'])->toBeNull();
});

test('a lead that is refused records nothing', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->call('save')
        ->assertHasErrors('channelId');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('correcting inquiry details records what each changed field was before and is now', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = Lead::factory()->at($lakeview)->create([
        'inquiry_name' => 'Maria Lopes',
        'inquiry_phone' => '5552013344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_address' => '14 Oak St',
    ]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->set('email', 'maria@example.com')
        ->set('addressLine1', '')
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadInquiryCorrected);
    expect($event->actor->is($consultant))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();

    // The first-recorded values stay recoverable; the unchanged phone is not repeated.
    expect(recorded($event->before()))->toBe(recorded(['name' => 'Maria Lopes', 'email' => null, 'address' => '14 Oak St']));
    expect(recorded($event->after()))->toBe(recorded(['name' => 'Maria Lopez', 'email' => 'maria@example.com', 'address' => null]));
});

test('changing attribution records the options before and after, separately from corrections', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('marketingSourceId', MarketingSource::query()->where('name', 'Yard Sign')->sole()->id)
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadAttributionChanged);
    expect($event->before())->toBe(['marketing_source' => 'Unknown / Unattributed']);
    expect($event->after())->toBe(['marketing_source' => 'Yard Sign']);
});

test('a correction and an attribution change made together are recorded as two events', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopes']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->set('channelId', LeadChannel::query()->where('name', 'Web Form')->sole()->id)
        ->call('update')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::LeadInquiryCorrected, AuditAction::LeadAttributionChanged]);
});

test('saving a lead without changing anything records nothing', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopez']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('name', '  Maria Lopez  ')
        ->call('update')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('starting work records the change of status once', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('startWorking')
        ->call('startWorking')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadWorkStarted);
    expect($event->actor->is($consultant))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before())->toBe(['status' => 'new']);
    expect($event->after())->toBe(['status' => 'working']);
});

test('changing the services records the services and the main one before and after', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    // Starts with one service, Interior Painting, which is therefore the main one.
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('serviceIds', [(string) serviceNamed('Interior Painting')->id, (string) serviceNamed('Cabinet Painting')->id])
        ->set('primaryServiceId', (string) serviceNamed('Cabinet Painting')->id)
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadServicesChanged);
    expect($event->actor->is($consultant))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect(recorded($event->before()))->toBe(recorded(['services' => ['Interior Painting'], 'primary_service' => 'Interior Painting']));
    expect(recorded($event->after()))->toBe(recorded(['services' => ['Interior Painting', 'Cabinet Painting'], 'primary_service' => 'Cabinet Painting']));
});

test('changing only which service is the main one is recorded', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $lead->services()->sync([
        serviceNamed('Interior Painting')->id => ['is_primary' => true],
        serviceNamed('Cabinet Painting')->id => ['is_primary' => false],
    ]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('primaryServiceId', (string) serviceNamed('Cabinet Painting')->id)
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadServicesChanged);
    expect($event->before()['primary_service'])->toBe('Interior Painting');
    expect($event->after()['primary_service'])->toBe('Cabinet Painting');
});

test('reclassifying the project size records the size before and after', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('projectSizeId', projectSizeId(ProjectSize::LARGE))
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LeadProjectSizeChanged);
    expect($event->before())->toBe(['project_size' => 'Small']);
    expect($event->after())->toBe(['project_size' => 'Large']);
});

test('changing the value a project size covers records the range before and after', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.project-sizes')
        ->set('mediumStartsAt', '2000')
        ->set('largeStartsAt', '12000')
        ->call('save')
        ->assertHasNoErrors();

    // Small did not change, so only Medium and Large are recorded.
    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events->pluck('action')->unique()->all())->toBe([AuditAction::ProjectSizeRangeChanged]);
    expect($events->map(fn (AuditEvent $event) => $event->subject->key)->all())->toBe(['medium', 'large']);
    expect(recorded($events[0]->before()))->toBe(recorded(['min_value' => 2000, 'max_value' => 9999]));
    expect(recorded($events[0]->after()))->toBe(recorded(['min_value' => 2000, 'max_value' => 11999]));
    expect(recorded($events[1]->before()))->toBe(recorded(['min_value' => 10000, 'max_value' => null]));
    expect(recorded($events[1]->after()))->toBe(recorded(['min_value' => 12000, 'max_value' => null]));
    expect($events[0]->actor->is($owner))->toBeTrue();
});

test('saving project sizes without changing them records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)->test('pages::settings.project-sizes')->call('save')->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});
