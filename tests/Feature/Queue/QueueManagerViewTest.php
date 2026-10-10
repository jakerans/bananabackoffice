<?php

use App\Actions\ContactCenter\SetQueueManagerView;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Support\Queue\DueQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The manager-view switch (decision 85). A Call Center Agent may assign Leads,
 * but has the manager part of the call queue only when Owner or Leadership
 * switched it on for that one person: the summary, the timer warning, the
 * "Cadence finished, no contact" list and Put back in queue on the Call Queue
 * page, and the Call queue panel on a Lead. Every other role that may assign
 * is unaffected.
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
});

function agentAt(Location $location, bool $managerView = false, string $name = 'Avery Agent'): User
{
    return User::factory()->withRoleAt(Role::CallCenterAgent, $location)->create(['name' => $name, 'queue_manager_view' => $managerView]);
}

function finishedLead(Location $location, string $name): Lead
{
    return dueLead($location, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()], ['inquiry_name' => $name]);
}

/*
|--------------------------------------------------------------------------
| The rule
|--------------------------------------------------------------------------
*/

test('who has the manager part of the queue for a lead', function (Closure $person, bool $allowed) {
    $lead = finishedLead($this->lakeview, 'Finished Person');

    $user = $person($this);

    expect($user->can('manageQueue', $lead))->toBe($allowed);
    expect($user->can('returnToQueue', $lead))->toBe($allowed);
})->with([
    'an owner' => [fn ($test) => $test->owner, true],
    'leadership' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'the location manager there' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, $test->lakeview)->create(), true],
    'an agent there, switched off' => [fn ($test) => agentAt($test->lakeview), false],
    'an agent there, switched on' => [fn ($test) => agentAt($test->lakeview, managerView: true), true],
    'a company-wide agent, switched off' => [fn ($test) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), false],
    'a company-wide agent, switched on' => [fn ($test) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['queue_manager_view' => true]), true],
    'an agent at another location, switched on' => [fn ($test) => agentAt($test->harbor, managerView: true), false],
    'an agent who is also the location manager there, switched off' => [fn ($test) => User::factory()->withRoleAt(Role::CallCenterAgent, $test->lakeview)->withRoleAt(Role::LocationManager, $test->lakeview)->create(), true],
    'an agent there who manages another location, switched off' => [fn ($test) => User::factory()->withRoleAt(Role::CallCenterAgent, $test->lakeview)->withRoleAt(Role::LocationManager, $test->harbor)->create(), false],
    'a sales consultant with the switch somehow on' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->create(['queue_manager_view' => true]), false],
    'a deactivated agent, switched on' => [fn ($test) => User::factory()->withRoleAt(Role::CallCenterAgent, $test->lakeview)->inactive()->create(['queue_manager_view' => true]), false],
]);

