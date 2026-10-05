<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateLocation
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Take a location out of operation.
     *
     * Records at a deactivated location cannot be opened by anyone, so a
     * location that still has active Leads is refused: they must first be
     * transferred to another location or returned to central. Nothing overrides
     * this. Once Projects exist, active Projects must block it the same way.
     *
     * @throws ValidationException
     */
    public function handle(Location $location): void
    {
        DB::transaction(function () use ($location): void {
            $activeLeads = $location->leads()->active()->count();

            if ($activeLeads > 0) {
                throw ValidationException::withMessages([
                    'deactivation' => trans_choice(
                        '{1} :name still has 1 active lead. Transfer it to another location or return it to central, then deactivate.|[2,*] :name still has :count active leads. Transfer them to another location or return them to central, then deactivate.',
                        $activeLeads,
                        ['name' => $location->name, 'count' => $activeLeads],
                    ),
                ]);
            }

            $location->update(['is_active' => false]);

            if ($location->wasChanged('is_active')) {
                $this->recordAuditEvent->handle(AuditAction::LocationDeactivated, $location, location: $location);
            }
        });
    }
}
