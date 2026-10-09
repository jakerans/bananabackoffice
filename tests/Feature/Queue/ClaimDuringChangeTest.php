<?php

use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Locations\ChangeLocationTimezone;
use App\Actions\Queue\ClaimNextLead;
use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Enums\QueueSkipReason;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Queue\QueueClaim;
use App\Support\Queue\QueueHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * A change that lands while a claim is in progress, and a change that lands
 * while a Lead is held (docs/slice-4-plan.md, contract 5, "Locks").
 *
 * The first half uses ClaimNextLead's test-only seam: the callback runs after
 * the candidates are read and before they are tried, standing in for a change
 * made by someone else at exactly that moment. The Lead was due and eligible
 * when the candidates were read. Real processes race the same changes in
 * QueueConcurrencyTest.
 */

beforeEach(function () {
    // Wednesday 7 October 2026, 10:00 am Eastern: 4:00 am in Honolulu.
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->riverside = Location::factory()->create(['name' => 'Riverside']);
    $this->honolulu = Location::factory()->create(['name' => 'Honolulu', 'timezone' => 'Pacific/Honolulu']);

    $this->agent = User::factory()
        ->withRoleAt(Role::CallCenterAgent, $this->lakeview)
        ->withRoleAt(Role::CallCenterAgent, $this->riverside)
        ->withRoleAt(Role::CallCenterAgent, $this->honolulu)
        ->create(['name' => 'Avery Agent']);

    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);

    // The Lead every case is about: first in the queue.
    $this->lead = dueLead($this->lakeview, attributes: ['received_at' => '2026-10-07 13:30:00']);
    // The next eligible Lead, at another Location.
    $this->fallback = dueLead($this->riverside, attributes: ['received_at' => '2026-10-07 13:00:00']);
});

/**
 * Press "Next lead" with a change made after the candidates are read.
 */
function claimWhile(User $caller, Closure $change): QueueClaim
{
    $ran = false;

    return app(ClaimNextLead::class)->afterCandidatesRead(function ($candidates) use ($change, &$ran): void {
        if ($ran) {
            return;
        }

        $ran = true;

        // The Lead in question was read as the first candidate, before the change.
        expect($candidates->first()->lead_id)->toBe(test()->lead->id);

        $change();
    })->handle($caller);
}

function grantAt(User $user, Location $location): AccessGrant
{
    return AccessGrant::query()->where('user_id', $user->id)->where('location_id', $location->id)->sole();
}

test('a change during the claim that takes eligibility away: the lead is not returned and nothing is held on it', function (string $change, bool $fallbackGiven) {
    $claim = claimWhile($this->agent, fn () => match ($change) {
        'transferred to a location that is closed' => app(RouteLead::class)->handle($this->lead->fresh(), $this->honolulu, 'Wrong area.', $this->owner),
        'its location\'s timezone changed to one that is closed' => app(ChangeLocationTimezone::class)->handle($this->lakeview, 'Pacific/Honolulu'),
        'the hours narrowed to exclude this moment' => saveQueueSettings(['calling_opens_at' => 720]),
        'today unticked as a calling day' => saveQueueSettings(['calling_days' => [1, 2, 4, 5, 6, 7]]),
        'the caller\'s grant there revoked' => app(RevokeAccess::class)->handle(grantAt($this->agent, $this->lakeview)),
        'the caller\'s role there left without the queue permission' => DB::table('access_grants')->where('id', grantAt($this->agent, $this->lakeview)->id)->update(['role' => Role::SalesConsultant->value]),
        'the caller deactivated' => app(DeactivateStaffMember::class)->handle($this->agent),
        // Set directly: DeactivateLocation refuses while the Lead is active.
        'the location made inactive' => DB::table('locations')->where('id', $this->lakeview->id)->update(['is_active' => false]),
        'a running skip cooldown for the caller' => app(QueueHistory::class)->record(
            LeadCadence::query()->where('lead_id', $this->lead->id)->sole(),
            QueueEventType::Skipped,
            actor: $this->agent,
            reason: QueueSkipReason::CannotCallNow->value,
            affectedUserId: $this->agent->id,
            cooldownUntil: now()->addMinutes(5),
        ),
    });

    $cadence = LeadCadence::query()->where('lead_id', $this->lead->id)->sole();

    expect($claim->lead?->id)->not->toBe($this->lead->id);
    expect($cadence->held_by_user_id)->toBeNull();
    expect($cadence->hold_token)->toBeNull();
    expect(LeadQueueEvent::query()->where('lead_id', $this->lead->id)->where('type', QueueEventType::Claimed)->count())->toBe(0);
    expect(LeadQueueEvent::query()->where('lead_id', $this->lead->id)->whereIn('type', [QueueEventType::HoldEnded, QueueEventType::HoldExpired])->count())->toBe(0);

    if ($fallbackGiven) {
        expect($claim->lead?->id)->toBe($this->fallback->id);
    } else {
        expect($claim->status)->toBe(QueueClaim::NOTHING_DUE);
        expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);
    }
})->with([
    ['transferred to a location that is closed', true],
    ['its location\'s timezone changed to one that is closed', true],
    ['the hours narrowed to exclude this moment', false],
    ['today unticked as a calling day', false],
    ['the caller\'s grant there revoked', true],
    ['the caller\'s role there left without the queue permission', true],
    ['the caller deactivated', false],
    ['the location made inactive', true],
    ['a running skip cooldown for the caller', true],
]);