test('the manager part covers only the locations the person has it for', function () {
    $queue = app(DueQueue::class);

    expect($queue->managerScope(agentAt($this->lakeview)))->toBe(['locations' => [], 'unrouted' => false, 'anywhere' => false]);
    expect($queue->managerScope(agentAt($this->lakeview, managerView: true)))->toBe(['locations' => [$this->lakeview->id], 'unrouted' => false, 'anywhere' => true]);

    // An agent everywhere who also manages Harbor: without the switch, only Harbor and no unrouted Leads.
    $mixed = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->withRoleAt(Role::LocationManager, $this->harbor)->create();

    expect($queue->managerScope($mixed))->toBe(['locations' => [$this->harbor->id], 'unrouted' => false, 'anywhere' => true]);

    $mixed->forceFill(['queue_manager_view' => true])->save();
    $scope = $queue->managerScope($mixed);

    expect(collect($scope['locations'])->sort()->values()->all())->toBe(collect([$this->lakeview->id, $this->harbor->id])->sort()->values()->all());
    expect($scope['unrouted'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Call Queue page and the Lead page
|--------------------------------------------------------------------------
*/

test('an agent works the queue without the manager part until it is switched on', function () {
    $agent = agentAt($this->lakeview);
    $due = dueLead($this->lakeview, attributes: ['inquiry_name' => 'Due Person']);
    $finished = finishedLead($this->lakeview, 'Finished Person');

    // No timer has ever run, so anyone with the manager part is warned about it.
    SchedulerHeartbeat::query()->delete();

    $page = Livewire::actingAs($agent)->test('pages::marketing.call-queue');

    // The setter's own part is all there.
    $page->assertOk()->assertSee('1 lead due now')->assertSee('Next lead');

    // None of the manager part is.
    $page->assertDontSeeHtml('data-test="summary-due"')
        ->assertDontSeeHtml('data-test="finished-list"')
        ->assertDontSee('The queue timer has not run lately')
        ->assertDontSee('Finished Person')
        ->assertDontSee('Put back in queue');

    $page->call('openPutBack', $finished->id)->assertForbidden();

    // They can still be given the Lead and work it.
    Livewire::actingAs($agent)->test('pages::marketing.call-queue')->call('nextLead')->assertSee('Due Person');

    $agent->forceFill(['queue_manager_view' => true])->save();

    Livewire::actingAs($agent)->test('pages::marketing.call-queue')
        ->assertSeeHtml('data-test="summary-due"')
        ->assertSeeHtml('data-test="finished-list"')
        ->assertSee('The queue timer has not run lately')
        ->assertSee('Finished Person')
        ->assertSee('Put back in queue')
        ->call('openPutBack', $finished->id)
        ->set('putBackNote', 'Trying a second round')
        ->call('putBack')
        ->assertHasNoErrors();

    expect(LeadCadence::query()->where('lead_id', $finished->id)->value('state'))->toBe(CadenceState::InQueue);
    expect($due->fresh()->status)->toBe($due->status);
});

test('switching it off while a put-back dialog is open refuses the save', function () {
    $agent = agentAt($this->lakeview, managerView: true);
    $finished = finishedLead($this->lakeview, 'Finished Person');

    $page = Livewire::actingAs($agent)->test('pages::marketing.call-queue')
        ->call('openPutBack', $finished->id)
        ->set('putBackNote', 'Trying a second round');

    app(SetQueueManagerView::class)->handle($this->owner, $agent, false);

    $page->call('putBack')->assertForbidden();

    expect(LeadCadence::query()->where('lead_id', $finished->id)->value('state'))->toBe(CadenceState::FinishedNoContact);
});

test('the call queue panel on a lead follows the switch', function () {
    $agent = agentAt($this->lakeview);
    $lead = finishedLead($this->lakeview, 'Finished Person');

    Livewire::actingAs($agent)->test('pages::leads.show', ['lead' => $lead])
        ->assertOk()
        ->assertDontSeeHtml('data-test="cadence-panel"')
        ->assertDontSee('Queue history')
        ->assertDontSee('Put back in queue')
        ->call('openPutBack')
        ->assertForbidden();

    $agent->forceFill(['queue_manager_view' => true])->save();

    Livewire::actingAs($agent)->test('pages::leads.show', ['lead' => $lead])
        ->assertSeeHtml('data-test="cadence-panel"')
        ->assertSee('Queue history')
        ->assertSee('Put back in queue');
});

/*
|--------------------------------------------------------------------------
| Changing the switch
|--------------------------------------------------------------------------
*/

test('owner and leadership switch it on and off, and each change is recorded once', function (Role $role) {
    $actor = User::factory()->withRoleEverywhere($role)->create(['name' => 'Lee Leadership']);
    $agent = agentAt($this->lakeview);

    expect(app(SetQueueManagerView::class)->handle($actor, $agent, true))->toBeTrue();
    expect($agent->fresh()->queue_manager_view)->toBeTrue();

    $event = AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->sole();

    expect($event->actor_id)->toBe($actor->id);
    expect($event->subject_id)->toBe($agent->id);
    expect($event->values_before)->toBe(['queue_manager_view' => false]);
    expect($event->values_after)->toBe(['queue_manager_view' => true]);

    // Saving what is already saved changes nothing and records nothing.
    expect(app(SetQueueManagerView::class)->handle($actor, $agent, true))->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->count())->toBe(1);

    expect(app(SetQueueManagerView::class)->handle($actor, $agent, false))->toBeTrue();
    expect($agent->fresh()->queue_manager_view)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->count())->toBe(2);
})->with([Role::Owner, Role::Leadership]);

test('nobody else may change the switch, including the agent themselves', function (Closure $person) {
    $agent = agentAt($this->lakeview);
    $actor = $person($this, $agent);

    expect(fn () => app(SetQueueManagerView::class)->handle($actor, $agent, true))->toThrow(AuthorizationException::class);

    expect($agent->fresh()->queue_manager_view)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->count())->toBe(0);
})->with([
    'the agent' => [fn ($test, User $agent) => $agent],
    'a location manager' => [fn ($test, User $agent) => User::factory()->withRoleEverywhere(Role::LocationManager)->create()],
    'leadership at one location only' => [fn ($test, User $agent) => User::factory()->withRoleAt(Role::Leadership, $test->lakeview)->create()],
    'a company-wide agent with the switch on' => [fn ($test, User $agent) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['queue_manager_view' => true])],
]);

