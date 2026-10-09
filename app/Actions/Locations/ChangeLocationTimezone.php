<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Support\Queue\LocationTimezones;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeLocationTimezone
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Set the timezone a Location's calling hours are judged in.
     *
     * Only a zone on the fixed list is accepted. The timezone is saved with its
     * audit event or not at all; choosing the zone it already has records
     * nothing. No scheduled time is recalculated by this.
     *
     * @throws ValidationException
     */
    public function handle(Location $location, string $timezone): void
    {
        if (! LocationTimezones::isAllowed($timezone)) {
            throw ValidationException::withMessages([
                'editingTimezone' => __('Choose one of the listed timezones.'),
            ]);
        }

        DB::transaction(function () use ($location, $timezone): void {
            $previous = $location->timezone;

            $location->update(['timezone' => $timezone]);

            if ($location->wasChanged('timezone')) {
                $this->recordAuditEvent->handle(
                    AuditAction::LocationTimezoneChanged,
                    $location,
                    before: ['timezone' => $previous],
                    after: ['timezone' => $location->timezone],
                    location: $location,
                );
            }
        });
    }
}
