<?php

use App\Actions\Leads\AssignLead;
use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\ReactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Enums\AssignmentEndReason;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Who may assign, and to whom
|--------------------------------------------------------------------------
*/

test('people who hand out leads can assign one to someone who works leads there', function (Closure $assigner) {
    $this->freezeSecond();

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $lead = Lead::factory()->at($lakeview)->create();

    $assigner = $assigner($lakeview);

    Livewire::actingAs($assigner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Nobody yet')
        ->call('openAssign')
        ->set('assigneeId', $consultant->id)
        ->call('assign')
        ->assertHasNoErrors()
        ->assertDontSee('Nobody yet')
        ->assertSee('Reassign');

    $assigned = $lead->fresh();

    expect($assigned->assignee->is($consultant))->toBeTrue();
    expect($assigned->assigned_at->equalTo(now()))->toBeTrue();
    expect($assigned->status)->toBe(LeadStatus::New);

    $assignment = LeadAssignment::query()->sole();

    expect($assignment->lead->is($lead))->toBeTrue();
    expect($assignment->user->is($consultant))->toBeTrue();
    expect($assignment->assignedBy->is($assigner))->toBeTrue();
    expect($assignment->location->is($lakeview))->toBeTrue();
    expect($assignment->isOpen())->toBeTrue();
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
]);

test('only people who can already work leads at the location are offered', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(['name' => 'Mona Manager']);

    User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create(['name' => 'Nora Northside']);
    User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Fiona Finance']);
    User::factory()->withRoleAt(Role::ProductionManager, $lakeview)->create(['name' => 'Pat Production']);
    User::factory()->inactive()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Ina Inactive']);
    User::factory()->invited()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Ivy Invited']);
    User::factory()->create(['name' => 'Noah Noaccess']);

    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->assertSee(['Sam Sales', 'Olive Owner', 'Mona Manager'])
        ->assertDontSee(['Nora Northside', 'Fiona Finance', 'Pat Production', 'Ina Inactive', 'Ivy Invited', 'Noah Noaccess']);
});

test('someone who cannot work leads there cannot be assigned, whatever the browser sends', function (Closure $outsider) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $outsider = $outsider($lakeview, $northside);
    $lead = Lead::factory()->at($lakeview)->create();
    $couldOpenIt = $outsider->can('view', $lead);

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->set('assigneeId', $outsider->id)
        ->call('assign')
        ->assertHasErrors('assigneeId');

    expect(fn () => app(AssignLead::class)->handle($lead, $outsider, $owner))->toThrow(ValidationException::class);

    expect($lead->fresh()->isAssigned())->toBeFalse();
    expect(LeadAssignment::query()->count())->toBe(0);
    // Trying to assign them changed nothing about what they may open.
    expect($outsider->fresh()->can('view', $lead))->toBe($couldOpenIt);
})->with([
    'works leads at another location' => [fn (Location $lakeview, Location $northside) => User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create()],
    'has a role here that does not work leads' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::ProductionManager, $lakeview)->create()],
    'is deactivated' => [fn (Location $lakeview) => User::factory()->inactive()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
    'has not accepted their invitation' => [fn (Location $lakeview) => User::factory()->invited()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('an unrouted lead can only be assigned to company-wide lead staff', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(['name' => 'Mona Manager']);
    $lead = Lead::factory()->create();

    $page = Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->assertSee('Casey Caller')
        ->assertDontSee('Mona Manager')
        ->set('assigneeId', $manager->id)
        ->call('assign')
        ->assertHasErrors('assigneeId');

    $page->set('assigneeId', $agent->id)->call('assign')->assertHasNoErrors();

    expect($lead->fresh()->assignee->is($agent))->toBeTrue();
    expect(LeadAssignment::query()->sole()->location_id)->toBeNull();
});

test('a sales consultant cannot assign, reassign, release or pick up a lead', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $unassigned = Lead::factory()->at($lakeview)->create();
    $own = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    // An unassigned lead: no way to take it, by assigning or by starting work.
    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $unassigned])
        ->assertDontSeeHtml('data-test="assign-lead"')
        ->assertDontSee('Start working')
        ->call('openAssign')
        ->assertForbidden();

    foreach (['assign', 'startWorking'] as $action) {
        Livewire::actingAs($consultant)
            ->test('pages::leads.show', ['lead' => $unassigned])
            ->set('assigneeId', $consultant->id)
            ->call($action)
            ->assertForbidden();
    }

    // Their own lead: they work it, but cannot hand it on or let it go.
    foreach (['assign', 'unassign'] as $action) {
        Livewire::actingAs($consultant)
            ->test('pages::leads.show', ['lead' => $own])
            ->set('assigneeId', $colleague->id)
            ->call($action)
            ->assertForbidden();
    }

    expect($unassigned->fresh()->isAssigned())->toBeFalse();
    expect($unassigned->fresh()->status)->toBe(LeadStatus::New);
    expect($own->fresh()->assignee->is($consultant))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reassigning and releasing
