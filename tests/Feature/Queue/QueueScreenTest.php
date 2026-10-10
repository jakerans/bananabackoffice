<?php

use App\Actions\Leads\DisqualifyLead;
use App\Actions\Queue\SkipHeldLead;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\QueueSkipReason;
use App\Enums\Role;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Queue\DueQueue;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * The Call Queue page (docs/slice-4-plan.md, "Step 4c"). The clock is
 * Wednesday 7 October 2026, 10:00 am Eastern, unless moved.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
});

function queuePage(User $user)
{
    return Livewire::actingAs($user)->test('pages::marketing.call-queue');
}

function setterAt(Location $location, string $name = 'Avery Agent'): User
{
    return User::factory()->withRoleAt(Role::CallCenterAgent, $location)->create(['name' => $name]);
}

/*
|--------------------------------------------------------------------------
| Route and page
|--------------------------------------------------------------------------
*/

test('the page opens for the four queue roles', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get('/marketing/contact-center/queue')->assertOk()->assertSee('Call Queue');
    expect(route('marketing.call_center.queue', absolute: false))->toBe('/marketing/contact-center/queue');
})->with([Role::Owner, Role::Leadership, Role::LocationManager, Role::CallCenterAgent]);

test('the page is refused by URL and by direct Livewire call to everyone else', function (?Role $role) {
    $user = $role === null ? User::factory()->create() : User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('marketing.call_center.queue'))->assertForbidden();

    Livewire::actingAs($user)->test('pages::marketing.call-queue')->assertForbidden();
})->with([Role::SalesConsultant, Role::ProductionManager, Role::Finance, null]);

test('a guest is sent to sign in and cannot call the page', function () {
    $this->get(route('marketing.call_center.queue'))->assertRedirect(route('login'));

    Livewire::test('pages::marketing.call-queue')->assertForbidden();
});

