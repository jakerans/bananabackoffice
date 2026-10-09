<?php

use App\Actions\Queue\SkipHeldLead;
use App\Enums\QueueEventType;
use App\Enums\QueueSkipReason;
use App\Enums\Role;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\Impersonation;
use App\Support\Queue\QueueClaim;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Skipping a held Lead (decision 70): a reason is needed, the Lead goes back
 * to everyone else at once, and it is kept away from the person who skipped
 * it for one hold length. The cooldown is per Lead and per setter.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create();
    $this->avery = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Avery Agent']);
    $this->blake = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Blake Agent']);
    $this->lead = dueLead($this->lakeview);
});

function skip(User $setter, QueueClaim $claim, QueueSkipReason|string|null $reason = QueueSkipReason::CannotCallNow, ?string $note = null): void
{
    app(SkipHeldLead::class)->handle($setter, $claim->cadence->id, $claim->cadence->hold_token, $reason, $note);
}

test('the four skip reasons are fixed in code', function () {
    expect(collect(QueueSkipReason::cases())->mapWithKeys(fn (QueueSkipReason $reason) => [$reason->value => $reason->label()])->all())->toBe([
        'no_usable_number' => 'No usable phone number',
        'needs_manager' => 'Needs a manager to look at it',
        'cannot_call_now' => 'I cannot make this call right now',
        'other' => 'Other',
    ]);
});

test('a skip releases the hold, leaves the due time alone and records the reason', function () {
    $claim = nextLead($this->avery);
    $dueAt = $claim->cadence->due_at->toDateTimeString();
    $row = (array) DB::table('leads')->where('id', $this->lead->id)->first();

    $this->travelTo('2026-10-07 14:02:00');
    skip($this->avery, $claim, QueueSkipReason::NeedsManager, '  The number looks like a fax line.  ');

    $cadence = $claim->cadence->fresh();

    expect($cadence->held_by_user_id)->toBeNull();
    expect($cadence->hold_token)->toBeNull();
    expect($cadence->held_until)->toBeNull();
    expect($cadence->due_at->toDateTimeString())->toBe($dueAt);
    expect($cadence->attempts_made)->toBe(0);
    expect($cadence->isInQueue())->toBeTrue();

    $event = LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->sole();

    expect($event->reason)->toBe('needs_manager');
    expect($event->note)->toBe('The number looks like a fax line.');
    expect($event->actor_id)->toBe($this->avery->id);
    expect($event->affected_user_id)->toBe($this->avery->id);
    expect($event->hold_token)->toBe($claim->cadence->hold_token);
    // One hold length after the skip.
    expect($event->cooldown_until->toDateTimeString())->toBe('2026-10-07 14:07:00');
    expect($event->step)->toBe(1);

    expect((array) DB::table('leads')->where('id', $this->lead->id)->first())->toBe($row);
});

test('a reason is required, and it must be one of the four', function (mixed $reason) {
    $claim = nextLead($this->avery);

    expect(fn () => skip($this->avery, $claim, $reason))->toThrow(ValidationException::class, 'Choose why you are skipping this lead.');

    expect($claim->cadence->fresh()->held_by_user_id)->toBe($this->avery->id);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->count())->toBe(0);
})->with([null, '', 'too_busy']);

test('the "other" reason needs a note, and the rest do not', function () {
    $claim = nextLead($this->avery);

    expect(fn () => skip($this->avery, $claim, QueueSkipReason::Other))->toThrow(ValidationException::class, 'Say briefly why you are skipping this lead.');
    expect(fn () => skip($this->avery, $claim, 'other', '   '))->toThrow(ValidationException::class);
    expect($claim->cadence->fresh()->held_by_user_id)->toBe($this->avery->id);

    skip($this->avery, $claim, 'other', 'They asked us to stop calling this week.');

    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->sole()->note)->toBe('They asked us to stop calling this week.');
});

