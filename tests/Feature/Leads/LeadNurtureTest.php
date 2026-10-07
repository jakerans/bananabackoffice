<?php

use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Database\Factories\LeadFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The company keeps its calendar in America/New_York. These tests start at
 * 11:00 there on Tuesday, October 6, 2026.
 */
beforeEach(function () {
    $this->travelTo('2026-10-06 15:00:00');
});

/*
|--------------------------------------------------------------------------
| Moving a lead to nurture
|--------------------------------------------------------------------------
*/

test('a lead being worked can be moved to nurture with a follow-up date and a note', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openNurture')
        ->assertSee('Schedule follow-up')
        ->assertSee('Schedule a manual callback with a date and note.')
        ->set('followUpOn', '2026-11-02')
        ->set('followUpNote', 'Wants a quote once the roof is finished.')
        ->call('saveNurture')
        ->assertHasNoErrors()
        ->assertSee('Deferred Follow-up')
        ->assertSee('Follow up on Nov 2, 2026')
        ->assertSee('Wants a quote once the roof is finished.')
        ->assertDontSeeHtml('data-test="follow-up-due"');

    $nurtured = $lead->fresh();

    expect($nurtured->status)->toBe(LeadStatus::Nurture);
    expect($nurtured->follow_up_on->toDateString())->toBe('2026-11-02');
    expect($nurtured->follow_up_note)->toBe('Wants a quote once the roof is finished.');
    // The follow-up belongs to whoever already has the lead.
    expect($nurtured->assignee->is($consultant))->toBeTrue();
    expect($nurtured->isActive())->toBeTrue();

    $entry = LeadStatusChange::query()->sole();

    expect($entry->event)->toBe(LeadStatusEvent::MovedToNurture);
    expect($entry->from_status)->toBe(LeadStatus::Working);
    expect($entry->to_status)->toBe(LeadStatus::Nurture);
    expect($entry->changedBy->is($consultant))->toBeTrue();
    expect($entry->assignee->is($consultant))->toBeTrue();
    expect($entry->location->is($lakeview))->toBeTrue();
    expect($entry->follow_up_on->toDateString())->toBe('2026-11-02');
    expect($entry->note)->toBe('Wants a quote once the roof is finished.');
});

test('a qualified lead can be moved to nurture', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->qualified()->assignedTo($consultant)->create();

    app(MoveLeadToNurture::class)->handle($lead, '2026-12-01', 'Budget is approved in December.', $consultant);

    expect($lead->fresh()->status)->toBe(LeadStatus::Nurture);
    expect(LeadStatusChange::query()->sole()->from_status)->toBe(LeadStatus::Qualified);
});

test('a follow-up may be set as far ahead as needed', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    app(MoveLeadToNurture::class)->handle($lead, '2031-04-15', 'Repaints every five years.', $consultant);

    expect($lead->fresh()->follow_up_on->toDateString())->toBe('2031-04-15');
});

test('nurture needs a date that is not in the past and a note', function (?string $date, ?string $note, string $field) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openNurture')
        ->set('followUpOn', (string) $date)
        ->set('followUpNote', (string) $note)
        ->call('saveNurture')
        ->assertHasErrors($field);

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
    expect(LeadStatusChange::query()->count())->toBe(0);
})->with([
    'no date' => [null, 'Call back.', 'followUpOn'],
    'yesterday' => ['2026-10-05', 'Call back.', 'followUpOn'],
    'not a date' => ['next week', 'Call back.', 'followUpOn'],
    'no note' => ['2026-11-02', null, 'followUpNote'],
    'a blank note' => ['2026-11-02', '   ', 'followUpNote'],
]);

test('a follow-up can be set for today', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    app(MoveLeadToNurture::class)->handle($lead, '2026-10-06', 'Call back this afternoon.', $consultant);

    expect($lead->fresh()->followUpIsDue())->toBeTrue();
});

