<?php

use App\Actions\Queue\ClaimNextLead;
use App\Enums\QueueEventType;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Support\Access\Impersonation;
use App\Support\Queue\QueueClaim;
use App\Support\Queue\QueueTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/*
 * "Next lead": one due Lead nobody else can get, under a timed hold
 * (docs/slice-4-plan.md, contract 5). These tests are deterministic; the same
 * guarantees are raced by real processes in QueueConcurrencyTest.
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern, unless moved.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
});

function agentEverywhere(string $name = 'Avery Agent'): User
{
    return User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => $name]);
}

/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

test('leads never called come first, newest first, then everything else, most overdue first', function () {
    $olderNew = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);
    $newerNew = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    $lessOverdue = dueLead($this->lakeview, ['attempts_made' => 1, 'due_at' => '2026-10-07 13:50:00'], ['received_at' => '2026-10-07 13:55:00']);
    $mostOverdue = dueLead($this->lakeview, ['attempts_made' => 2, 'due_at' => '2026-10-07 12:10:00']);
    // Put back for a second cycle: it has been called before, so it is not "never called".
    $restarted = dueLead($this->lakeview, ['cycle' => 2, 'attempts_made' => 0, 'due_at' => '2026-10-07 12:30:00']);

    $given = [];

    foreach (range(1, 5) as $number) {
        $given[] = nextLead(agentEverywhere("Agent {$number}"))->lead->id;
    }

    expect($given)->toBe([$newerNew->id, $olderNew->id, $mostOverdue->id, $restarted->id, $lessOverdue->id]);
    expect(nextLead(agentEverywhere('Agent 6'))->status)->toBe(QueueClaim::NOTHING_DUE);
});

test('two never-called leads received at the same moment go by the newest record', function () {
    $first = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);
    $second = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    expect(nextLead(agentEverywhere())->lead->id)->toBe($second->id);
    expect(nextLead(agentEverywhere('Blake Agent'))->lead->id)->toBe($first->id);
});

/*
|--------------------------------------------------------------------------
| Only due Leads, only inside the Lead's Location's calling hours
|--------------------------------------------------------------------------
*/

test('only a lead that is due is offered', function () {
    $agent = agentEverywhere();
    $later = dueLead($this->lakeview, ['due_at' => '2026-10-07 14:00:01']);

    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);

    $this->travelTo('2026-10-07 14:00:01');

    expect(nextLead($agent)->lead->id)->toBe($later->id);
});

test('a lead is never offered outside its location\'s calling hours, even overdue from yesterday', function () {
    // 12:30 UTC: 8:30 am Eastern, 5:30 am Pacific.
    $this->travelTo('2026-10-07 12:30:00');

    $portland = Location::factory()->create(['timezone' => 'America/Los_Angeles']);
    $agent = agentEverywhere();
    $overdue = dueLead($portland, ['attempts_made' => 3, 'due_at' => '2026-10-06 16:00:00']);

    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(queueHistory($overdue))->toBe(['entered']);

    $eastern = dueLead($this->lakeview);

    expect(nextLead($agent)->lead->id)->toBe($eastern->id);

    // 8:00 am in Portland: its stored due time has not moved, and now it is offered.
    $this->travelTo('2026-10-07 15:00:00');

    $later = [nextLead(agentEverywhere('Blake Agent'))->lead->id, nextLead(agentEverywhere('Casey Agent'))->lead->id];

    // The never-called Eastern Lead, free again, still comes first; then the overdue one.
    expect($later)->toBe([$eastern->id, $overdue->id]);
    expect(LeadCadence::query()->where('lead_id', $overdue->id)->sole()->due_at->toDateTimeString())->toBe('2026-10-06 16:00:00');
});

test('nothing is offered on a day that is not a calling day, or after closing', function () {
    $agent = agentEverywhere();
    dueLead($this->lakeview);

    // Wednesday unticked.
    saveQueueSettings(['calling_days' => [1, 2, 4, 5, 6, 7]]);
    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);

    saveQueueSettings(['calling_days' => [1, 2, 3, 4, 5, 6, 7]]);

    // 9:00 pm Eastern exactly: closed.
    $this->travelTo('2026-10-08 01:00:00');
    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);

    // 8:59 pm: open.
    $this->travelTo('2026-10-08 00:59:00');
    expect(nextLead($agent)->given())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The hold