test('actions on the page are refused again when the access is gone', function () {
    $agent = setterAt($this->lakeview);
    dueLead($this->lakeview);

    $page = queuePage($agent);
    $agent->accessGrants()->delete();

    $page->call('nextLead')->assertForbidden();
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

test('the queue item is live under Marketing > Contact Center for the four roles and hidden otherwise', function () {
    $navigation = app(Navigation::class);
    $item = $navigation->find('marketing.call_center.queue');

    expect($item->route)->toBe('marketing.call_center.queue');
    expect($navigation->find('marketing.call_center')->primary)->toBe('shared.leads');
    expect(collect($navigation->find('marketing.call_center')->children)->pluck('id')->all())->toBe(['shared.leads', 'marketing.call_center.queue']);
    expect(collect($navigation->centers())->flatMap(fn ($center) => $center->flatten())->pluck('id')->filter(fn ($id) => $id === 'marketing.call_center.queue'))->toHaveCount(1);
    expect($navigation->find('sales.call_center.queue'))->toBeNull();

    foreach ([Role::Owner, Role::Leadership, Role::LocationManager, Role::CallCenterAgent] as $role) {
        expect($navigation->allows(User::factory()->withRoleEverywhere($role)->create(), $item))->toBeTrue($role->value);
    }

    foreach ([Role::SalesConsultant, Role::ProductionManager, Role::Finance] as $role) {
        expect($navigation->allows(User::factory()->withRoleEverywhere($role)->create(), $item))->toBeFalse($role->value);
    }

    expect($navigation->allows(User::factory()->create(), $item))->toBeFalse();
});

test('the menu shows Call Queue to a setter', function () {
    $this->actingAs(setterAt($this->lakeview))->get(route('leads'))->assertSee('Call Queue');
});

/*
|--------------------------------------------------------------------------
| The setter's view
|--------------------------------------------------------------------------
*/

test('the count equals what next lead would offer, per role and scope', function () {
    $lakeviewAgent = setterAt($this->lakeview);
    $everywhere = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->harbor)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    dueLead($this->lakeview);
    dueLead($this->lakeview);
    dueLead($this->harbor);
    dueLead($this->harbor);
    dueLead(null);
    dueLead($this->lakeview, ['due_at' => '2026-10-07 14:30:00']);
    dueLead($this->lakeview);
    nextLead(User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create());

    $due = app(DueQueue::class);
    $snapshot = LeadCadence::query()->get()->mapWithKeys(fn ($cadence) => [$cadence->id => $cadence->getAttributes()])->all();

    // Each person is compared on the same seeded pool, restored between people.
    $expected = [
        'lakeview agent' => [$lakeviewAgent, 2],
        'harbor manager' => [$manager, 2],
        'everywhere agent' => [$everywhere, $due->dueNowCount($everywhere, now())],
        'owner' => [$owner, $due->dueNowCount($owner, now())],
    ];

    foreach ($expected as $label => [$person, $number]) {
        foreach ($snapshot as $id => $attributes) {
            LeadCadence::query()->whereKey($id)->update($attributes);
        }

        $counted = $due->dueNowCount($person, now());
        $offered = 0;

        while (nextLeadFor($person)) {
            $offered++;
        }

        expect($counted)->toBe($offered, $label)->and($counted)->toBe($number, $label)->and($counted)->toBeGreaterThan(0, $label);
    }

    foreach ($snapshot as $id => $attributes) {
        LeadCadence::query()->whereKey($id)->update($attributes);
    }

    // The scopes really differ: company-wide people also get the unrouted lead (2 + 2 + 1).
    expect($due->dueNowCount($lakeviewAgent, now()))->toBe(2)
        ->and($due->dueNowCount($manager, now()))->toBe(2)
        ->and($due->dueNowCount($everywhere, now()))->toBe(5)
        ->and($due->dueNowCount($owner, now()))->toBe(5);
});

function nextLeadFor(User $person): bool
{
    $claim = nextLead($person);

    if (! $claim->given()) {
        return false;
    }

    // Take it out of the pool so the same person is offered the next one.
    LeadCadence::query()->whereKey($claim->cadence->id)->update(['state' => CadenceState::Connected->value, 'held_by_user_id' => null, 'hold_token' => null, 'held_at' => null, 'held_until' => null]);

    return true;
}

test('the count leaves out leads outside calling hours, held by others and just skipped', function () {
    $agent = setterAt($this->lakeview);
    $other = setterAt($this->lakeview, 'Blake Agent');

    dueLead($this->lakeview);
    $heldByOther = dueLead($this->lakeview);
    $skipLead = dueLead($this->lakeview);

    nextLead($other);
    $first = nextLead($agent);
    app(SkipHeldLead::class)->handle($agent, $first->cadence->id, $first->cadence->hold_token, QueueSkipReason::NoUsableNumber);

    $count = app(DueQueue::class)->dueNowCount($agent, now());
    expect($count)->toBe(1);

    $this->travelTo('2026-10-08 02:00:00'); // 10 pm Eastern
    expect(app(DueQueue::class)->dueNowCount($agent, now()))->toBe(0);
});

test('next lead shows one lead and the page never lists the due leads', function () {
    $agent = setterAt($this->lakeview);
    $leads = collect(range(1, 4))->map(fn (int $n) => dueLead($this->lakeview, attributes: ['inquiry_name' => "Lead Person {$n}", 'received_at' => "2026-10-07 13:0{$n}:00"]));

    $page = queuePage($agent)->assertSee('4 leads due now');

    foreach ($leads as $lead) {
        $page->assertDontSee($lead->inquiry_name);
    }

    $page->call('nextLead')->assertSee('Lead Person 4')->assertSee('Attempt 1 of')->assertSee('Lakeview')->assertSee('Open the full lead');

    foreach ([1, 2, 3] as $n) {
        $page->assertDontSee("Lead Person {$n}");
    }

    // Reloading the page brings the card back without claiming a second lead.
    queuePage($agent)->assertSee('Lead Person 4');
    expect(LeadCadence::query()->where('held_by_user_id', $agent->id)->count())->toBe(1);
});

test('a second session cannot obtain the same lead', function () {
    $first = setterAt($this->lakeview, 'First Setter');
    $second = setterAt($this->lakeview, 'Second Setter');
    collect(range(1, 8))->each(fn (int $n) => dueLead($this->lakeview, attributes: ['inquiry_name' => "Queue Person {$n}"]));

    $a = queuePage($first);
    $b = queuePage($second);

    $seen = [];
    foreach (range(1, 3) as $round) {
        Livewire::actingAs($first);
        $a->call('nextLead');
        Livewire::actingAs($second);
        $b->call('nextLead');

        $one = LeadCadence::query()->where('held_by_user_id', $first->id)->value('lead_id');
        $two = LeadCadence::query()->where('held_by_user_id', $second->id)->value('lead_id');

        expect($one)->not->toBeNull()->and($two)->not->toBeNull()->and($one)->not->toBe($two);
        array_push($seen, $one, $two);

        // Finish both calls so the next round claims new leads.
        Livewire::actingAs($first);
        $a->call('openSkip')->set('skipReason', 'cannot_call_now')->call('skip');
        Livewire::actingAs($second);
        $b->call('openSkip')->set('skipReason', 'cannot_call_now')->call('skip');
    }

    expect($seen)->toHaveCount(6);
});

test('with nothing due the page says so and says when calling hours open', function () {
    $agent = setterAt($this->lakeview);

    queuePage($agent)->assertSee('Nothing is due for you right now.')->assertDontSee('Calling hours are closed');

    $this->travelTo('2026-10-08 02:00:00');

    queuePage($agent)->assertSee('Calling hours are closed. They open');
});

test('the card shows the location time and the device time source for Eastern and Pacific locations', function () {
    $pacific = Location::factory()->create(['name' => 'Seaside', 'timezone' => 'America/Los_Angeles']);
    $eastern = Location::factory()->create(['name' => 'Harbor East', 'timezone' => 'America/New_York']);
    $this->travelTo('2026-10-07 18:00:00'); // 2 pm Eastern, 11 am Pacific

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    foreach ([[$pacific, 'Oct 7, 11:00 AM PDT'], [$eastern, 'Oct 7, 2:00 PM EDT']] as [$location, $expected]) {
        $lead = Lead::factory()->at($location)->create(['received_at' => '2026-10-07 18:00:00']);
        enterQueue($lead, ['due_at' => '2026-10-07 18:00:00']);

        $page = queuePage($agent)->call('nextLead');

        $page->assertSee($expected)->assertSeeHtml('datetime="2026-10-07T18:00:00+00:00"')->assertSeeHtml('Intl.DateTimeFormat');

        LeadCadence::query()->update(['state' => CadenceState::Connected->value, 'held_by_user_id' => null, 'hold_token' => null, 'held_at' => null, 'held_until' => null]);
    }
});

test('nothing on the server reads a device timezone', function () {
    $page = file_get_contents(resource_path('views/pages/marketing/⚡call-queue.blade.php'));
    $component = file_get_contents(resource_path('views/components/queue-time.blade.php'));

    expect($page.$component)->not->toMatch('/\$_(COOKIE|SERVER)|timezone\s*=\s*request|wire:model[^"]*timezone|\$wire\.[a-z]*[tT]imezone/');
    expect($component)->toContain('Intl.DateTimeFormat')->not->toContain('wire:');
});

test('the device time formatter runs for Eastern and Pacific timestamps', function () {
    $node = trim((string) shell_exec('command -v node'));

    if ($node === '') {
        $this->markTestSkipped('Node is not available to run the formatter.');
    }

    $component = file_get_contents(resource_path('views/components/queue-time.blade.php'));
    preg_match('/new Intl\.DateTimeFormat\(undefined, (\{.*?\})\)/', $component, $match);

    expect($match)->not->toBeEmpty();

    foreach (['America/New_York' => 'EDT', 'America/Los_Angeles' => 'PDT'] as $zone => $abbreviation) {
        $script = "const o = {$match[1]}; o.timeZone = process.argv[1]; console.log(new Intl.DateTimeFormat('en-US', o).format(new Date('2026-10-07T18:00:00+00:00')));";
        $output = trim((string) shell_exec(escapeshellarg($node).' -e '.escapeshellarg($script).' '.escapeshellarg($zone).' 2>&1'));

        expect($output)->toContain($abbreviation)->and($output)->toContain('Oct 7, 2026');
    }
});

/*
|--------------------------------------------------------------------------
| Log, skip, disqualify
|--------------------------------------------------------------------------
*/

test('logging from the card saves one attempt through the shared action, ends the hold and schedules the next', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview);

    $page = queuePage($agent)->call('nextLead')->call('openContactAttempt');

    expect($page->get('attemptChannel'))->toBe('phone')->and($page->get('attemptDirection'))->toBe('outbound');

    // Channel and direction are fixed on the card, whatever the browser sends.
    $page->set('attemptChannel', 'sms')->set('attemptDirection', 'inbound')->set('attemptDisposition', 'no_answer')->set('attemptDurationSeconds', 20)->call('recordContactAttempt')->assertHasNoErrors();

    $attempt = ContactAttempt::query()->sole();
    expect($attempt->channel->value)->toBe('phone')->and($attempt->direction->value)->toBe('outbound')->and($attempt->responsible_user_id)->toBe($agent->id);

    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();
    expect($cadence->held_by_user_id)->toBeNull()->and($cadence->attempts_made)->toBe(1)->and($cadence->due_at->gt(now()))->toBeTrue();

    $page->assertSee('Your call was logged.')->assertSee('Next lead');
});