test('a lead with nobody assigned cannot be moved to nurture', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    expect(fn () => app(MoveLeadToNurture::class)->handle($lead, '2026-11-02', 'Call back.', $manager))
        ->toThrow(ValidationException::class, 'Someone must be assigned to this lead to own the follow-up.');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('a lead that is new or disqualified cannot be moved to nurture', function (Closure $state) {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = $state(Lead::factory()->at($lakeview)->assignedTo($consultant))->create();
    $status = $lead->status;

    expect(fn () => app(MoveLeadToNurture::class)->handle($lead, '2026-11-02', 'Call back.', $manager))
        ->toThrow(ValidationException::class, 'Only a lead that is being worked, or is qualified, can have a deferred follow-up scheduled.');

    expect($lead->fresh()->status)->toBe($status);
})->with([
    'new' => [fn (LeadFactory $lead) => $lead],
    'disqualified' => [fn (LeadFactory $lead) => $lead->disqualified()],
]);

/*
|--------------------------------------------------------------------------
| Rescheduling and resuming
|--------------------------------------------------------------------------
*/

test('a follow-up can be rescheduled without the lead leaving nurture', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->nurture('2026-10-10', 'Call back about the exterior.')->assignedTo($consultant)->create([
        'status_changed_at' => '2026-10-01 12:00:00',
    ]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openNurture')
        ->assertSet('followUpOn', '2026-10-10')
        ->assertSet('followUpNote', 'Call back about the exterior.')
        ->set('followUpOn', '2026-10-24')
        ->set('followUpNote', 'They are away until the 23rd.')
        ->call('saveNurture')
        ->assertHasNoErrors();

    $rescheduled = $lead->fresh();

    expect($rescheduled->status)->toBe(LeadStatus::Nurture);
    expect($rescheduled->follow_up_on->toDateString())->toBe('2026-10-24');
    expect($rescheduled->follow_up_note)->toBe('They are away until the 23rd.');
    // It has been in nurture since the 1st. Moving the date does not restart that.
    expect($rescheduled->status_changed_at->toDateTimeString())->toBe('2026-10-01 12:00:00');

    $entry = LeadStatusChange::query()->sole();

    expect($entry->event)->toBe(LeadStatusEvent::FollowUpRescheduled);
    expect($entry->from_status)->toBe(LeadStatus::Nurture);
    expect($entry->to_status)->toBe(LeadStatus::Nurture);
    expect($entry->follow_up_on->toDateString())->toBe('2026-10-24');
});

test('saving a follow-up unchanged records nothing', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->nurture('2026-10-10', 'Call back about the exterior.')->assignedTo($consultant)->create();

    app(MoveLeadToNurture::class)->handle($lead, '2026-10-10', 'Call back about the exterior.', $consultant);

    expect(LeadStatusChange::query()->count())->toBe(0);
});

test('resuming a lead in nurture returns it to working with the same person', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->nurture('2026-10-10')->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReturnToWorking')
        ->assertSee('The scheduled follow-up will clear')
        ->call('resumeWorking')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="lead-follow-up"');

    $resumed = $lead->fresh();

    expect($resumed->status)->toBe(LeadStatus::Working);
    expect($resumed->assignee->is($consultant))->toBeTrue();
    expect($resumed->follow_up_on)->toBeNull();
    expect($resumed->follow_up_note)->toBeNull();

    $entry = LeadStatusChange::query()->sole();

    expect($entry->event)->toBe(LeadStatusEvent::ResumedWorking);
    expect($entry->from_status)->toBe(LeadStatus::Nurture);
    expect($entry->to_status)->toBe(LeadStatus::Working);
});

/*
|--------------------------------------------------------------------------
| When a follow-up is due
|--------------------------------------------------------------------------
*/

test('a follow-up shows as due on its date and as overdue afterwards, and nothing changes by itself', function (string $followUpOn, string $shown, string $notShown) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->nurture($followUpOn)->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee($shown)
        ->assertDontSee($notShown);

    // Nothing is escalated, reassigned or closed when the date passes.
    expect($lead->fresh()->status)->toBe(LeadStatus::Nurture);
    expect($lead->fresh()->assignee->is($consultant))->toBeTrue();
})->with([
    'today' => ['2026-10-06', 'Follow-up due', 'Overdue'],
    'yesterday' => ['2026-10-05', 'Overdue by 1 day', 'Follow-up due'],
    'four days ago' => ['2026-10-02', 'Overdue by 4 days', 'Follow-up due'],
    'tomorrow' => ['2026-10-07', 'Follow up on Oct 7, 2026', 'Follow-up due'],
]);