|--------------------------------------------------------------------------
*/

test('reassigning closes the earlier assignment and opens a new one', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $first = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $second = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Rep']);

    $lead = Lead::factory()->at($lakeview)->assignedTo($first)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->assertSet('assigneeId', $first->id)
        ->set('assigneeId', $second->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect($lead->fresh()->assignee->is($second))->toBeTrue();

    [$earlier, $current] = LeadAssignment::query()->orderBy('id')->get()->all();

    expect($earlier->user->is($first))->toBeTrue();
    expect($earlier->isOpen())->toBeFalse();
    expect($earlier->end_reason)->toBe(AssignmentEndReason::Reassigned);
    expect($current->user->is($second))->toBeTrue();
    expect($current->isOpen())->toBeTrue();
});

test('assigning the person who already has the lead changes nothing', function () {
    $lakeview = Location::factory()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->call('assign')
        ->assertHasNoErrors();

    expect(LeadAssignment::query()->sole()->isOpen())->toBeTrue();
});

test('someone who hands out leads can leave a lead unassigned', function () {
    $lakeview = Location::factory()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->working()->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->call('unassign')
        ->assertHasNoErrors()
        ->assertSee('Nobody yet');

    $released = $lead->fresh();

    expect($released->isAssigned())->toBeFalse();
    expect($released->assigned_at)->toBeNull();
    expect($released->status)->toBe(LeadStatus::Working);

    $assignment = LeadAssignment::query()->sole();

    expect($assignment->isOpen())->toBeFalse();
    expect($assignment->end_reason)->toBe(AssignmentEndReason::Released);
});

test('an assignment dialog that is out of date saves nothing', function (string $action) {
    $lakeview = Location::factory()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $first = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $second = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($manager)->test('pages::leads.show', ['lead' => $lead])->call('openAssign');

    // Someone else assigns it while the dialog is open.
    app(AssignLead::class)->handle($lead->fresh(), $first, $manager);

    $page->set('assigneeId', $second->id)->call($action)->assertHasErrors('stale');

    expect($lead->fresh()->assignee->is($first))->toBeTrue();
    expect(LeadAssignment::query()->sole()->isOpen())->toBeTrue();
})->with(['assign', 'unassign']);