|--------------------------------------------------------------------------
*/

test('a claim is a timed hold with its own token, and never an assignment', function () {
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();
    $agent = agentEverywhere();
    $lead = Lead::factory()->at($this->lakeview)->assignedTo($consultant)->working()->create();
    enterQueue($lead);
    $row = (array) DB::table('leads')->where('id', $lead->id)->first();

    $claim = nextLead($agent);
    $cadence = $claim->cadence->fresh();

    expect($claim->status)->toBe(QueueClaim::GIVEN);
    expect($claim->alreadyHeld)->toBeFalse();
    expect($claim->lead->id)->toBe($lead->id);
    expect($cadence->held_by_user_id)->toBe($agent->id);
    expect(Str::isUuid($cadence->hold_token))->toBeTrue();
    expect($cadence->held_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    // Five minutes by default.
    expect($cadence->held_until->toDateTimeString())->toBe('2026-10-07 14:05:00');
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 14:00:00');

    // The Lead's own row, with its assignee, status and revision, is untouched.
    expect((array) DB::table('leads')->where('id', $lead->id)->first())->toBe($row);
    expect($lead->fresh()->assigned_user_id)->toBe($consultant->id);

    $event = LeadQueueEvent::query()->where('type', QueueEventType::Claimed)->sole();

    expect($event->actor_id)->toBe($agent->id);
    expect($event->hold_token)->toBe($cadence->hold_token);
    expect($event->step)->toBe(1);
    expect($event->location_id)->toBe($this->lakeview->id);
});

test('the hold lasts as long as the hold length in force when it is taken', function () {
    saveQueueSettings(['hold_minutes' => 12]);
    dueLead($this->lakeview);

    expect(nextLead(agentEverywhere())->cadence->fresh()->held_until->toDateTimeString())->toBe('2026-10-07 14:12:00');
});

test('pressing next lead twice returns the same lead and takes no second hold', function () {
    $agent = agentEverywhere();
    $first = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    $one = nextLead($agent);

    $this->travelTo('2026-10-07 14:02:00');
    $two = nextLead($agent);

    expect($one->lead->id)->toBe($first->id);
    expect($two->lead->id)->toBe($first->id);
    expect($two->alreadyHeld)->toBeTrue();
    expect($two->cadence->hold_token)->toBe($one->cadence->hold_token);
    // The hold is not extended by asking again.
    expect($two->cadence->held_until->toDateTimeString())->toBe('2026-10-07 14:05:00');
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(1);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Claimed)->count())->toBe(1);
});

test('a second caller issued the same candidate gets zero rows and moves on', function () {
    $first = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    $second = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    $avery = agentEverywhere();
    $blake = agentEverywhere('Blake Agent');
    $seen = null;
    $ran = false;

    // Avery has read the candidates; before she tries them, Blake takes the first.
    $claim = app(ClaimNextLead::class)->afterCandidatesRead(function ($candidates) use ($blake, &$seen, &$ran): void {
        if (! $ran) {
            $ran = true;
            $seen = $candidates->pluck('lead_id')->all();
            nextLead($blake);
        }
    })->handle($avery);

    expect($seen)->toBe([$first->id, $second->id]);
    expect($claim->lead->id)->toBe($second->id);
    expect(LeadCadence::query()->where('lead_id', $first->id)->sole()->held_by_user_id)->toBe($blake->id);
    expect(LeadCadence::query()->where('lead_id', $second->id)->sole()->held_by_user_id)->toBe($avery->id);
    expect(queueHistory($first))->toBe(['entered', 'claimed']);
    expect(queueHistory($second))->toBe(['entered', 'claimed']);
});