test('a follow-up becomes due by the company\'s calendar, not by the server\'s clock', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->nurture('2026-10-07')->assignedTo($consultant)->create();

    // 10 pm on the 6th in New York is already the 7th in UTC.
    $this->travelTo('2026-10-07 02:00:00');

    expect($lead->fresh()->followUpIsDue())->toBeFalse();
    expect(Lead::query()->actionable()->count())->toBe(0);

    // Just after midnight in New York.
    $this->travelTo('2026-10-07 04:01:00');

    expect($lead->fresh()->followUpIsDue())->toBeTrue();
    expect($lead->fresh()->daysOverdue())->toBe(0);
    expect(Lead::query()->actionable()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Follow-ups in the Leads list
|--------------------------------------------------------------------------
*/

test('assigned to me includes a follow-up once it is due, and not before', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create(['inquiry_name' => 'Working Wanda']);
    Lead::factory()->at($lakeview)->qualified()->assignedTo($consultant)->create(['inquiry_name' => 'Qualified Quinn']);
    Lead::factory()->at($lakeview)->nurture('2026-10-06')->assignedTo($consultant)->create(['inquiry_name' => 'Due Dana']);
    Lead::factory()->at($lakeview)->nurture('2026-10-01')->assignedTo($consultant)->create(['inquiry_name' => 'Overdue Omar']);
    Lead::factory()->at($lakeview)->nurture('2026-10-20')->assignedTo($consultant)->create(['inquiry_name' => 'Later Lena']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Assigned to me', '(4)'])
        ->assertSee(['Working Wanda', 'Qualified Quinn', 'Due Dana', 'Overdue Omar'])
        ->assertSee('Overdue by 5 days')
        ->assertDontSee('Later Lena');
});

test('a sales consultant\'s follow-ups view lists their own nurture leads by follow-up date', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->nurture('2026-10-20')->assignedTo($consultant)->create(['inquiry_name' => 'Later Lena']);
    Lead::factory()->at($lakeview)->nurture('2026-10-01')->assignedTo($consultant)->create(['inquiry_name' => 'Overdue Omar']);
    Lead::factory()->at($lakeview)->nurture('2026-10-06')->assignedTo($consultant)->create(['inquiry_name' => 'Due Dana']);
    Lead::factory()->at($lakeview)->nurture('2026-10-02')->assignedTo($colleague)->create(['inquiry_name' => 'Colleague Cara']);
    Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create(['inquiry_name' => 'Working Wanda']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.index')
        ->call('show', 'follow-ups')
        ->assertSet('counts.follow-ups', 3)
        ->assertSet('leads', fn ($leads) => $leads->total() === 3)
        ->assertSeeInOrder(['Follow-ups', '(3)'])
        ->assertSeeInOrder(['Overdue Omar', 'Due Dana', 'Later Lena'])
        ->assertDontSee('Colleague Cara')
        ->assertDontSee('Working Wanda');
});

test('someone who hands out leads sees every follow-up at the location they are working in', function (Closure $distributor) {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $sam = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $nora = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create();

    Lead::factory()->at($lakeview)->nurture('2026-10-01')->assignedTo($sam)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($northside)->nurture('2026-10-01')->assignedTo($nora)->create(['inquiry_name' => 'Northside Nell']);

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    Livewire::actingAs($distributor($lakeview))
        ->test('pages::leads.index')
        ->call('show', 'follow-ups')
        ->assertSet('counts.follow-ups', 1)
        ->assertSet('leads', fn ($leads) => $leads->total() === 1)
        ->assertSee('Lakeview Lena')
        ->assertDontSee('Northside Nell');
})->with([
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
    'owner working in that location' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
]);

test('a nurture lead left unassigned by a transfer stays in the follow-ups of the people who hand out leads there', function (Closure $distributor) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $sam = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $lead = Lead::factory()->at($lakeview)->nurture('2026-10-01')->assignedTo($sam)->create(['inquiry_name' => 'Transferred Tara']);

    // The move clears who has it. It is still in nurture, with its follow-up.
    app(RouteLead::class)->handle($lead, $northside, 'The house is on the north side.', $owner);

    expect($lead->fresh()->assigned_user_id)->toBeNull();
    expect($lead->fresh()->status)->toBe(LeadStatus::Nurture);

    session([CurrentLocation::SESSION_KEY => $northside->id]);

    Livewire::actingAs($distributor($northside, $owner))
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Follow-ups', '(1)'])
        ->call('show', 'follow-ups')
        // Listed, and plainly with nobody on it.
        ->assertSeeInOrder(['Transferred Tara', 'Unassigned', 'Overdue by 5 days'])
        ->assertDontSee('Sam Sales');
})->with([
    'location manager' => [fn (Location $northside) => User::factory()->withRoleAt(Role::LocationManager, $northside)->create()],
    'call center agent at the location' => [fn (Location $northside) => User::factory()->withRoleAt(Role::CallCenterAgent, $northside)->create()],
    'company-wide call center agent' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'owner' => [fn (Location $northside, User $owner) => $owner],
]);

test('a sales consultant\'s follow-ups never include a nurture lead that is not theirs, assigned or not', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->nurture('2026-10-01')->create(['inquiry_name' => 'Unassigned Una']);
    Lead::factory()->at($lakeview)->nurture('2026-10-01')->assignedTo($colleague)->create(['inquiry_name' => 'Colleague Cara']);
    Lead::factory()->at($lakeview)->nurture('2026-10-01')->assignedTo($consultant)->create(['inquiry_name' => 'Mine Mia']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Follow-ups', '(1)'])
        ->call('show', 'follow-ups')
        ->assertSee('Mine Mia')
        ->assertDontSee('Unassigned Una')
        ->assertDontSee('Colleague Cara');
});

test('a nurture lead returned to central stays in the follow-ups of people who can see unrouted leads', function (Closure $viewer, bool $sees) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $sam = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->nurture('2026-10-20')->assignedTo($sam)->create(['inquiry_name' => 'Returned Rita']);

    // Back to central: no location, and so nobody assigned.
    app(ReturnLeadToCentral::class)->handle($lead, 'Outside what Lakeview covers.', $owner);

    expect($lead->fresh()->location_id)->toBeNull();
    expect($lead->fresh()->assigned_user_id)->toBeNull();
    expect($lead->fresh()->status)->toBe(LeadStatus::Nurture);

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    $page = Livewire::actingAs($viewer($lakeview, $owner))
        ->test('pages::leads.index')
        ->call('show', 'follow-ups');

    $sees
        ? $page->assertSeeInOrder(['Follow-ups', '(1)'])->assertSeeInOrder(['Returned Rita', 'Unrouted', 'Unassigned'])
        : $page->assertSeeInOrder(['Follow-ups', '(0)'])->assertDontSee('Returned Rita');
})->with([
    'owner' => [fn (Location $lakeview, User $owner) => $owner, true],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'company-wide call center agent' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), true],
    // An unrouted lead belongs to no location, so no location grant reaches it.
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(), false],
    'call center agent at one location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create(), false],
]);

