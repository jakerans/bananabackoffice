<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\LocationSalesTarget;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Set a Location's monthly sold-revenue target from a month
 * (docs/slice-25-marketing-and-executive-boards-plan.md, step 25a; decision
 * 183, question 6). Whoever manages Locations. Earlier months keep the
 * target they had.
 */
class ChangeSalesTarget
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Location $location, ?string $amount, ?string $fromMonth, User $actor): LocationSalesTarget
    {
        Gate::forUser($actor)->authorize('update', $location);

        $cents = Money::toCents($amount) ?? throw ValidationException::withMessages(['salesTargetAmount' => __('Give the monthly target in dollars, like 250,000.')]);
        $month = (string) $fromMonth;

        if (! PayPeriods::isPeriod($month)) {
            throw ValidationException::withMessages(['salesTargetFrom' => __('Choose the month it starts.')]);
        }

        return DB::transaction(function () use ($location, $cents, $month, $actor): LocationSalesTarget {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $before = LocationSalesTarget::for($location->id, $month);

            $target = new LocationSalesTarget;
            $target->forceFill([
                'location_id' => $location->id,
                'month_from' => $month,
                'amount_cents' => $cents,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LocationSalesTargetChanged,
                $location,
                actor: $actor,
                before: ['amount_cents' => $before],
                after: ['amount_cents' => $cents, 'from' => $month],
                location: $location,
            );

            return $target;
        });
    }
}