test('a caller\'s own expired hold is released, with no timer having run', function () {
    $agent = agentEverywhere();
    $lead = dueLead($this->lakeview);

    $first = nextLead($agent);

    // Six minutes later the hold has run out by its own stored time.
    $this->travelTo('2026-10-07 14:06:00');
    $second = nextLead($agent);

    expect($second->lead->id)->toBe($lead->id);
    expect($second->alreadyHeld)->toBeFalse();
    expect($second->cadence->hold_token)->not->toBe($first->cadence->hold_token);
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_expired', 'claimed']);

    $expired = LeadQueueEvent::query()->where('type', QueueEventType::HoldExpired)->sole();

    expect($expired->affected_user_id)->toBe($agent->id);
    expect($expired->hold_token)->toBe($first->cadence->hold_token);
    expect(SchedulerHeartbeat::query()->count())->toBe(0);
});

test('another person\'s expired hold is taken over, and the history says whose it was', function () {
    $avery = agentEverywhere();
    $blake = agentEverywhere('Blake Agent');
    $lead = dueLead($this->lakeview);

    $first = nextLead($avery);

    // While the hold runs, nobody else is given the Lead.
    $this->travelTo('2026-10-07 14:04:59');
    expect(nextLead($blake)->status)->toBe(QueueClaim::NOTHING_DUE);

    // At the stored instant it ends, it is free.
    $this->travelTo('2026-10-07 14:05:00');
    $second = nextLead($blake);

    expect($second->lead->id)->toBe($lead->id);
    expect($second->cadence->fresh()->held_by_user_id)->toBe($blake->id);
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_expired', 'claimed']);

    $expired = LeadQueueEvent::query()->where('type', QueueEventType::HoldExpired)->sole();

    expect($expired->affected_user_id)->toBe($avery->id);
    expect($expired->hold_token)->toBe($first->cadence->hold_token);
    expect($expired->actor_id)->toBe($blake->id);
});

test('the database refuses a second hold for one person', function () {
    $agent = agentEverywhere();
    dueLead($this->lakeview);
    dueLead($this->lakeview);

    nextLead($agent);

    expect(fn () => DB::table('lead_cadences')->whereNull('held_by_user_id')->update(['held_by_user_id' => $agent->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    // Any number of Leads may be held by nobody.
    expect(LeadCadence::query()->whereNull('held_by_user_id')->count())->toBe(1);
});

test('a claim racing in another tab is answered with the lead already held', function () {
    $agent = agentEverywhere();
    $first = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    $second = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);
    $ran = false;

    // This tab found no hold and read the candidates; the other tab then takes the first Lead.
    $claim = app(ClaimNextLead::class)->afterCandidatesRead(function () use ($agent, &$ran): void {
        if (! $ran) {
            $ran = true;
            app()->make(ClaimNextLead::class)->handle($agent);
        }
    })->handle($agent);

    expect($claim->given())->toBeTrue();
    expect($claim->alreadyHeld)->toBeTrue();
    expect($claim->lead->id)->toBe($first->id);
    expect(LeadCadence::query()->where('held_by_user_id', $agent->id)->count())->toBe(1);
    expect(LeadCadence::query()->where('lead_id', $second->id)->sole()->held_by_user_id)->toBeNull();
    expect(queueHistory($second))->toBe(['entered']);
});

/*
|--------------------------------------------------------------------------
| Who is offered a Lead
|--------------------------------------------------------------------------
*/

test('a lead at a location the setter has no access to is never offered', function () {
    $riverside = Location::factory()->create();
    $agent = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create();

    $elsewhere = dueLead($riverside);

    expect(nextLead($agent)->status)->toBe(QueueClaim::NOTHING_DUE);

    $here = dueLead($this->lakeview);

    expect(nextLead($agent)->lead->id)->toBe($here->id);
    expect(queueHistory($elsewhere))->toBe(['entered']);
});

test('an unrouted lead is offered only to company-wide lead staff', function (Closure $staff, bool $offered) {
    $unrouted = dueLead();
    $person = $staff($this->lakeview);

    $claim = nextLead($person);

    expect($claim->given())->toBe($offered);
    expect(LeadCadence::query()->where('lead_id', $unrouted->id)->sole()->held_by_user_id)->toBe($offered ? $person->id : null);
})->with([
    'an owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'leadership everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'a call center agent everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), true],
    'a call center agent at one location' => [fn (Location $location) => User::factory()->withRoleAt(Role::CallCenterAgent, $location)->create(), false],
    'leadership at one location' => [fn (Location $location) => User::factory()->withRoleAt(Role::Leadership, $location)->create(), false],
    'a location manager, even everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::LocationManager)->create(), false],
]);

test('the four queue roles are offered a lead at their location', function (Role $role) {
    $lead = dueLead($this->lakeview);
    $person = $role->requiresAllLocations()
        ? User::factory()->withRoleEverywhere($role)->create()
        : User::factory()->withRoleAt($role, $this->lakeview)->create();

    expect(nextLead($person)->lead->id)->toBe($lead->id);
})->with([Role::Owner, Role::Leadership, Role::LocationManager, Role::CallCenterAgent]);

test('roles without the queue permission are refused and nothing is held', function (Role $role) {
    dueLead($this->lakeview);
    dueLead();

    $person = User::factory()->withRoleEverywhere($role)->create();

    expect(fn () => nextLead($person))->toThrow(AuthorizationException::class);
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Claimed)->count())->toBe(0);
})->with([Role::SalesConsultant, Role::ProductionManager, Role::Finance]);

