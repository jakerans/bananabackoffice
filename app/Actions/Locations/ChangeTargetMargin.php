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
 * Set a Location's target gross margin: the percent of the contract a job
 * there aims to keep (docs/slice-19-job-costing-plan.md, step 19a; decision
 * 170, question 4). Whoever manages Locations. Every job's budget reads it
 * as it is now.
 */
class ChangeTargetMargin
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

        if (preg_match('/^\d{1,2}$/', $typed) !== 1 || (int) $typed < 1) {
            throw ValidationException::withMessages(['targetMarginPercent' => __('Give a whole percent from 1 to 99.')]);
        }

        DB::transaction(function () use ($location, $typed, $actor): void {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $before = $location->target_margin_percent;

            if ($before === (int) $typed) {
                return;
            }

            $location->forceFill(['target_margin_percent' => (int) $typed])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LocationTargetMarginChanged,
                $location,
                actor: $actor,
                before: ['target_margin_percent' => $before],
                after: ['target_margin_percent' => (int) $typed],
                location: $location,
            );
        });
    }
}