test('a skipped lead is not offered back to the skipper within one hold length, but to someone else at once', function () {
    skip($this->avery, nextLead($this->avery));

    // Straight away: not to Avery, but to Blake.
    expect(nextLead($this->avery)->status)->toBe(QueueClaim::NOTHING_DUE);

    $blakes = nextLead($this->blake);
    expect($blakes->lead->id)->toBe($this->lead->id);

    // Blake logs a call, so the Lead is free; one second before Avery's cooldown ends she still is not offered it.
    logAttempt($this->lead, $this->blake);
    DB::table('lead_cadences')->where('lead_id', $this->lead->id)->update(['due_at' => now()]);

    $this->travelTo('2026-10-07 14:04:59');
    expect(nextLead($this->avery)->status)->toBe(QueueClaim::NOTHING_DUE);

    // At the stored instant the cooldown ends, she is.
    $this->travelTo('2026-10-07 14:05:00');
    expect(nextLead($this->avery)->lead->id)->toBe($this->lead->id);
});

test('the skipper is given a different due lead while the cooldown runs', function () {
    $other = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    $first = nextLead($this->avery);
    expect($first->lead->id)->toBe($this->lead->id);

    skip($this->avery, $first);

    expect(nextLead($this->avery)->lead->id)->toBe($other->id);
});

test('only the person holding the lead now, under that hold, can skip it', function () {
    $claim = nextLead($this->avery);

    // Someone else.
    expect(fn () => skip($this->blake, $claim))->toThrow(ValidationException::class, 'You are no longer holding this lead');

    // A token from another hold.
    expect(fn () => app(SkipHeldLead::class)->handle($this->avery, $claim->cadence->id, (string) Str::uuid(), QueueSkipReason::CannotCallNow))
        ->toThrow(ValidationException::class);

    // A cadence that does not exist.
    expect(fn () => app(SkipHeldLead::class)->handle($this->avery, 999999, $claim->cadence->hold_token, QueueSkipReason::CannotCallNow))
        ->toThrow(ValidationException::class);

    expect($claim->cadence->fresh()->held_by_user_id)->toBe($this->avery->id);

    // After the hold has run out.
    $this->travelTo('2026-10-07 14:05:00');
    expect(fn () => skip($this->avery, $claim))->toThrow(ValidationException::class);

    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->count())->toBe(0);
});

test('a stale card cannot skip a lead its holder may no longer work', function () {
    $claim = nextLead($this->avery);

    // Access removed directly, leaving the hold in place.
    DB::table('access_grants')->where('user_id', $this->avery->id)->delete();

    expect(fn () => skip($this->avery, $claim))->toThrow(AuthorizationException::class);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->count())->toBe(0);
});

test('a second skip of the same hold changes nothing', function () {
    $claim = nextLead($this->avery);

    skip($this->avery, $claim);

    expect(fn () => skip($this->avery, $claim))->toThrow(ValidationException::class);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->count())->toBe(1);
});

test('skipping carries no penalty: nothing counts skips anywhere', function () {
    foreach (['users', 'leads', 'lead_cadences', 'lead_queue_events', 'contact_attempts'] as $table) {
        $suspect = collect(Schema::getColumnListing($table))->filter(fn (string $column): bool => preg_match('/skip|penalt|strike|score|demerit/i', $column) === 1);

        expect($suspect->all())->toBe([], "[{$table}] has a column that looks like a skip counter or penalty.");
    }

    $userBefore = (array) DB::table('users')->where('id', $this->avery->id)->first();

    foreach (range(1, 3) as $round) {
        skip($this->avery, nextLead($this->avery));
        $this->travelTo(now()->addMinutes(6));
    }

    expect((array) DB::table('users')->where('id', $this->avery->id)->first())->toBe($userBefore);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->count())->toBe(3);
    // Still offered to the skipper once each cooldown has passed.
    expect(nextLead($this->avery)->lead->id)->toBe($this->lead->id);
});

/*
|--------------------------------------------------------------------------
| Two setters (regression)
|--------------------------------------------------------------------------
*/