test('an attempt form left open past the hold still saves if the person may work the lead', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview);

    $page = queuePage($agent)->call('nextLead')->call('openContactAttempt');

    $this->travelTo(now()->addMinutes(30));

    $page->call('refreshQueue')->assertSee('Your time on this lead ran out.');
    $page->set('attemptDurationSeconds', 20)->call('recordContactAttempt')->assertHasNoErrors();

    expect(ContactAttempt::query()->where('lead_id', $lead->id)->count())->toBe(1);
});

test('a stale card saves nothing it should not and shows the reason', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['inquiry_name' => 'Hidden Person']);

    $page = queuePage($agent)->call('nextLead')->call('openContactAttempt');

    // The lead moves to a Location the setter has no queue access at.
    $lead->forceFill(['location_id' => $this->harbor->id])->save();

    $page->call('recordContactAttempt')
        ->assertSee('Your access to this lead changed.')
        ->assertDontSee('Hidden Person');

    expect(ContactAttempt::query()->count())->toBe(0);

    $page->call('openSkip')->assertForbidden();
});

test('a hold that ran out is explained as time running out, and a skip then saves nothing', function () {
    $agent = setterAt($this->lakeview);
    dueLead($this->lakeview);

    $page = queuePage($agent)->call('nextLead');
    $this->travelTo(now()->addMinutes(30));

    $page->call('refreshQueue')->assertSee('Your time on this lead ran out.')->assertSee('Next lead');
    $page->set('skipReason', 'no_usable_number')->call('skip')->assertHasNoErrors();

    expect(queueHistory(Lead::query()->first()))->not->toContain('skipped:no_usable_number');
});

