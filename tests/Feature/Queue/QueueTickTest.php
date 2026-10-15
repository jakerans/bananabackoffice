<?php

use App\Actions\Queue\EndQueueHold;
use App\Actions\Queue\RunQueueTick;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\QueueEventType;
use App\Enums\Role;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * The every-minute tick: a heartbeat and a safety net, never the engine.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create();
    $this->avery = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create(['name' => 'Avery Agent']);
    $this->blake = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create(['name' => 'Blake Agent']);
});

test('the scheduler runs the queue tick every minute, inline, with a five-minute overlap lock', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'backoffice:queue-tick'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('* * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->expiresAt)->toBe(5);
    expect($event->runInBackground)->toBeFalse();
    expect($event->onOneServer)->toBeFalse();

    Artisan::call('schedule:list');

    // The listing pads its columns to the longest schedule, so the spacing varies.
    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan backoffice:queue-tick/');
});

test('the command writes the heartbeat and succeeds with nothing to do', function () {
    $this->artisan('backoffice:queue-tick')
        ->expectsOutputToContain('0 expired holds released, 0 cadences ended, 0 holds ended for lost access, 0 failures')
        ->assertSuccessful();

    $heartbeat = SchedulerHeartbeat::query()->sole();

    expect($heartbeat->name)->toBe('queue_tick');
    expect($heartbeat->last_ran_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect(SchedulerHeartbeat::lastRanAt(SchedulerHeartbeat::QUEUE_TICK)->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect(SchedulerHeartbeat::lastRanAt('something_else'))->toBeNull();
});

test('the heartbeat is one row that moves forward', function () {
    app(RunQueueTick::class)->handle();

    $this->travelTo('2026-10-07 14:01:00');
    app(RunQueueTick::class)->handle();

    expect(SchedulerHeartbeat::query()->count())->toBe(1);
    expect(SchedulerHeartbeat::query()->sole()->last_ran_at->toDateTimeString())->toBe('2026-10-07 14:01:00');
});

test('it releases holds whose time has run out and leaves running holds alone', function () {
    $first = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    $second = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    $averys = nextLead($this->avery);

    $this->travelTo('2026-10-07 14:03:00');
    nextLead($this->blake);

    // 14:05: Avery's hold has run out; Blake's has three minutes left.
    $this->travelTo('2026-10-07 14:05:00');
    $done = app(RunQueueTick::class)->handle();

    expect($done)->toBe(['expired' => 1, 'ended' => 0, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 0]);
    expect(LeadCadence::query()->where('lead_id', $first->id)->sole()->held_by_user_id)->toBeNull();
    expect(LeadCadence::query()->where('lead_id', $second->id)->sole()->held_by_user_id)->toBe($this->blake->id);
    expect(queueHistory($first))->toBe(['entered', 'claimed', 'hold_expired']);
    expect(queueHistory($second))->toBe(['entered', 'claimed']);

    $expired = LeadQueueEvent::query()->where('type', QueueEventType::HoldExpired)->sole();

    expect($expired->affected_user_id)->toBe($this->avery->id);
    expect($expired->hold_token)->toBe($averys->cadence->hold_token);
    // The timer has no actor.
    expect($expired->actor_id)->toBeNull();
    expect($expired->actor_name)->toBeNull();
    expect($expired->impersonator_id)->toBeNull();
});

test('the timer is recorded with no actor even when someone is signed in', function () {
    dueLead($this->lakeview);
    nextLead($this->avery);

    $this->travelTo('2026-10-07 14:06:00');
    $this->actingAs($this->blake);
    app(RunQueueTick::class)->handle();

    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldExpired)->sole()->actor_id)->toBeNull();
});