test('a lead routed from central to a closed location during the claim is not returned', function () {
    $companyAgent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $unrouted = dueLead(attributes: ['received_at' => '2026-10-07 13:45:00']);
    $this->lead = $unrouted;

    $claim = claimWhile($companyAgent, fn () => app(RouteLead::class)->handle($unrouted->fresh(), $this->honolulu, null, $this->owner));

    expect($claim->lead->id)->not->toBe($unrouted->id);
    expect(LeadCadence::query()->where('lead_id', $unrouted->id)->sole()->held_by_user_id)->toBeNull();
    expect(queueHistory($unrouted))->toBe(['entered']);
});

test('a lead returned to central during the claim while Eastern is closed is not returned', function () {
    // 02:00 UTC Thursday: 10:00 pm Wednesday Eastern (closed), 7:00 pm Pacific (open).
    $this->travelTo('2026-10-08 02:00:00');

    $portland = Location::factory()->create(['timezone' => 'America/Los_Angeles']);
    $seattle = Location::factory()->create(['timezone' => 'America/Los_Angeles']);
    $companyAgent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->lead = dueLead($portland, attributes: ['received_at' => '2026-10-08 01:30:00']);
    $next = dueLead($seattle, attributes: ['received_at' => '2026-10-08 01:00:00']);

    $claim = claimWhile($companyAgent, fn () => app(ReturnLeadToCentral::class)->handle($this->lead->fresh(), 'Outside every service area.', $this->owner));

    expect($this->lead->fresh()->location_id)->toBeNull();
    expect($claim->lead->id)->toBe($next->id);
    expect(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->held_by_user_id)->toBeNull();
    expect(queueHistory($this->lead))->toBe(['entered']);
});

test('a lead routed into open hours during the claim is judged by its new location', function () {
    $claim = claimWhile($this->agent, fn () => app(RouteLead::class)->handle($this->lead->fresh(), $this->riverside, 'Closer to the customer.', $this->owner));

    expect($claim->lead->id)->toBe($this->lead->id);
    expect($claim->lead->location_id)->toBe($this->riverside->id);

    $claimed = LeadQueueEvent::query()->where('lead_id', $this->lead->id)->where('type', QueueEventType::Claimed)->sole();

    expect($claimed->location_id)->toBe($this->riverside->id);
    expect($claimed->location_name)->toBe('Riverside');
});

test('a lead moved to a location the caller has no access to during the claim is not returned', function () {
    $elsewhere = Location::factory()->create();

    $claim = claimWhile($this->agent, fn () => app(RouteLead::class)->handle($this->lead->fresh(), $elsewhere, 'Wrong area.', $this->owner));

    expect($claim->lead->id)->toBe($this->fallback->id);
    expect(queueHistory($this->lead))->toBe(['entered']);
});

/*
|--------------------------------------------------------------------------
| The existing hold is checked again
|--------------------------------------------------------------------------
*/

test('with nothing changed the held lead is returned again', function () {
    $first = nextLead($this->agent);

    $this->travelTo('2026-10-07 14:03:00');
    $again = nextLead($this->agent);

    expect($again->lead->id)->toBe($this->lead->id);
    expect($again->alreadyHeld)->toBeTrue();
    expect($again->endedHold)->toBeNull();
    expect($again->cadence->hold_token)->toBe($first->cadence->hold_token);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed']);
});