test('a person with no grants is refused', function () {
    dueLead($this->lakeview);

    expect(fn () => nextLead(User::factory()->create()))->toThrow(AuthorizationException::class);
});

test('two grants do not combine into access neither gives', function () {
    $riverside = Location::factory()->create();

    // The queue at Lakeview only; leads, but no queue, at Riverside.
    $person = User::factory()
        ->withRoleAt(Role::CallCenterAgent, $this->lakeview)
        ->withRoleAt(Role::SalesConsultant, $riverside)
        ->create();

    $atRiverside = dueLead($riverside);

    expect(nextLead($person)->status)->toBe(QueueClaim::NOTHING_DUE);

    // The queue everywhere without unrouted leads, plus unrouted-capable access at one location only.
    $manager = User::factory()
        ->withRoleEverywhere(Role::LocationManager)
        ->withRoleAt(Role::CallCenterAgent, $this->lakeview)
        ->create();

    $unrouted = dueLead();

    expect(nextLead($manager)->lead->id)->toBe($atRiverside->id);
    expect(LeadCadence::query()->where('lead_id', $unrouted->id)->sole()->held_by_user_id)->toBeNull();
});

test('a deactivated location\'s leads are offered to nobody', function () {
    $closed = Location::factory()->inactive()->create();
    $lead = dueLead($closed);

    expect(nextLead(agentEverywhere())->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(nextLead(User::factory()->withRoleEverywhere(Role::Owner)->create())->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(queueHistory($lead))->toBe(['entered']);
});

test('a deactivated person is refused', function () {
    dueLead($this->lakeview);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->inactive()->create();

    expect(fn () => nextLead($agent))->toThrow(AuthorizationException::class);
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);
});

test('a stale copy of the caller does not carry access they no longer have', function () {
    dueLead($this->lakeview);

    $agent = agentEverywhere();
    $agent->load('accessGrants');

    DB::table('access_grants')->where('user_id', $agent->id)->delete();

    expect(fn () => nextLead($agent))->toThrow(AuthorizationException::class);
});

test('while an owner impersonates a setter the queue uses that setter\'s access alone', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $agent = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create(['name' => 'Roseanne Smith']);
    $riverside = Location::factory()->create();

    $unrouted = dueLead(attributes: ['received_at' => '2026-10-07 13:50:00']);
    $elsewhere = dueLead($riverside, attributes: ['received_at' => '2026-10-07 13:40:00']);
    $here = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    app(Impersonation::class)->serveAs($owner, $agent);

    try {
        $claim = nextLead($agent);
        $again = nextLead($agent);
    } finally {
        app(Impersonation::class)->forgetRequest();
    }

    // The Owner could be given all three; the setter only the one at Lakeview.
    expect($claim->lead->id)->toBe($here->id);
    expect($again->lead->id)->toBe($here->id);
    expect(queueHistory($unrouted))->toBe(['entered']);
    expect(queueHistory($elsewhere))->toBe(['entered']);

    $event = LeadQueueEvent::query()->where('type', QueueEventType::Claimed)->sole();

    expect($event->actor_name)->toBe('Roseanne Smith');
    expect($event->impersonator_id)->toBe($owner->id);
    expect($event->impersonator_name)->toBe('Jacob Ransom');
});

