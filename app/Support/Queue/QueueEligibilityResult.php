<?php

namespace App\Support\Queue;

use App\Enums\QueueHoldEndReason;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\User;

/**
 * The answer to "may this person be given this Lead at this moment?", with the
 * current rows it was worked out from.
 */
final readonly class QueueEligibilityResult
{
    /**
     * @param  QueueHoldEndReason|null  $refusal  Why not: access lost, outside calling hours or a running skip cooldown. Null when eligible.
     * @param  User|null  $user  The person as they are now, with their current grants.
     * @param  Lead|null  $lead  The Lead as it is now, with its current Location.
     */
    public function __construct(
        public ?QueueHoldEndReason $refusal,
        public ?User $user,
        public ?Lead $lead,
        public ContactCenterSettings $settings,
        public string $timezone,
    ) {}

    public function eligible(): bool
    {
        return $this->refusal === null;
    }

    /**
     * Whether the person may work this Lead in the queue at all, whatever the
     * hour and whether or not they skipped it.
     */
    public function mayWork(): bool
    {
        return $this->refusal !== QueueHoldEndReason::AccessLost;
    }
}