test('a person who is not a call center agent cannot be switched', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);

    expect(fn () => app(SetQueueManagerView::class)->handle($this->owner, $manager, true))
        ->toThrow(ValidationException::class, 'Morgan Manager is not a Call Center Agent, so there is nothing to switch.');

    expect($manager->fresh()->queue_manager_view)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The settings page
|--------------------------------------------------------------------------
*/

test('the settings page lists the active call center agents with where they work, all switched off', function () {
    agentAt($this->lakeview, name: 'Avery Agent');
    User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Callcenter']);
    User::factory()->withRoleAt(Role::CallCenterAgent, $this->harbor)->inactive()->create(['name' => 'Gone Agent']);
    User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);

    $page = Livewire::actingAs($this->owner)->test('pages::settings.contact-center');

    $page->assertSee('Manager view for Call Center Agents')
        ->assertSeeInOrder(['Avery Agent', 'Lakeview', 'Casey Callcenter', 'All locations'])
        ->assertDontSee('Gone Agent')
        ->assertDontSee('Morgan Manager');

    expect($page->get('managerView'))->each->toBeFalse();
    expect($page->get('managerView'))->toHaveCount(2);
});

test('with no call center agents the settings page says so', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.contact-center')
        ->assertSee('Nobody has Call Center Agent access yet.');
});

test('changing a switch on the settings page saves it at once', function () {
    $agent = agentAt($this->lakeview);

    $page = Livewire::actingAs($this->owner)->test('pages::settings.contact-center');

    $page->set("managerView.{$agent->id}", true)->assertHasNoErrors();

    expect($agent->fresh()->queue_manager_view)->toBeTrue();
    expect($page->get('managerView'))->toBe([$agent->id => true]);
    expect(AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->sole()->actor_id)->toBe($this->owner->id);

    $page->set("managerView.{$agent->id}", false)->assertHasNoErrors();

    expect($agent->fresh()->queue_manager_view)->toBeFalse();
});

test('the settings page cannot switch a person who is not a call center agent, or a made-up one', function () {
    $agent = agentAt($this->lakeview);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);

    $page = Livewire::actingAs($this->owner)->test('pages::settings.contact-center');

    $page->set("managerView.{$manager->id}", true)
        ->assertHasErrors('agent')
        ->assertSee('Morgan Manager is not a Call Center Agent, so there is nothing to switch.');

    expect($manager->fresh()->queue_manager_view)->toBeFalse();

    $page->set('managerView.999999', true)->assertHasNoErrors();
    $page->set('managerView', [$agent->id => true, $manager->id => true]);

    // Whatever was sent, the page shows what is saved, and nothing was.
    expect($page->get('managerView'))->toBe([$agent->id => false]);
    expect($agent->fresh()->queue_manager_view)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::StaffQueueManagerViewChanged)->count())->toBe(0);
});

test('someone who loses the settings permission cannot change a switch from an open page', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    $agent = agentAt($this->lakeview);

    $page = Livewire::actingAs($leader)->test('pages::settings.contact-center');

    $leader->accessGrants()->delete();
    $leader->unsetRelation('accessGrants');

    $page->set("managerView.{$agent->id}", true)->assertForbidden();

    expect($agent->fresh()->queue_manager_view)->toBeFalse();
});
