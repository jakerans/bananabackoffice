<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\ColorReadiness;
use App\Models\ColorSpecificationLine;
use App\Models\Project;
use App\Models\User;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordColorApproval
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Record that the customer approved the colors as they are written
     * (docs/slice-10-pre-production-plan.md, step 10b; product-architecture.md,
     * 6.4; decision 141, question 4). The Project's Production Manager, or a
     * manager where it is, until the customer can approve for themselves.
     *
     * It is somebody's word that the customer approved: who approved, how
     * and on which day. From then on that version is never changed; changing
     * the colors starts the next one, which needs approval again.
     *
     * @param  string|null  $approvedBy  Who approved, as "Pat Customer".
     * @param  string|null  $method  A ColorApprovalMethod, as its value.
     * @param  string|null  $approvedOn  The day they approved, as "2026-10-14".
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, ?string $approvedBy, ?string $method, ?string $approvedOn, User $actor): void
    {
        $approvedBy = trim((string) $approvedBy);

        DB::transaction(function () use ($project, $approvedBy, $method, $approvedOn, $actor): void {
            // Read as it is now, and held so the colors cannot change while they are approved.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $working = $project->colorSpecifications()->first();

            if ($working === null || $working->isApproved() || $working->lines()->doesntExist()) {
                throw ValidationException::withMessages(['colors' => __('There are no colors waiting to be approved.')]);
            }

            if ($approvedBy === '') {
                throw ValidationException::withMessages(['approvedBy' => __('Say who approved the colors.')]);
            }

            if (mb_strlen($approvedBy) > ColorSpecificationLine::TEXT_LENGTH) {
                throw ValidationException::withMessages(['approvedBy' => __('Keep this to :max characters or fewer.', ['max' => ColorSpecificationLine::TEXT_LENGTH])]);
            }

            $how = ColorApprovalMethod::tryFrom((string) $method)
                ?? throw ValidationException::withMessages(['approvalMethod' => __('Say how they approved: in person, by phone, by text or by email.')]);

            $day = RoughTimeframe::typed($approvedOn, 'approvedOn', __('Choose the day they approved.'));

            if ($day->greaterThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['approvedOn' => __('The approval cannot be on a day that has not come yet.')]);
            }

            $was = $project->color_readiness;

            $working->forceFill([
                'approved_at' => now(),
                'approved_on' => $day,
                'approved_by_customer' => $approvedBy,
                'approval_method' => $how,
                'approval_recorded_by' => $actor->id,
                'approval_recorded_by_name' => $actor->name,
            ])->save();

            $project->forceFill(['color_readiness' => ColorReadiness::Approved])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectColorsApproved,
                $project,
                actor: $actor,
                before: ['color_readiness' => $was->value],
                after: [
                    'color_readiness' => ColorReadiness::Approved->value,
                    'version' => $working->version,
                    'approved_by' => $approvedBy,
                    'how' => $how->value,
                    'approved_on' => $day->toDateString(),
                    'lines' => $working->lines()->count(),
                ],
                location: $project->location,
            );
        });
    }
}