test('it repairs a cadence left in the queue for a lead that is disqualified or in deferred follow-up', function () {
    $disqualified = dueLead($this->lakeview);
    $deferred = dueLead($this->lakeview);
    $healthy = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 12:00:00']);

    // Held, and then broken on purpose: the status changed without the action that ends the cadence.
    $held = nextLead($this->avery);
    DB::table('leads')->where('id', $disqualified->id)->update(['status' => LeadStatus::Disqualified->value]);
    DB::table('leads')->where('id', $deferred->id)->update(['status' => LeadStatus::Nurture->value, 'follow_up_on' => '2026-10-20']);

    $done = app(RunQueueTick::class)->handle();

    expect($done)->toBe(['expired' => 0, 'ended' => 2, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 0]);

    expect(LeadCadence::query()->where('lead_id', $disqualified->id)->sole()->state)->toBe(CadenceState::Disqualified);
    expect(LeadCadence::query()->where('lead_id', $deferred->id)->sole()->state)->toBe(CadenceState::DeferredFollowUp);
    expect(LeadCadence::query()->where('lead_id', $healthy->id)->sole()->state)->toBe(CadenceState::InQueue);
    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);

    $heldLead = $held->lead->id === $deferred->id ? $deferred : $disqualified;
    $reason = $held->lead->id === $deferred->id ? 'deferred_follow_up' : 'disqualified';
    $other = $held->lead->id === $deferred->id ? $disqualified : $deferred;
    $otherReason = $held->lead->id === $deferred->id ? 'disqualified' : 'deferred_follow_up';

    expect(queueHistory($heldLead))->toBe(['entered', 'claimed', "hold_ended:{$reason}", "ended:{$reason}"]);
    expect(queueHistory($other))->toBe(['entered', "ended:{$otherReason}"]);
    expect(queueHistory($healthy))->toBe(['entered']);
    expect(LeadQueueEvent::query()->whereIn('type', [QueueEventType::HoldEnded, QueueEventType::Ended])->whereNotNull('actor_id')->count())->toBe(0);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->sole()->affected_user_id)->toBe($this->avery->id);
});

test('it ends a hold by someone who may no longer work that lead', function (string $broken) {
    $lead = dueLead($this->lakeview);
    $other = dueLead(Location::factory()->create());
    $company = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    nextLead($this->avery);
    nextLead($company);

    // Broken on purpose, without the action that would have ended the hold.
    match ($broken) {
        'the grant removed' => DB::table('access_grants')->where('user_id', $this->avery->id)->delete(),
        'the person deactivated' => DB::table('users')->where('id', $this->avery->id)->update(['is_active' => false]),
        'the location deactivated' => DB::table('locations')->where('id', $this->lakeview->id)->update(['is_active' => false]),
        'the lead moved away' => DB::table('leads')->where('id', $lead->id)->update(['location_id' => Location::factory()->create()->id]),
    };

    $done = app(RunQueueTick::class)->handle();

    expect($done)->toBe(['expired' => 0, 'ended' => 0, 'access_lost' => 1, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 0]);
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->sole()->affected_user_id)->toBe($this->avery->id);
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->state)->toBe(CadenceState::InQueue);

    // A hold by someone who may still work their Lead is left alone.
    expect(LeadCadence::query()->where('lead_id', $other->id)->sole()->held_by_user_id)->toBe($company->id);
})->with(['the grant removed', 'the person deactivated', 'the location deactivated', 'the lead moved away']);

test('a hold is not ended just because calling hours have closed: that waits for its own time', function () {
    // Taken at 8:58 pm Eastern.
    $this->travelTo('2026-10-08 00:58:00');
    $lead = dueLead($this->lakeview);
    nextLead($this->avery);

    $this->travelTo('2026-10-08 01:01:00');
    app(RunQueueTick::class)->handle();

    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->held_by_user_id)->toBe($this->avery->id);
});

