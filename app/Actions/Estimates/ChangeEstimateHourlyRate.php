<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Estimate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Change the hourly rate an estimate is priced at
 * (docs/slice-31-estimating-plan.md, step 31c). A manager only; a
 * salesperson changes the price through the scope, the paint and the named
 * discounts (decision 204, question 4). Recorded on the Lead.
 */
class ChangeEstimateHourlyRate
{
    public function __construct(
        private ChangeEstimate $changeEstimate,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  string|null  $rate  In dollars, as typed.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, ?string $rate, User $actor): void
    {
        $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($rate, $actor): void {
            $cents = Money::toCents($rate) ?? throw ValidationException::withMessages(['hourlyRate' => __('Give the hourly rate in dollars, such as 85.')]);

            if ($cents === $estimate->hourly_rate_cents) {
                return;
            }

            $before = $estimate->hourly_rate_cents;
            $estimate->forceFill(['hourly_rate_cents' => $cents])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadEstimateHourlyRateChanged,
                $estimate->lead,
                actor: $actor,
                before: ['hourly_rate' => Money::format($before)],
                after: ['hourly_rate' => Money::format($cents)],
                location: $estimate->location,
            );
        }, ability: 'changeHourlyRate');
    }
}
