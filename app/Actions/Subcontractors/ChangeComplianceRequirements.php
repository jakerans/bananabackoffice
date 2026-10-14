<?php

namespace App\Actions\Subcontractors;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A Location's own compliance requirements, beyond the base checklist
 * (docs/slice-12-subcontractors-plan.md, step 12b; product-architecture.md,
 * 7.2: "Location/state additions are CONFIGURABLE"; decision 148, question
 * 7). Changed by whoever manages Locations.
 */
class ChangeComplianceRequirements
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add a requirement every subcontractor at this Location must meet.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function add(Location $location, ?string $name, bool $expires, User $actor): ComplianceRequirement
    {
        Gate::forUser($actor)->authorize('update', $location);

        $name = trim((string) $name);

        if ($name === '') {
            throw ValidationException::withMessages(['requirementName' => __('Say what they must have.')]);
        }

        if (mb_strlen($name) > ComplianceRequirement::NAME_LENGTH) {
            throw ValidationException::withMessages(['requirementName' => __('Keep this to :max characters or fewer.', ['max' => ComplianceRequirement::NAME_LENGTH])]);
        }

        return DB::transaction(function () use ($location, $name, $expires, $actor): ComplianceRequirement {
            $taken = ComplianceRequirement::query()->active()
                ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $location->id))
                ->get()
                ->contains(fn (ComplianceRequirement $requirement): bool => mb_strtolower($requirement->name) === mb_strtolower($name));

            if ($taken) {
                throw ValidationException::withMessages(['requirementName' => __('This is already a requirement here.')]);
            }

            $requirement = new ComplianceRequirement;
            $requirement->forceFill([
                'location_id' => $location->id,
                'name' => $name,
                'expires' => $expires,
                'allows_banana_coverage' => false,
                'position' => ((int) ComplianceRequirement::query()->where('location_id', $location->id)->max('position')) + 1,
                'is_active' => true,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(AuditAction::LocationComplianceRequirementAdded, $location, actor: $actor, after: ['requirement' => $name, 'expires' => $expires], location: $location);

            return $requirement;
        });
    }

    /**
     * Take a Location's requirement away. It is kept, with what was recorded
     * against it; it no longer counts.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function remove(Location $location, int $requirementId, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $location);

        DB::transaction(function () use ($location, $requirementId, $actor): void {
            $requirement = ComplianceRequirement::query()->active()->where('location_id', $location->id)->lockForUpdate()->find($requirementId)
                ?? throw ValidationException::withMessages(['requirementName' => __('This requirement is no longer here. Reload the page.')]);

            $requirement->forceFill(['is_active' => false])->save();

            $this->recordAuditEvent->handle(AuditAction::LocationComplianceRequirementRemoved, $location, actor: $actor, before: ['requirement' => $requirement->name], location: $location);
        });
    }
}
