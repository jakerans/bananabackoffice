<?php

use App\Actions\Leads\QualifyLead;
use App\Actions\Leads\ReturnLeadToWorking;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Database\Factories\LeadFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A lead meeting the existing qualification requirements: a location, someone assigned, a
 * customer with a contact and a property, a service and a project size.
 */
function leadReadyToQualify(Location $location, User $assignee): LeadFactory
{
    $account = Account::factory()->withPrimaryContact()->create();
    $property = Property::factory()->of($account)->create();

    return Lead::factory()->at($location)->working()->assignedTo($assignee)->from($account, $account->primaryContact(), $property);
}

/*
|--------------------------------------------------------------------------
| Qualifying
|--------------------------------------------------------------------------
*/

test('a working lead meeting the qualification requirements can be qualified by the person it is assigned to', function () {
    $this->freezeSecond();

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openQualify')
        ->assertSee('It does not mean booked, Lead Issued, demoed, sold or Project-created.')
        ->assertSeeHtml('data-test="qualification-ready"')
        ->call('qualify')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="lead-qualified"');

    $qualified = $lead->fresh();

    expect($qualified->status)->toBe(LeadStatus::Qualified);
    expect($qualified->first_qualified_at->equalTo(now()))->toBeTrue();
    expect($qualified->status_changed_at->equalTo(now()))->toBeTrue();
    // Qualifying is not a handoff: it stays with whoever has it.
    expect($qualified->assignee->is($consultant))->toBeTrue();

    $entry = LeadStatusChange::query()->sole();

    expect($entry->lead->is($lead))->toBeTrue();
    expect($entry->event)->toBe(LeadStatusEvent::Qualified);
    expect($entry->from_status)->toBe(LeadStatus::Working);
    expect($entry->to_status)->toBe(LeadStatus::Qualified);
    expect($entry->changedBy->is($consultant))->toBeTrue();
    expect($entry->location->is($lakeview))->toBeTrue();
    expect($entry->assignee->is($consultant))->toBeTrue();
    expect($entry->created_at->equalTo(now()))->toBeTrue();
});

test('a lead cannot be qualified while a qualification requirement is missing', function (Closure $without, string $missing) {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = $without(leadReadyToQualify($lakeview, $consultant))->create();

    expect(fn () => app(QualifyLead::class)->handle($lead->fresh(), $manager))
        ->toThrow(ValidationException::class, $missing);

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
    expect($lead->fresh()->first_qualified_at)->toBeNull();
    expect(LeadStatusChange::query()->count())->toBe(0);
})->with([
    'no location' => [fn (LeadFactory $lead) => $lead->state(['location_id' => null]), 'a location'],
    'nobody assigned' => [fn (LeadFactory $lead) => $lead->state(['assigned_user_id' => null]), 'someone assigned to it'],
    'no customer' => [fn (LeadFactory $lead) => $lead->state(['account_id' => null, 'contact_id' => null, 'property_id' => null]), 'a customer; a contact; a property'],
    'an archived customer' => [fn (LeadFactory $lead) => $lead->afterCreating(fn (Lead $lead) => $lead->account->forceFill(['archived_at' => now()])->save()), 'a customer'],
    'no contact' => [fn (LeadFactory $lead) => $lead->state(['contact_id' => null]), 'a contact'],
    'no property' => [fn (LeadFactory $lead) => $lead->state(['property_id' => null]), 'a property'],
    'an archived property' => [fn (LeadFactory $lead) => $lead->afterCreating(fn (Lead $lead) => $lead->property->forceFill(['archived_at' => now()])->save()), 'a property'],
    'no service' => [fn (LeadFactory $lead) => $lead->withoutServices(), 'a service, with one marked as the main one'],
    'no project size' => [fn (LeadFactory $lead) => $lead->unclassified(), 'a project size'],
]);