test('the database refuses a second open assignment for one lead, and never forgets one', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    expect(fn () => LeadAssignment::factory()->create(['lead_id' => $lead->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    // A closed one beside the open one is ordinary history.
    LeadAssignment::factory()->create(['lead_id' => $lead->id, 'ended_at' => now(), 'end_reason' => AssignmentEndReason::Reassigned]);

    expect(fn () => LeadAssignment::query()->firstOrFail()->delete())->toThrow(LogicException::class);
    expect(LeadAssignment::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Starting work
|--------------------------------------------------------------------------
*/

test('starting work on an unassigned lead makes it yours, if you hand out leads', function (Closure $user) {
    $this->freezeSecond();

    $lakeview = Location::factory()->create();
    $user = $user($lakeview);
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($user)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Start working (assigns it to you)')
        ->call('startWorking')
        ->assertHasNoErrors();

    $started = $lead->fresh();

    expect($started->status)->toBe(LeadStatus::Working);
    expect($started->assignee->is($user))->toBeTrue();
    expect($started->work_started_at->equalTo(now()))->toBeTrue();
    expect(LeadAssignment::query()->sole()->assignedBy->is($user))->toBeTrue();
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
]);

test('nobody starts working a lead that is assigned to someone else', function (Closure $user) {
    $lakeview = Location::factory()->create();

    $holder = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($holder)->create();

    Livewire::actingAs($user($lakeview))
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertDontSee('Start working')
        ->call('startWorking')
        ->assertForbidden();

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
    expect($lead->fresh()->assignee->is($holder))->toBeTrue();
})->with([
    'another sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
    'a location manager, who must reassign it first' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
]);

/*
|--------------------------------------------------------------------------
| A change of location is a handoff
|--------------------------------------------------------------------------
*/

test('every change of location clears the assignee, even one who also works at the destination', function (Closure $move) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create();

    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->working()->create();

    $move(Livewire::actingAs($owner)->test('pages::leads.show', ['lead' => $lead]), $northside)->assertHasNoErrors();

    $moved = $lead->fresh();

    expect($moved->isAssigned())->toBeFalse();
    expect($moved->assigned_at)->toBeNull();
    expect($moved->status)->toBe(LeadStatus::Working);

    $assignment = LeadAssignment::query()->sole();

    expect($assignment->isOpen())->toBeFalse();
    expect($assignment->end_reason)->toBe(AssignmentEndReason::LocationChanged);
    expect($assignment->location->is($lakeview))->toBeTrue();
})->with([
    'moved to another location' => [fn ($page, Location $northside) => $page->call('openRouting')->set('destinationId', $northside->id)->set('routingReason', 'North side.')->call('route')],
    'returned to central' => [fn ($page) => $page->call('openReturn')->set('returnReason', 'Wrong branch.')->call('returnToCentral')],
]);

test('routing an unrouted lead clears the central person it was assigned to', function () {
    $lakeview = Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->assignedTo($agent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->set('destinationId', $lakeview->id)
        ->call('route')
        ->assertHasNoErrors();

    expect($lead->fresh()->isAssigned())->toBeFalse();
    expect(LeadAssignment::query()->sole()->end_reason)->toBe(AssignmentEndReason::LocationChanged);
});

/*
|--------------------------------------------------------------------------
| Losing access
|--------------------------------------------------------------------------
*/

test('removing someone\'s access unassigns the leads they can no longer work, and only those', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create();

    $atLakeview = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();
    $atNorthside = Lead::factory()->at($northside)->assignedTo($consultant)->create();

    $this->actingAs($owner);

    app(RevokeAccess::class)->handle($consultant->accessGrants()->where('location_id', $lakeview->id)->sole());

    expect($atLakeview->fresh()->isAssigned())->toBeFalse();
    expect($atLakeview->assignments()->sole()->end_reason)->toBe(AssignmentEndReason::AccessRemoved);
    expect($atNorthside->fresh()->assignee->is($consultant))->toBeTrue();
    expect($atNorthside->assignments()->sole()->isOpen())->toBeTrue();
});

test('a lead stays with someone who can still work it through another grant', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::LocationManager, $lakeview)
        ->create();

    $lead = Lead::factory()->at($lakeview)->assignedTo($staff)->create();

    $this->actingAs($owner);

    app(RevokeAccess::class)->handle($staff->accessGrants()->where('role', Role::SalesConsultant)->sole());

    expect($lead->fresh()->assignee->is($staff))->toBeTrue();
});

test('deactivating someone unassigns their leads, and reactivating does not give them back', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->working()->create();

    $this->actingAs($owner);

    app(DeactivateStaffMember::class)->handle($consultant);

    expect($lead->fresh()->isAssigned())->toBeFalse();
    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
    expect(LeadAssignment::query()->sole()->end_reason)->toBe(AssignmentEndReason::AccessRemoved);

    app(ReactivateStaffMember::class)->handle($consultant->fresh());

    expect($lead->fresh()->isAssigned())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Assignment when a Lead is recorded
|--------------------------------------------------------------------------
*/

test('a lead a sales consultant records at their own location is assigned to them', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Rita Referral')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->assignee->is($consultant))->toBeTrue();
    expect($lead->status)->toBe(LeadStatus::New);
    expect($lead->first_routed_at)->not->toBeNull();

    $assignment = LeadAssignment::query()->sole();

    expect($assignment->user->is($consultant))->toBeTrue();
    expect($assignment->assignedBy->is($consultant))->toBeTrue();
    expect($assignment->location->is($lakeview))->toBeTrue();
});

test('a lead recorded by anyone else is left unassigned', function (Closure $user) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    Livewire::actingAs($user($lakeview))
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->set('locationId', (string) $lakeview->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->location->is($lakeview))->toBeTrue();
    expect($lead->isAssigned())->toBeFalse();
    expect(LeadAssignment::query()->count())->toBe(0);
})->with([
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
    'company-wide call center agent' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
]);

/*
|--------------------------------------------------------------------------
| Workload shown when assigning
|--------------------------------------------------------------------------
*/

test('the open-lead count beside each person counts only leads the assigner may see', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create(['name' => 'Sam Sales']);

    Lead::factory()->count(2)->at($lakeview)->assignedTo($consultant)->create();
    Lead::factory()->count(3)->at($northside)->assignedTo($consultant)->create();

    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->assertSeeInOrder(['Sam Sales', '2 open leads'])
        ->assertDontSee('5 open leads');

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->assertSeeInOrder(['Sam Sales', '5 open leads']);
});