test('one setter\'s skip never overwrites another\'s cooldown', function () {
    // 14:00 Avery is given the Lead and skips it.
    skip($this->avery, nextLead($this->avery), QueueSkipReason::NoUsableNumber);

    // 14:02 Blake is given the same Lead and skips it.
    $this->travelTo('2026-10-07 14:02:00');
    $blakes = nextLead($this->blake);
    expect($blakes->lead->id)->toBe($this->lead->id);
    skip($this->blake, $blakes, QueueSkipReason::NeedsManager);

    // 14:03 Inside both cooldowns: neither is given it. It is the only Lead, so nothing is due.
    $this->travelTo('2026-10-07 14:03:00');
    expect(nextLead($this->avery)->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(nextLead($this->blake)->status)->toBe(QueueClaim::NOTHING_DUE);

    // A third person is offered it at once, and gives it up without a skip.
    $casey = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    expect(nextLead($casey)->lead->id)->toBe($this->lead->id);
    DB::table('lead_cadences')->where('lead_id', $this->lead->id)->update(['held_by_user_id' => null, 'hold_token' => null, 'held_at' => null, 'held_until' => null]);

    // 14:05 Avery's own cooldown has passed; Blake's has two minutes to run.
    $this->travelTo('2026-10-07 14:05:00');
    expect(nextLead($this->blake)->status)->toBe(QueueClaim::NOTHING_DUE);

    $averys = nextLead($this->avery);
    expect($averys->lead->id)->toBe($this->lead->id);

    // Avery skips again, which must not shorten or lengthen Blake's cooldown.
    skip($this->avery, $averys);

    $this->travelTo('2026-10-07 14:06:59');
    expect(nextLead($this->blake)->status)->toBe(QueueClaim::NOTHING_DUE);

    // 14:07 Blake's cooldown has passed.
    $this->travelTo('2026-10-07 14:07:00');
    expect(nextLead($this->blake)->lead->id)->toBe($this->lead->id);

    // Every skip is its own event, each with its own setter and its own instant.
    $skips = LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->orderBy('id')->get()
        ->map(fn (LeadQueueEvent $event): array => [$event->affected_user_id, $event->reason, $event->cooldown_until->toDateTimeString()])
        ->all();

    expect($skips)->toBe([
        [$this->avery->id, 'no_usable_number', '2026-10-07 14:05:00'],
        [$this->blake->id, 'needs_manager', '2026-10-07 14:07:00'],
        [$this->avery->id, 'cannot_call_now', '2026-10-07 14:10:00'],
    ]);
});

test('when the skipped lead is not the only one each setter gets a different due lead', function () {
    $second = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);
    $third = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 12:00:00']);

    skip($this->avery, nextLead($this->avery));
    skip($this->blake, nextLead($this->blake));

    expect(nextLead($this->avery)->lead->id)->toBe($second->id);
    expect(nextLead($this->blake)->lead->id)->toBe($third->id);
    expect(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->held_by_user_id)->toBeNull();
});

test('a cooldown is a stored instant and does not move when the hold length changes', function () {
    skip($this->avery, nextLead($this->avery));

    $stored = LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->sole()->cooldown_until->toDateTimeString();
    expect($stored)->toBe('2026-10-07 14:05:00');

    // Lengthened: Avery is still offered the Lead at the stored instant, not later.
    saveQueueSettings(['hold_minutes' => 30]);

    expect(LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->sole()->cooldown_until->toDateTimeString())->toBe($stored);

    $this->travelTo('2026-10-07 14:04:59');
    expect(nextLead($this->avery)->status)->toBe(QueueClaim::NOTHING_DUE);

    $this->travelTo('2026-10-07 14:05:00');
    $claim = nextLead($this->avery);
    expect($claim->lead->id)->toBe($this->lead->id);

    // A skip made now uses the length in force now.
    skip($this->avery, $claim);
    saveQueueSettings(['hold_minutes' => 1]);

    $this->travelTo('2026-10-07 14:34:59');
    expect(nextLead($this->avery)->status)->toBe(QueueClaim::NOTHING_DUE);

    $this->travelTo('2026-10-07 14:35:00');
    expect(nextLead($this->avery)->lead->id)->toBe($this->lead->id);
});

test('a skip made while an owner impersonates a setter cools down that setter', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);

    app(Impersonation::class)->serveAs($owner, $this->avery);

    try {
        skip($this->avery, nextLead($this->avery));
    } finally {
        app(Impersonation::class)->forgetRequest();
    }

    $event = LeadQueueEvent::query()->where('type', QueueEventType::Skipped)->sole();

    expect($event->affected_user_id)->toBe($this->avery->id);
    expect($event->actor_id)->toBe($this->avery->id);
    expect($event->impersonator_id)->toBe($owner->id);
    expect($event->impersonator_name)->toBe('Jacob Ransom');

    // The setter is cooled down. The Owner, as themselves, is not.
    expect(nextLead($this->avery)->status)->toBe(QueueClaim::NOTHING_DUE);
    expect(nextLead($owner)->lead->id)->toBe($this->lead->id);
});