test('skip needs a reason, and Other needs a note', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview);

    $page = queuePage($agent)->call('nextLead')->call('openSkip');

    $page->call('skip')->assertHasErrors('skipReason');

    // Each message is shown once, and choosing a reason clears what was said about the last choice.
    expect(substr_count($page->html(), 'Choose why you are skipping this lead.'))->toBe(1);
    $page->set('skipReason', 'other')->assertHasNoErrors();

    $page->call('skip')->assertHasErrors('skipNote');
    expect(substr_count($page->html(), 'Say briefly why you are skipping this lead.'))->toBe(1);
    $page->set('skipNote', 'Number belongs to a business')->call('skip')->assertHasNoErrors()->assertSee('You skipped this lead');

    expect(queueHistory($lead))->toContain('skipped:other');
});

test('disqualify from the card follows the existing reasons and permission', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['status' => LeadStatus::Working, 'assigned_user_id' => $agent->id]);
    $reason = LeadDisqualificationReason::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('special_handling')->orWhere('special_handling', '!=', LeadDisqualificationReason::UNABLE_TO_CONTACT))->orderBy('id')->firstOrFail();

    $page = queuePage($agent)->call('nextLead')->call('openDisqualify');

    $page->call('disqualify')->assertHasErrors('disqualificationReasonId');
    expect(substr_count($page->html(), 'Choose why this lead is being disqualified.'))->toBe(1);
    $page->set('disqualificationReasonId', $reason->id);

    // Someone else changes the lead while the dialog is open: nothing is saved.
    Lead::query()->whereKey($lead->id)->increment('lifecycle_version');
    $page->call('disqualify')->assertHasErrors('stale');
    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('a refusal that belongs to no field stays through the page refresh until the next action', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['status' => LeadStatus::Working, 'assigned_user_id' => $agent->id]);
    $reason = LeadDisqualificationReason::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('special_handling')->orWhere('special_handling', '!=', LeadDisqualificationReason::UNABLE_TO_CONTACT))->orderBy('id')->firstOrFail();
    $refusal = 'This lead was just changed by someone else, so nothing was saved.';

    $page = queuePage($agent)->call('nextLead')->call('openDisqualify')->set('disqualificationReasonId', $reason->id);

    Lead::query()->whereKey($lead->id)->increment('lifecycle_version');

    $page->call('disqualify')->assertHasErrors('stale')->assertSee($refusal);

    // The ten-second refresh, twice: the message is still there, said once more each time and not doubled.
    $page->call('refreshQueue')->assertHasErrors('stale')->assertSee($refusal);
    $page->call('refreshQueue')->assertHasErrors('stale')->assertSee($refusal);
    expect($page->errors()->get('stale'))->toHaveCount(1);

    // The person's next action clears it.
    $page->call('openDisqualify')->assertHasNoErrors()->assertDontSee($refusal);
    $page->call('refreshQueue')->assertHasNoErrors()->assertDontSee($refusal);

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('pressing next lead clears an earlier refusal', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['status' => LeadStatus::Working, 'assigned_user_id' => $agent->id]);
    $reason = LeadDisqualificationReason::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('special_handling')->orWhere('special_handling', '!=', LeadDisqualificationReason::UNABLE_TO_CONTACT))->orderBy('id')->firstOrFail();

    $page = queuePage($agent)->call('nextLead')->call('openDisqualify')->set('disqualificationReasonId', $reason->id);

    Lead::query()->whereKey($lead->id)->increment('lifecycle_version');

    $page->call('disqualify')->assertHasErrors('stale');
    $page->call('nextLead')->assertHasNoErrors();
    $page->call('refreshQueue')->assertHasNoErrors();
});

