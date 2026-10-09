<?php

namespace App\Support\Queue;

use App\Enums\QueueHoldEndReason;
use App\Models\Lead;
use App\Models\LeadCadence;

/**
 * The answer to "Next lead": one Lead nobody else can get, "nothing due right
 * now", or "try again" when the database stayed busy.
 */
final readonly class QueueClaim
{
    public const string GIVEN = 'given';

    public const string NOTHING_DUE = 'nothing_due';

    public const string TRY_AGAIN = 'try_again';

    /**
     * @param  bool  $alreadyHeld  The Lead was already held by this person and has been returned again.
     * @param  QueueHoldEndReason|null  $endedHold  Why the Lead this person was holding was taken back, when it was.
     */
    public function __construct(
        public string $status,
        public ?LeadCadence $cadence = null,
        public ?Lead $lead = null,
        public bool $alreadyHeld = false,
        public ?QueueHoldEndReason $endedHold = null,
    ) {}

    public function given(): bool
    {
        return $this->status === self::GIVEN;
    }
}
