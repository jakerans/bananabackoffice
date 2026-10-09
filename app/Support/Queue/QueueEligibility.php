<?php

namespace App\Support\Queue;

use App\Enums\Permission;
use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Models\AccessGrant;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * The one rule that answers "may this person be given this Lead at this
 * moment?". Every path that hands out a Lead, keeps a hold or ends one for
 * lost access asks here.
 *
 * check() always reads the current rows, through row locks and in one fixed
 * order, so a change that is still being saved is waited for and one that has
 * been saved is seen. It must be called inside a database transaction, and
 * the cadence row is touched only after it.
 */
class QueueEligibility
{
    /**
     * Read the current rows through their locks and answer for this person,
     * this Lead and this moment.
     *
     * The order is fixed: the person, their access grants, the current
     * settings, the Lead, the Lead's Location.
     */
    public function check(int $userId, int $leadId, int $cadenceId, CarbonInterface $moment): QueueEligibilityResult
    {
        $user = User::query()->sharedLock()->find($userId);
        $grants = AccessGrant::query()->where('user_id', $userId)->sharedLock()->get();
        $settings = ContactCenterSettings::query()->orderByDesc('version')->sharedLock()->firstOrFail();
        $lead = Lead::query()->lockForUpdate()->find($leadId);
        $location = $lead?->location_id === null
            ? null
            : Location::query()->sharedLock()->find($lead->location_id);

        $user?->setRelation('accessGrants', $grants);
        $lead?->setRelation('location', $location);

        $timezone = CallingHours::timezoneFor($location);

        $refusal = match (true) {
            $user === null, $lead === null, ! $this->mayWork($user, $lead) => QueueHoldEndReason::AccessLost,
            ! CallingHours::fromSettings($settings)->isOpen($moment, $timezone) => QueueHoldEndReason::OutsideCallingHours,
            $this->isCoolingDown($userId, $cadenceId, $moment) => QueueHoldEndReason::SkipCooldown,
            default => null,
        };

        return new QueueEligibilityResult($refusal, $user, $lead, $settings, $timezone);
    }

    /**
     * Whether the person may work this Lead in the queue where it is now.
     *
     * A routed Lead needs the queue permission at the Lead's own Location and
     * the existing "work this Lead" rule there. An unrouted Lead needs the
     * queue permission through an all-locations grant and the existing unrouted
     * rule. A deactivated person or Location is covered by nothing.
     *
     * The person and the Lead must be given as they are now, with the grants
     * and the Location already loaded by the caller.
     */
    public function mayWork(User $user, Lead $lead): bool
    {
        if ($lead->location_id === null) {
            $holdsQueue = $user->hasPermissionAcrossAllLocations(Permission::WorkLeadQueue);
        } else {
            $holdsQueue = $lead->location !== null && $user->hasPermissionAt(Permission::WorkLeadQueue, $lead->location);
        }

        return $holdsQueue && Gate::forUser($user)->allows('update', $lead);
    }

    /**
     * Whether the person skipped this Lead and their cooldown is still running.
     */
    public function isCoolingDown(int $userId, int $cadenceId, CarbonInterface $moment): bool
    {
        return LeadQueueEvent::query()
            ->where('lead_cadence_id', $cadenceId)
            ->where('affected_user_id', $userId)
            ->where('type', QueueEventType::Skipped)
            ->where('cooldown_until', '>', $moment)
            ->exists();
    }

    /**
     * Where to look for candidates: the Locations the person may work the
     * queue at that are inside calling hours right now, and whether unrouted
     * Leads are both allowed and open.
     *
     * This is only a hint. Nothing read here decides who gets a Lead: each
     * candidate is put through check() before it is claimed.
     *
     * @return array{locations: list<int>, unrouted: bool, anywhere: bool}
     */
    public function scope(User $user, CarbonInterface $moment): array
    {
        $hours = CallingHours::fromSettings(ContactCenterSettings::current());
        $unroutedLead = new Lead;

        $allowed = $user->locationsWhere(Permission::WorkLeadQueue)
            ->filter(fn (Location $location): bool => $this->mayWork($user, (new Lead)->forceFill(['location_id' => $location->id])->setRelation('location', $location)));

        $mayWorkUnrouted = $this->mayWork($user, $unroutedLead);

        return [
            'locations' => array_values($allowed
                ->filter(fn (Location $location): bool => $hours->isOpen($moment, CallingHours::timezoneFor($location)))
                ->modelKeys()),
            'unrouted' => $mayWorkUnrouted && $hours->isOpen($moment, CallingHours::UNROUTED_TIMEZONE),
            'anywhere' => $mayWorkUnrouted || $allowed->isNotEmpty(),
        ];
    }
}
