<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use App\Support\ServiceAreas\PostalCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateServiceAreaPostalCodes
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Set the postal codes a Location normally serves.
     *
     * The list given replaces the Location's list. Each entry must be a
     * five-digit ZIP (a ZIP+4 is reduced to its ZIP). What was added and what
     * was removed is recorded; saving an unchanged list records nothing.
     *
     * @param  list<string>  $postalCodes
     *
     * @throws ValidationException
     */
    public function handle(Location $location, array $postalCodes): void
    {
        $wanted = [];
        $rejected = [];

        foreach ($postalCodes as $typed) {
            if (trim($typed) === '') {
                continue;
            }

            $normalized = PostalCode::normalize($typed);

            if ($normalized === null) {
                $rejected[] = trim($typed);
            } else {
                $wanted[$normalized] = true;
            }
        }

        if ($rejected !== []) {
            throw ValidationException::withMessages([
                'postalCodes' => __('These are not five-digit ZIP codes (:codes).', ['codes' => implode(', ', array_slice($rejected, 0, 10))]),
            ]);
        }

        $wanted = array_map(strval(...), array_keys($wanted));
        sort($wanted);

        $current = $location->servicePostalCodes()->orderBy('postal_code')->pluck('postal_code')->all();

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        if ($added === [] && $removed === []) {
            return;
        }

        DB::transaction(function () use ($location, $added, $removed): void {
            if ($removed !== []) {
                $location->servicePostalCodes()->whereIn('postal_code', $removed)->delete();
            }

            foreach ($added as $postalCode) {
                $row = new ServiceAreaPostalCode;
                $row->location_id = $location->id;
                $row->postal_code = $postalCode;
                $row->save();
            }

            $this->recordAuditEvent->handle(
                AuditAction::LocationServiceAreaChanged,
                $location,
                before: ['removed' => $removed],
                after: ['added' => $added],
                location: $location,
            );
        });

        $location->unsetRelation('servicePostalCodes');
    }
}
