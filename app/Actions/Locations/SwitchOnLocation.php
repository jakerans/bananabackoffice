<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Support\Locations\LocationSetup;
use App\Support\Locations\SetupStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SwitchOnLocation
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private LocationSetup $setup) {}

    /**
     * Switch on a new Location once its required steps are done
     * (docs/slice-37-location-setup-plan.md, step 37b; decision 220,
     * question 2). Until then no Lead is routed to it and nothing is booked
     * there. Recorded in the Audit Log; a Location already switched on once
     * is reactivated instead.
     *
     * @throws ValidationException
     */
    public function handle(Location $location): void
    {
        DB::transaction(function () use ($location): void {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);

            if (! $location->isBeingSetUp()) {
                return;
            }

            $missing = $this->setup->missing($location);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'switchOn' => __('Still to do first: :steps.', ['steps' => implode(', ', array_map(fn (SetupStep $step): string => mb_strtolower($step->label), $missing))]),
                ]);
            }

            $location->forceFill(['is_active' => true, 'opened_at' => now()])->save();

            $this->recordAuditEvent->handle(AuditAction::LocationSwitchedOn, $location, location: $location);
        });
    }
}
