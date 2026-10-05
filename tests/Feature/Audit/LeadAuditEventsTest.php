<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
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
        'location' => 'Lakeview',
        'received_at' => '2026-10-05T18:30:00+00:00',
    ]));
});

test('capturing an unrouted lead records no location', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
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
        ->set('address', '')
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
    $lead = Lead::factory()->at($lakeview)->create();

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
