<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Set a Location's deposit policy: the percent of the sold price asked for
 * as a deposit (docs/slice-17-customer-payments-plan.md, step 17b; decision
 * 162, question 4; product-architecture.md, 10.4). Whoever manages
 * Locations. It applies to every job there whose deposit was not changed by
 * a manager; an invoice already recorded is not changed.
 */
class ChangeDepositPolicy
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Location $location, ?string $percent, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $location);

        $typed = trim((string) $percent, " \t%");

        if (preg_match('/^\d{1,3}$/', $typed) !== 1 || (int) $typed > 100) {
            throw ValidationException::withMessages(['depositPercent' => __('Give a whole percent from 0 to 100.')]);
        }

        DB::transaction(function () use ($location, $typed, $actor): void {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $before = $location->deposit_percent;

            if ($before === (int) $typed) {
                return;
            }

            $location->forceFill(['deposit_percent' => (int) $typed])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LocationDepositPolicyChanged,
                $location,
                actor: $actor,
                before: ['deposit_percent' => $before],
                after: ['deposit_percent' => (int) $typed],
                location: $location,
            );
        });
    }
}