/*
|--------------------------------------------------------------------------
| A busy database
|--------------------------------------------------------------------------
*/

test('a deadlock or a busy database is retried three times and then passed on', function () {
    Sleep::fake();

    $calls = 0;
    $busy = fn () => throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');

    $result = app(QueueTransaction::class)->run(function () use (&$calls, $busy): string {
        return ++$calls < 3 ? $busy() : 'done';
    });

    expect($result)->toBe('done');
    expect($calls)->toBe(3);

    $calls = 0;

    expect(function () use (&$calls, $busy): void {
        app(QueueTransaction::class)->run(function () use (&$calls, $busy): never {
            $calls++;
            $busy();
        });
    })->toThrow(PDOException::class);

    // One try and three retries.
    expect($calls)->toBe(4);

    // Anything else is not retried.
    $calls = 0;

    expect(function () use (&$calls): void {
        app(QueueTransaction::class)->run(function () use (&$calls): never {
            $calls++;

            throw new RuntimeException('Something else.');
        });
    })->toThrow(RuntimeException::class);

    expect($calls)->toBe(1);
});

test('when the database stays busy the caller is told to try again and nothing is held', function () {
    Sleep::fake();

    $agent = agentEverywhere();
    dueLead($this->lakeview);

    $this->partialMock(QueueTransaction::class)
        ->shouldReceive('run')
        ->andThrow(new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));

    $claim = nextLead($agent);

    expect($claim->status)->toBe(QueueClaim::TRY_AGAIN);
    expect($claim->lead)->toBeNull();
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| After an outage
|--------------------------------------------------------------------------
*/

test('after a day with no timer every overdue lead is offered once, in order, and yesterday\'s holds are free', function () {
    $agents = collect(range(1, 6))->map(fn (int $number): User => agentEverywhere("Agent {$number}"));

    $neverCalledOld = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);
    $neverCalledNew = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    $followUpA = dueLead($this->lakeview, ['attempts_made' => 4, 'due_at' => '2026-10-07 16:40:00']);
    $followUpB = dueLead($this->lakeview, ['attempts_made' => 2, 'due_at' => '2026-10-07 14:30:00']);
    $unrouted = dueLead(cadence: ['attempts_made' => 1, 'due_at' => '2026-10-07 20:00:00']);

    // Two were held when the server went down.
    $heldYesterday = [nextLead($agents[0])->lead->id, nextLead($agents[1])->lead->id];
    expect($heldYesterday)->toBe([$neverCalledNew->id, $neverCalledOld->id]);

    // A day later, same time of day. No tick ran in between.
    $this->travelTo('2026-10-08 14:00:00');

    $given = $agents->map(fn (User $agent): ?int => nextLead($agent)->lead?->id)->all();

    expect($given)->toBe([$neverCalledNew->id, $neverCalledOld->id, $followUpB->id, $followUpA->id, $unrouted->id, null]);
    expect(array_unique(array_filter($given)))->toHaveCount(5);

    // Every Lead has exactly one holder, nobody holds two, and nothing was lost.
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(5);
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->distinct()->count('held_by_user_id'))->toBe(5);

    // Yesterday's two holds were taken over, each recorded as expired.
    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldExpired)->pluck('lead_id')->sort()->values()->all())
        ->toBe(collect($heldYesterday)->sort()->values()->all());
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Claimed)->count())->toBe(7);

    // No due time was recalculated and no attempt was invented while the server was away.
    expect(LeadCadence::query()->where('lead_id', $followUpA->id)->sole()->due_at->toDateTimeString())->toBe('2026-10-07 16:40:00');
    expect(LeadCadence::query()->where('lead_id', $followUpA->id)->sole()->attempts_made)->toBe(4);
    expect(SchedulerHeartbeat::query()->count())->toBe(0);
});