test('the browser cannot plant a refusal of its own', function () {
    $agent = setterAt($this->lakeview);

    expect(fn () => queuePage($agent)->set('keptErrors', ['stale' => ['Something the server never said.']]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a valid reason disqualifies the lead from the card, ends its cadence and hold, and offers Next lead', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['status' => LeadStatus::Working, 'assigned_user_id' => $agent->id]);
    $reason = LeadDisqualificationReason::query()->where('is_active', true)->whereNull('special_handling')->where('requires_note', false)->orderBy('id')->firstOrFail();

    queuePage($agent)->call('nextLead')->call('openDisqualify')
        ->set('disqualificationReasonId', $reason->id)
        ->call('disqualify')
        ->assertHasNoErrors()
        ->assertSee('You disqualified this lead.')
        ->assertSee('Next lead');

    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified)
        ->and($cadence->state)->not->toBe(CadenceState::InQueue)
        ->and($cadence->held_by_user_id)->toBeNull()
        ->and($cadence->hold_token)->toBeNull();
});

test('the Duplicate reason takes an original from the possible originals, and a reason that needs a note is refused without one', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['status' => LeadStatus::Working, 'assigned_user_id' => $agent->id, 'inquiry_phone_normalized' => '5552017788']);
    $original = Lead::factory()->at($this->lakeview)->create(['inquiry_phone_normalized' => '5552017788']);
    $duplicate = LeadDisqualificationReason::query()->where('is_active', true)->where('special_handling', LeadDisqualificationReason::DUPLICATE)->firstOrFail();
    $needsNote = LeadDisqualificationReason::query()->where('is_active', true)->whereNull('special_handling')->where('requires_note', true)->firstOrFail();

    $page = queuePage($agent)->call('nextLead')->call('openDisqualify');

    expect($page->instance()->possibleOriginals->pluck('id')->all())->toContain($original->id);

    // A reason that needs a note is refused without one, and nothing is saved.
    $page->set('disqualificationReasonId', $needsNote->id)->call('disqualify')->assertHasErrors('disqualificationNote');
    expect($lead->fresh()->status)->toBe(LeadStatus::Working);

    // Duplicate with neither an original nor a note is refused too.
    $page->set('disqualificationReasonId', $duplicate->id)->call('disqualify')->assertHasErrors('duplicateOfId');
    expect($lead->fresh()->status)->toBe(LeadStatus::Working);

    $page->set('duplicateOfId', $original->id)->call('disqualify')->assertHasNoErrors()->assertSee('You disqualified this lead.');

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified)
        ->and($lead->fresh()->duplicate_of_lead_id)->toBe($original->id);
});

