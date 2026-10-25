<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Projects\AmbassadorPackage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Brand Ambassador package a job was sold with
 * (docs/slice-21-brand-ambassador-plan.md, step 21a; decision 175;
 * product-architecture.md, 6.10 and 10.2).
 *
 * It is recorded with the sale. The salesperson can correct it until the
 * handoff is complete, a manager at any time, and after the handoff with
 * why. The job keeps the tier, the discount, the items, the largest discount
 * then and the retail price before the discount. A custom package tells the
 * Location's managers.
 */
class RecordAmbassadorPackage
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Record or correct the package from the job. A job sold before slice 21
     * has none until someone records it.
     *
     * @param  array<string, mixed>  $input  ambassadorTier, and for Custom ambassadorDiscount, ambassadorItems and ambassadorReason.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function correct(Project $project, array $input, ?string $reason, User $actor): Project
    {
        return DB::transaction(function () use ($project, $input, $reason, $actor): Project {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('recordAmbassadorPackage', $project);

            $package = AmbassadorPackage::fromInput(['ambassadorTier' => '', ...$input])
                ?? throw ValidationException::withMessages(['ambassadorTier' => __('Choose the Brand Ambassador package, or none.')]);
            $reason = trim((string) $reason);

            if ($project->ambassador_tier !== null && $project->hasHandoff() && $reason === '') {
                throw ValidationException::withMessages(['ambassadorCorrection' => __('Say why the package changes after the handoff.')]);
            }

            if (mb_strlen($reason) > AmbassadorPackage::REASON_LENGTH) {
                throw ValidationException::withMessages(['ambassadorCorrection' => __('Keep this to :max characters or fewer.', ['max' => AmbassadorPackage::REASON_LENGTH])]);
            }

            $this->apply($project, $package, $reason === '' ? null : $reason, $actor);

            return $project;
        });
    }

    /**
     * Keep a package on the job. Part of the sale or of a correction, saved
     * with it or not at all; the caller has checked who may do it.
     */
    public function apply(Project $project, AmbassadorPackage $package, ?string $correction, User $actor): void
    {
        $before = $project->ambassadorPackage();

        $project->forceFill([
            'ambassador_tier' => $package->tier,
            'ambassador_discount_percent' => $package->discountPercent,
            'ambassador_items' => $package->items,
            'ambassador_reason' => $package->reason,
            'ambassador_max_discount_percent' => AmbassadorPackage::MAX_DISCOUNT_PERCENT,
            'retail_cents' => $package->retailCents($project->sold_cents),
            'ambassador_recorded_at' => now(),
            'ambassador_recorded_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectAmbassadorPackageRecorded,
            $project,
            actor: $actor,
            before: $before === null ? [] : ['package' => $before->label(), 'items' => $before->items],
            after: ['package' => $package->label(), 'items' => $package->items, 'retail_cents' => $project->retail_cents],
            location: $project->location,
            reason: $correction ?? $package->reason,
        );

        if ($package->tier === AmbassadorPackage::CUSTOM && $before != $package) {
            User::query()
                ->where('is_active', true)
                ->whereKeyNot($actor->id)
                ->with('accessGrants')
                ->get()
                ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::LocationManager, $project->location))
                ->each(fn (User $user) => $user->notify(new ProjectNotice($project->id, ProjectNotice::AMBASSADOR_CUSTOM, name: $actor->name)));
        }
    }
}