test('the qualify dialog lists what is missing and offers nothing to submit', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->unclassified()->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openQualify')
        ->assertSeeInOrder(['It still needs:', 'a customer', 'a contact', 'a property', 'a project size'])
        // With the way to fix the customer side of it.
        ->assertSeeHtml('data-test="qualification-link-customer"')
        ->assertDontSeeHtml('data-test="qualify-submit"')
        // Submitting anyway changes nothing.
        ->call('qualify')
        ->assertHasErrors('qualification');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('only a lead that is being worked can be qualified', function (Closure $state) {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = $state(leadReadyToQualify($lakeview, $consultant))->create();
    $status = $lead->status;

    expect(fn () => app(QualifyLead::class)->handle($lead, $manager))
        ->toThrow(ValidationException::class, 'Only a lead that is being worked can be qualified.');

    expect($lead->fresh()->status)->toBe($status);
})->with([
    'new' => [fn (LeadFactory $lead) => $lead->state(['status' => LeadStatus::New])],
    'nurture' => [fn (LeadFactory $lead) => $lead->nurture()],
    'already qualified' => [fn (LeadFactory $lead) => $lead->qualified()],
    'disqualified' => [fn (LeadFactory $lead) => $lead->disqualified()],
]);

/*
|--------------------------------------------------------------------------
| Who may move a lead through its lifecycle
|--------------------------------------------------------------------------
*/

test('the assignee and the people who hand out leads there can change a lead\'s status', function (Closure $actor) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->create();

    Livewire::actingAs($actor($lakeview, $consultant))
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSeeHtml('data-test="qualify-lead"')
        ->assertSeeHtml('data-test="disqualify-lead"')
        ->call('openQualify')
        ->call('qualify')
        ->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
})->with([
    'the assignee' => [fn (Location $lakeview, User $consultant) => $consultant],
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
]);

test('a sales consultant cannot change the status of a colleague\'s lead', function (string $action) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->create();

    Livewire::actingAs($colleague)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertOk()
        ->assertDontSeeHtml('data-test="qualify-lead"')
        ->assertDontSeeHtml('data-test="nurture-lead"')
        ->assertDontSeeHtml('data-test="disqualify-lead"')
        ->call($action)
        ->assertForbidden();

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
})->with(['openQualify', 'qualify', 'openNurture', 'saveNurture', 'resumeWorking', 'openDisqualify', 'disqualify']);

test('a manager of another location cannot change the status of a lead they cannot see', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, $northside)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->create();

    Livewire::actingAs($elsewhere)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertForbidden();

    expect($elsewhere->can('changeStatus', $lead))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Returning a qualified lead to working
|--------------------------------------------------------------------------
*/

test('a qualified lead can be returned to working, and stays with whoever has it', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->qualified()->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('resumeWorking')
        ->assertHasNoErrors();

    $returned = $lead->fresh();

    expect($returned->status)->toBe(LeadStatus::Working);
    expect($returned->assignee->is($consultant))->toBeTrue();
    // It still counts as having been qualified once.
    expect($returned->first_qualified_at)->not->toBeNull();

    $entry = LeadStatusChange::query()->sole();

    expect($entry->event)->toBe(LeadStatusEvent::ReturnedToWorking);
    expect($entry->from_status)->toBe(LeadStatus::Qualified);
    expect($entry->to_status)->toBe(LeadStatus::Working);
});

test('the first time a lead was qualified is kept when it is qualified again', function () {
    $this->travelTo('2026-10-06 15:00:00');

    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->create();

    app(QualifyLead::class)->handle($lead, $consultant);

    $this->travelTo('2026-10-09 15:00:00');

    app(ReturnLeadToWorking::class)->handle($lead, $consultant);
    app(QualifyLead::class)->handle($lead, $consultant);

    expect($lead->fresh()->first_qualified_at->toDateTimeString())->toBe('2026-10-06 15:00:00');
    expect($lead->fresh()->status_changed_at->toDateTimeString())->toBe('2026-10-09 15:00:00');
    expect(LeadStatusChange::query()->where('event', LeadStatusEvent::Qualified)->count())->toBe(2);
});

test('a lead that is new, being worked or disqualified cannot be returned to working', function (Closure $state) {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = $state(Lead::factory()->at($lakeview))->create();
    $status = $lead->status;

    expect(fn () => app(ReturnLeadToWorking::class)->handle($lead, $manager))
        ->toThrow(ValidationException::class, 'Only a lead with a deferred follow-up, or a qualified one, can be returned to working.');

    expect($lead->fresh()->status)->toBe($status);
})->with([
    'new' => [fn (LeadFactory $lead) => $lead],
    'working' => [fn (LeadFactory $lead) => $lead->working()],
    'disqualified' => [fn (LeadFactory $lead) => $lead->disqualified()],
]);

test('a dialog opened before someone else changed the lead saves nothing', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = leadReadyToQualify($lakeview, $consultant)->create();

    $page = Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openNurture')
        ->set('followUpOn', now()->addWeek()->toDateString())
        ->set('followUpNote', 'Call back after the roof is done.');

    // Meanwhile a manager qualifies it.
    app(QualifyLead::class)->handle($lead->fresh(), $manager);

    $page->call('saveNurture')->assertHasErrors('stale');

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect($lead->fresh()->follow_up_on)->toBeNull();
});