test('openDisqualify and disqualify are refused for a holder who may not change the status', function () {
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview, attributes: ['status' => LeadStatus::Working, 'assigned_user_id' => $agent->id]);
    $reason = LeadDisqualificationReason::query()->where('is_active', true)->whereNull('special_handling')->where('requires_note', false)->orderBy('id')->firstOrFail();

    $page = queuePage($agent)->call('nextLead')->call('openDisqualify');

    // The lead stops being active under the open card, so the holder fails changeStatus.
    Lead::query()->whereKey($lead->id)->update(['status' => LeadStatus::Disqualified->value]);
    expect($agent->can('changeStatus', $lead->fresh()))->toBeFalse();

    $page->set('disqualificationReasonId', $reason->id)->call('disqualify')->assertForbidden();
    queuePage($agent)->call('openDisqualify')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Told at once
|--------------------------------------------------------------------------
*/

test('the page polls every ten seconds and polling never claims, extends or releases a hold', function () {
    $agent = setterAt($this->lakeview);
    dueLead($this->lakeview);
    dueLead($this->lakeview);

    $page = queuePage($agent)->assertSeeHtml('wire:poll.10s="refreshQueue"');
    $before = LeadCadence::query()->get()->map->getAttributes()->all();
    $events = LeadQueueEvent::query()->count();

    $page->call('refreshQueue');
    expect(LeadCadence::query()->get()->map->getAttributes()->all())->toBe($before)->and(LeadQueueEvent::query()->count())->toBe($events);

    $page->call('nextLead');
    $held = LeadCadence::query()->whereNotNull('held_by_user_id')->sole();
    $until = $held->held_until->toDateTimeString();

    $this->travelTo(now()->addMinutes(2));
    $page->call('refreshQueue');

    expect($held->fresh()->held_until->toDateTimeString())->toBe($until);
});

test('when calling hours close the poll replaces the card with the reason and changes nothing', function () {
    $agent = setterAt($this->lakeview);
    dueLead($this->lakeview, attributes: ['inquiry_name' => 'Hours Person']);

    $page = queuePage($agent)->call('nextLead')->assertSee('Hours Person');
    $before = LeadCadence::query()->get()->map->getAttributes()->all();
    $events = LeadQueueEvent::query()->count();

    // 10 pm Eastern is past closing, and the hold is moved to still be running.
    $this->travelTo('2026-10-08 02:00:00');
    LeadCadence::query()->update(['held_until' => now()->addMinutes(5)]);
    $before = LeadCadence::query()->get()->map->getAttributes()->all();

    $page->call('refreshQueue')->assertSee('Calling hours have closed for this lead.')->assertDontSeeHtml('data-test="queue-card"')->assertSee('Next lead');

    expect(LeadCadence::query()->get()->map->getAttributes()->all())->toBe($before)
        ->and(LeadQueueEvent::query()->count())->toBe($events);
});

test('when someone disqualifies the lead the card is replaced by the reason only', function () {
    $agent = setterAt($this->lakeview);
    $manager = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = dueLead($this->lakeview, attributes: ['inquiry_name' => 'Gone Person']);
    $reason = LeadDisqualificationReason::query()->where('is_active', true)->whereNull('special_handling')->whereNull('requires_note')->first()
        ?? LeadDisqualificationReason::query()->where('is_active', true)->whereNull('special_handling')->firstOrFail();

    $page = queuePage($agent)->call('nextLead')->assertSee('Gone Person');

    app(DisqualifyLead::class)->handle($lead->fresh(), $reason, 'Not a real inquiry', $manager);

    $page->call('refreshQueue')->assertSee('This lead was disqualified')->assertDontSee('Gone Person')->assertSee('Next lead');
});

/*
|--------------------------------------------------------------------------
| The manager part
|--------------------------------------------------------------------------
*/

test('the manager summary shows each number against known data', function () {
    $manager = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    $setter = setterAt($this->lakeview, 'Holding Setter');

    dueLead($this->lakeview, ['due_at' => '2026-10-07 13:00:00']);
    dueLead($this->lakeview, ['due_at' => '2026-10-07 13:30:00']);
    dueLead($this->lakeview, ['due_at' => '2026-10-07 15:00:00']);
    $held = dueLead($this->lakeview, ['due_at' => '2026-10-07 12:00:00'], ['inquiry_name' => 'Held Person']);
    $finished = dueLead($this->lakeview, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()], ['inquiry_name' => 'Finished Person']);

    LeadCadence::query()->where('lead_id', $held->id)->update(['held_by_user_id' => $setter->id, 'hold_token' => (string) Str::uuid(), 'held_at' => now(), 'held_until' => now()->addMinutes(10)]);

    $summary = app(DueQueue::class)->summary(app(DueQueue::class)->managerScope($manager), now());

    expect($summary['dueNow'])->toBe(2)
        ->and($summary['heldCount'])->toBe(1)
        ->and($summary['finishedCount'])->toBe(1)
        ->and($summary['longestWaitSince']->toDateTimeString())->toBe('2026-10-07 13:00:00');

    queuePage($manager)
        ->assertSeeInOrder(['Due now', 'Longest wait', 'Leads held now', 'Cadence finished, no contact'])
        ->assertSee('held by Holding Setter')
        ->assertSee('Held Person')
        ->assertSee('Finished Person');
});