test('running it twice is the same as running it once', function () {
    $expired = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    $broken = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    nextLead($this->avery);
    DB::table('leads')->where('id', $broken->id)->update(['status' => LeadStatus::Disqualified->value]);

    $this->travelTo('2026-10-07 14:06:00');

    $first = app(RunQueueTick::class)->handle();

    $cadences = DB::table('lead_cadences')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $events = LeadQueueEvent::query()->count();

    $second = app(RunQueueTick::class)->handle();

    expect($first)->toBe(['expired' => 1, 'ended' => 1, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 0]);
    expect($second)->toBe(['expired' => 0, 'ended' => 0, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 0]);
    expect(DB::table('lead_cadences')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($cadences);
    expect(LeadQueueEvent::query()->count())->toBe($events);
    expect(SchedulerHeartbeat::query()->count())->toBe(1);
    expect(queueHistory($expired))->toBe(['entered', 'claimed', 'hold_expired']);
});

test('one lead that throws does not stop the others, and the failure is logged without customer details', function () {
    $first = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00', 'inquiry_name' => 'Maria Lopez', 'inquiry_phone' => '555-201-3344']);
    $second = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:00:00']);

    nextLead($this->avery);
    nextLead($this->blake);

    $failing = LeadCadence::query()->where('lead_id', $first->id)->sole();

    Log::spy();

    // Releasing the first hold fails; everything else is real.
    $real = app(EndQueueHold::class);
    $this->mock(EndQueueHold::class)
        ->shouldReceive('handle')
        ->andReturnUsing(function (LeadCadence $cadence, ...$rest) use ($real, $failing): bool {
            if ($cadence->id === $failing->id) {
                throw new RuntimeException('Could not release the hold on Maria Lopez, 555-201-3344.');
            }

            return $real->handle($cadence, ...$rest);
        });

    $this->travelTo('2026-10-07 14:06:00');
    $done = app(RunQueueTick::class)->handle();

    expect($done)->toBe(['expired' => 1, 'ended' => 0, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 1]);
    expect($failing->fresh()->held_by_user_id)->toBe($this->avery->id);
    expect(LeadCadence::query()->where('lead_id', $second->id)->sole()->held_by_user_id)->toBeNull();
    expect(SchedulerHeartbeat::query()->count())->toBe(1);

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($failing): bool {
        $logged = $message.json_encode($context);

        return $context === ['part' => 'expired', 'lead_cadence_id' => $failing->id, 'exception' => RuntimeException::class]
            && ! str_contains($logged, 'Maria')
            && ! str_contains($logged, '555');
    });

    // The next tick tries again and, with nothing failing, finishes the job.
    $this->forgetMock(EndQueueHold::class);
    app()->forgetInstance(RunQueueTick::class);

    expect(app(RunQueueTick::class)->handle())->toBe(['expired' => 1, 'ended' => 0, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 0]);
    expect($failing->fresh()->held_by_user_id)->toBeNull();
});

test('a tick that cannot write its heartbeat still tidies up', function () {
    $lead = dueLead($this->lakeview);
    nextLead($this->avery);

    Log::spy();
    SchedulerHeartbeat::creating(fn () => throw new RuntimeException('The heartbeat could not be written.'));

    try {
        $this->travelTo('2026-10-07 14:06:00');
        $done = app(RunQueueTick::class)->handle();
    } finally {
        SchedulerHeartbeat::flushEventListeners();
    }

    expect($done)->toBe(['expired' => 1, 'ended' => 0, 'access_lost' => 0, 'warned' => 0, 'released' => 0, 'reminded' => 0, 'handoffs_reminded' => 0, 'failed' => 1]);
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_expired']);
});

test('it never schedules an attempt, never finishes a cadence and never contacts anyone', function () {
    Mail::fake();
    Notification::fake();

    // Overdue by days, never called, and one attempt from the end of its cadence.
    $overdue = dueLead($this->lakeview, ['due_at' => '2026-10-01 14:00:00']);
    $nearlyDone = dueLead($this->lakeview, ['attempts_made' => 11, 'due_at' => '2026-10-02 14:00:00', 'last_counted_attempt_at' => '2026-10-01 18:30:00']);
    // A cadence that has had more than the cadence now holds.
    $beyond = dueLead($this->lakeview, ['attempts_made' => 14, 'due_at' => '2026-10-02 14:00:00']);

    $before = DB::table('lead_cadences')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $leads = DB::table('leads')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    foreach (range(1, 3) as $minute) {
        $this->travelTo(now()->addMinute());
        app(RunQueueTick::class)->handle();
    }

    expect(DB::table('lead_cadences')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    expect(DB::table('leads')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($leads);
    expect(ContactAttempt::query()->count())->toBe(0);
    expect(LeadQueueEvent::query()->whereNotIn('type', [QueueEventType::Entered])->count())->toBe(0);
    expect(LeadCadence::query()->where('state', CadenceState::FinishedNoContact)->count())->toBe(0);
    expect(Lead::query()->whereIn('id', [$overdue->id, $nearlyDone->id, $beyond->id])->count())->toBe(3);

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});