test('a held lead whose calling hours have closed is taken back and not returned', function (string $change) {
    if ($change === 'the clock moved past closing') {
        // Taken at 8:58 pm Eastern; asked again at 9:01 pm, with the hold still running.
        $this->travelTo('2026-10-08 00:58:00');
    }

    $first = nextLead($this->agent);
    expect($first->lead->id)->toBe($this->lead->id);

    match ($change) {
        'the clock moved past closing' => $this->travelTo('2026-10-08 01:01:00'),
        'the hours narrowed' => saveQueueSettings(['calling_opens_at' => 720]),
        'the day unticked' => saveQueueSettings(['calling_days' => [1, 2, 4, 5, 6, 7]]),
        'the location\'s timezone changed' => app(ChangeLocationTimezone::class)->handle($this->lakeview, 'Pacific/Honolulu'),
    };

    // None of those changes ends a hold by itself.
    expect(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->held_by_user_id)->toBe($this->agent->id);

    $again = nextLead($this->agent);

    expect($again->lead?->id)->not->toBe($this->lead->id);
    expect($again->endedHold)->toBe(QueueHoldEndReason::OutsideCallingHours);
    expect(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->held_by_user_id)->toBeNull();
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:outside_calling_hours']);

    $ended = LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->sole();

    expect($ended->affected_user_id)->toBe($this->agent->id);
    expect($ended->hold_token)->toBe($first->cadence->hold_token);

    // Only a timezone change leaves another Location open: there the next Lead is given.
    if ($change === 'the location\'s timezone changed') {
        expect($again->lead->id)->toBe($this->fallback->id);
    } else {
        expect($again->status)->toBe(QueueClaim::NOTHING_DUE);
    }

    // Asking once more does not bring the Lead back.
    expect(nextLead($this->agent)->lead?->id)->not->toBe($this->lead->id);
})->with([
    'the clock moved past closing',
    'the hours narrowed',
    'the day unticked',
    'the location\'s timezone changed',
]);

test('a held lead the holder may no longer work is taken back and not returned', function (string $change) {
    $first = nextLead($this->agent);
    expect($first->lead->id)->toBe($this->lead->id);

    // Each change is made directly, so the hold is deliberately left in place.
    match ($change) {
        'the queue permission lost there' => DB::table('access_grants')->where('id', grantAt($this->agent, $this->lakeview)->id)->update(['role' => Role::SalesConsultant->value]),
        'the grant there removed' => DB::table('access_grants')->where('id', grantAt($this->agent, $this->lakeview)->id)->delete(),
        'the location made inactive' => DB::table('locations')->where('id', $this->lakeview->id)->update(['is_active' => false]),
        'the lead moved where the holder has no access' => DB::table('leads')->where('id', $this->lead->id)->update(['location_id' => Location::factory()->create()->id]),
    };

    expect(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->held_by_user_id)->toBe($this->agent->id);

    $again = nextLead($this->agent);

    expect($again->lead->id)->toBe($this->fallback->id);
    expect($again->endedHold)->toBe(QueueHoldEndReason::AccessLost);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->sole()->affected_user_id)->toBe($this->agent->id);
})->with([
    'the queue permission lost there',
    'the grant there removed',
    'the location made inactive',
    'the lead moved where the holder has no access',
]);

test('a holder who has lost all queue access has the hold ended and is refused', function (string $change) {
    nextLead($this->agent);

    match ($change) {
        'every grant removed' => DB::table('access_grants')->where('user_id', $this->agent->id)->delete(),
        'the account deactivated' => DB::table('users')->where('id', $this->agent->id)->update(['is_active' => false]),
    };

    expect(fn () => nextLead($this->agent))->toThrow(AuthorizationException::class);

    expect(LeadCadence::query()->whereNotNull('held_by_user_id')->count())->toBe(0);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
    expect(LeadQueueEvent::query()->where('type', QueueEventType::HoldEnded)->sole()->affected_user_id)->toBe($this->agent->id);
})->with(['every grant removed', 'the account deactivated']);

test('a held lead the holder has a running skip cooldown for is taken back', function () {
    $first = nextLead($this->agent);

    app(QueueHistory::class)->record(
        $first->cadence,
        QueueEventType::Skipped,
        actor: $this->agent,
        reason: QueueSkipReason::CannotCallNow->value,
        affectedUserId: $this->agent->id,
        cooldownUntil: now()->addMinutes(5),
    );

    $again = nextLead($this->agent);

    expect($again->lead->id)->toBe($this->fallback->id);
    expect($again->endedHold)->toBe(QueueHoldEndReason::SkipCooldown);
    expect(queueHistory($this->lead))->toBe(['entered', 'claimed', 'skipped:cannot_call_now', 'hold_ended:skip_cooldown']);
});