test('a location manager sees only their locations numbers', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();
    $setter = setterAt($this->lakeview);

    dueLead($this->lakeview);
    dueLead($this->harbor);
    dueLead($this->harbor);
    dueLead($this->harbor, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()], ['inquiry_name' => 'Elsewhere Person']);
    dueLead($this->lakeview, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()], ['inquiry_name' => 'Mine Person']);

    $summary = app(DueQueue::class)->summary(app(DueQueue::class)->managerScope($manager), now());
    expect($summary['dueNow'])->toBe(1)->and($summary['finishedCount'])->toBe(1);

    queuePage($manager)->assertSee('Mine Person')->assertDontSee('Elsewhere Person');

    // A Call Center Agent has the manager part only once it is switched on for them (decision 85).
    queuePage($setter)->assertDontSee('Mine Person')->assertDontSee('Elsewhere Person');

    $setter->forceFill(['queue_manager_view' => true])->save();

    queuePage($setter)->assertSee('Mine Person')->assertDontSee('Elsewhere Person');
});

test('the timer warning shows at 11 minutes and when there is no heartbeat, and not at 9', function (?int $minutesAgo, bool $warns) {
    $manager = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    SchedulerHeartbeat::query()->delete();

    if ($minutesAgo !== null) {
        SchedulerHeartbeat::query()->forceCreate(['name' => SchedulerHeartbeat::QUEUE_TICK, 'last_ran_at' => now()->subMinutes($minutesAgo)]);
    }

    $page = queuePage($manager);

    $warns ? $page->assertSeeHtml('data-test="heartbeat-warning"') : $page->assertDontSeeHtml('data-test="heartbeat-warning"');
})->with([
    'eleven minutes old' => [11, true],
    'never ran' => [null, true],
    'nine minutes old' => [9, false],
]);

/*
|--------------------------------------------------------------------------
| Put back in queue
|--------------------------------------------------------------------------
*/

test('put back from the page needs a note, resolves the lead on the server and uses the return action', function () {
    $manager = User::factory()->withRoleEverywhere(Role::LocationManager)->create();
    $lead = dueLead($this->lakeview, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()]);

    $page = queuePage($manager)->assertSee('Put back in queue')->call('openPutBack', $lead->id);

    // Refused, and the person is told why, once, beside the note.
    $page->call('putBack')->assertHasErrors('putBackNote');
    expect(substr_count($page->html(), 'Say why this lead is going back in the queue.'))->toBe(1);

    // The message is kept under the field's own name, so the page's ten-second refresh does not wipe it.
    $page->call('refreshQueue')->assertHasErrors('putBackNote')->assertSee('Say why this lead is going back in the queue.');
    expect(LeadCadence::query()->where('lead_id', $lead->id)->value('state'))->toBe(CadenceState::FinishedNoContact);

    $page->set('putBackNote', 'Customer asked us to try again')->call('putBack')->assertHasNoErrors();

    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();
    expect($cadence->state)->toBe(CadenceState::InQueue)->and($cadence->cycle)->toBe(2);
    expect(queueHistory($lead))->toContain('returned_to_queue');
});