test('the workload shown when assigning counts what a person has to act on now', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);

    Lead::factory()->at($lakeview)->assignedTo($consultant)->create();
    Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();
    Lead::factory()->at($lakeview)->qualified()->assignedTo($consultant)->create();
    Lead::factory()->at($lakeview)->nurture('2026-10-06')->assignedTo($consultant)->create();
    Lead::factory()->at($lakeview)->nurture('2026-10-03')->assignedTo($consultant)->create();
    // Waiting, not work: a follow-up still ahead.
    Lead::factory()->at($lakeview)->nurture('2026-11-15')->assignedTo($consultant)->create();

    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openAssign')
        ->assertSeeInOrder(['Sam Sales', '5 open leads']);
});

test('the All Locations follow-ups badge matches due and future rows including central and search', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    Lead::factory()->at($lakeview)->nurture('2026-10-06')->create(['inquiry_name' => 'Due Dana']);
    Lead::factory()->nurture('2026-10-20')->create(['inquiry_name' => 'Central Cora']);
    Lead::factory()->at($lakeview)->working()->create(['inquiry_name' => 'Working Wanda']);
    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    $page = Livewire::actingAs($owner)
        ->test('pages::leads.index')
        ->call('show', 'follow-ups')
        ->assertSet('counts.follow-ups', 2)
        ->assertSet('leads', fn ($leads) => $leads->total() === 2)
        ->assertSeeInOrder(['Follow-ups', '(2)'])
        ->assertSeeInOrder(['Due Dana', 'Central Cora'])
        ->assertDontSee('Working Wanda');

    $page->set('search', 'Central Cora')
        ->assertSet('counts.follow-ups', 1)
        ->assertSet('leads', fn ($leads) => $leads->total() === 1)
        ->assertSee('Central Cora')
        ->assertDontSee('Due Dana')
        ->set('search', 'No matching inquiry')
        ->assertSet('counts.follow-ups', 0)
        ->assertSet('leads', fn ($leads) => $leads->total() === 0)
        ->set('search', '')
        ->assertSet('counts.follow-ups', 2);
});

test('the follow-ups badge counts the full eligible result across pages', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    Lead::factory()->at($lakeview)->nurture('2026-10-20')->assignedTo($consultant)->count(26)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.index')
        ->call('show', 'follow-ups')
        ->assertSet('counts.follow-ups', 26)
        ->assertSet('leads', fn ($leads) => $leads->total() === 26 && $leads->count() === 25)
        ->call('setPage', 2)
        ->assertSet('counts.follow-ups', 26)
        ->assertSet('leads', fn ($leads) => $leads->total() === 26 && $leads->count() === 1);
});
