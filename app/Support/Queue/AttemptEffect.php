<?php

namespace App\Support\Queue;

use App\Models\LeadCadence;

/**
 * What a contact attempt is to the cadence, decided under the Lead lock before
 * the attempt is inserted (docs/slice-4-plan.md, contract 4).
 */
final readonly class AttemptEffect
{
    /** A real two-way connection: the cadence ends. */
    public const string CONNECTED = 'connected';

    /** An outbound call that did not connect: it uses up the attempt that is due. */
    public const string COUNTS = 'counts';

    /** Evidence only: kept, with no effect on the cadence. */
    public const string EVIDENCE = 'evidence';

    /**
     * @param  LeadCadence|null  $cadence  The Lead's cadence while it is in the queue; null when the attempt cannot touch one.
     * @param  int|null  $cycle  The cadence cycle, only for an attempt that counts.
     * @param  int|null  $step  The cadence step it uses up, only for an attempt that counts.
     */
    public function __construct(
        public string $kind,
        public ?LeadCadence $cadence = null,
        public ?int $cycle = null,
        public ?int $step = null,
    ) {}

    public function counts(): bool
    {
        return $this->kind === self::COUNTS;
    }
}