test('put back is refused to a setter and for a lead that is not finished', function () {
    $setter = setterAt($this->harbor);
    $manager = User::factory()->withRoleEverywhere(Role::LocationManager)->create();
    $finished = dueLead($this->lakeview, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()]);
    $queued = dueLead($this->lakeview);

    queuePage($setter)->call('openPutBack', $finished->id)->assertForbidden();
    queuePage($manager)->call('openPutBack', $queued->id)->assertForbidden();
    queuePage($manager)->call('putBack')->assertNotFound();
});

test('the lead page shows the cadence panel, history and put back only where the person may assign', function () {
    $manager = User::factory()->withRoleEverywhere(Role::LocationManager)->create();
    $setter = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();
    $lead = dueLead($this->lakeview, ['state' => CadenceState::FinishedNoContact, 'due_at' => null, 'ended_at' => now()], ['assigned_user_id' => $setter->id, 'status' => LeadStatus::Working]);

    Livewire::actingAs($manager)->test('pages::leads.show', ['lead' => $lead])
        ->assertSeeHtml('data-test="cadence-panel"')->assertSeeHtml('data-test="queue-history"')->assertSee('Put back in queue')
        ->call('openPutBack')->set('putBackNote', '')->call('putBack')->assertHasErrors('putBackNote')->assertSee('Say why this lead is going back in the queue.')
        ->set('putBackNote', 'Trying a second round')->call('putBack')->assertHasNoErrors();

    expect(LeadCadence::query()->where('lead_id', $lead->id)->value('state'))->toBe(CadenceState::InQueue);

    Livewire::actingAs($setter)->test('pages::leads.show', ['lead' => $lead])
        ->assertDontSeeHtml('data-test="cadence-panel"')->assertDontSee('Queue history')->call('openPutBack')->assertForbidden();
});

test('a queue history entry never starts with a separator', function () {
    $manager = User::factory()->withRoleEverywhere(Role::LocationManager)->create();
    $agent = setterAt($this->lakeview);
    $lead = dueLead($this->lakeview);

    // "Attempt counted" names a person and has no reason and no note.
    logAttempt($lead, $agent);

    $html = Livewire::actingAs($manager)->test('pages::leads.show', ['lead' => $lead])->html();

    // The words of each entry's second line, as a person reads them.
    preg_match_all('/<li[^>]*data-test="queue-event".*?<div class="text-zinc-700">(.*?)<\/div>\s*<\/li>/s', $html, $entries);

    $lines = array_map(
        fn (string $entry): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) preg_replace('/<!--.*?-->/s', '', $entry))))),
        $entries[1],
    );

    // Newest first: the attempt, with the separator only between the person and the attempt number; then entering.
    expect($lines)->toBe(['Avery Agent · attempt 1', 'attempt 1']);
});

/*
|--------------------------------------------------------------------------
| Dialogs
|--------------------------------------------------------------------------
*/

test('the queue page, the lead page and the shared form use the shared confirmation dialog and no native dialog', function () {
    foreach (['pages/marketing/⚡call-queue', 'pages/leads/⚡show', 'partials/contact-attempt-form'] as $view) {
        $contents = file_get_contents(resource_path("views/{$view}.blade.php"));

        expect($contents)->not->toContain('wire:confirm')->not->toMatch('/\b(window\.)?(alert|confirm|prompt)\s*\(/');
    }

    expect(file_get_contents(resource_path('views/pages/marketing/⚡call-queue.blade.php')))->toContain('<x-confirmation-modal');
    expect(file_get_contents(resource_path('views/pages/leads/⚡show.blade.php')))->toContain('name="put-back-in-queue"');
});
