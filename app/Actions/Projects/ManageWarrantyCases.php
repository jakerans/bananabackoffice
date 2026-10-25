<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WarrantyCase;
use App\Models\WorkPackageAward;
use App\Notifications\ProjectNotice;
use App\Support\Money;
use App\Support\Projects\RoughTimeframe;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Warranty cases (docs/slice-22-warranty-plan.md, step 22a; decision 177;
 * product-architecture.md, 16).
 *
 * Anyone who can see a job opens a case on it. It belongs to the job's
 * Production Manager while they are an active Production Manager at the
 * Location; otherwise the Location's managers are told, and one of them
 * gives it to a Production Manager or keeps it. A case is closed without
 * work, with why, when nothing is to be done. Each step is audited on the
 * job, and nothing is sent to the customer.
 */
class ManageWarrantyCases
{
    public function __construct(protected RecordAuditEvent $recordAuditEvent) {}

    /**
     * Open a case: what the customer reported, and who reported it.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function open(Project $project, ?string $description, ?string $reportedBy, User $actor): WarrantyCase
    {
        $description = $this->text($description, 'warrantyDescription', __('Say what the customer reports.'), WarrantyCase::DESCRIPTION_LENGTH);
        $reportedBy = $this->text($reportedBy, 'warrantyReportedBy', __('Say who reported it, and how.'), WarrantyCase::REPORTED_BY_LENGTH);

        return DB::transaction(function () use ($project, $description, $reportedBy, $actor): WarrantyCase {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('openWarranty', $project);

            $manager = $project->production_manager_id === null ? null : User::query()->with('accessGrants')->find($project->production_manager_id);
            $owner = $manager !== null && $manager->holdsRoleAt(Role::ProductionManager, $project->location) ? $manager : null;

            $case = new WarrantyCase;
            $case->forceFill([
                'project_id' => $project->id,
                'description' => $description,
                'reported_by' => $reportedBy,
                // Who did the original work, for their warranty-call rate later (question 7).
                'original_subcontractor_ids' => WorkPackageAward::query()
                    ->whereIn('work_package_id', $project->workPackages()->select('id'))
                    ->whereNull('ended_at')
                    ->distinct()
                    ->pluck('subcontractor_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all(),
                'state' => WarrantyCase::OPEN,
                'owner_id' => $owner?->id,
                'owner_name' => $owner?->name,
                'owner_assigned_at' => $owner === null ? null : now(),
                'opened_by' => $actor->id,
                'opened_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyCaseOpened,
                $project,
                actor: $actor,
                after: ['case' => $case->id, 'description' => $case->summary(), 'owner' => $case->owner_name],
                location: $project->location,
            );

            if ($owner !== null) {
                $this->tellOwner($case, $owner, $actor);
            } else {
                $this->managersOf($project, $actor)->each(fn (User $user) => $user->notify(new ProjectNotice($project->id, ProjectNotice::WARRANTY_UNOWNED)));
            }

            return $case;
        });
    }

    /**
     * A manager gives the case to a Production Manager at the Location, or
     * keeps it.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function assign(WarrantyCase $case, ?int $ownerId, User $actor): WarrantyCase
    {
        return $this->held($case, $actor, 'assign', function (WarrantyCase $case, Project $project) use ($ownerId, $actor): WarrantyCase {
            $owner = $ownerId === null ? null : User::query()->with('accessGrants')->find($ownerId);

            if ($owner === null || ! ($owner->holdsRoleAt(Role::ProductionManager, $project->location) || $owner->holdsRoleAt(Role::LocationManager, $project->location))) {
                throw ValidationException::withMessages(['warrantyOwner' => __('Choose a Production Manager or a manager at :location.', ['location' => $project->location->name])]);
            }

            $before = $case->owner_name;
            $case->forceFill(['owner_id' => $owner->id, 'owner_name' => $owner->name, 'owner_assigned_at' => now()])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyCaseAssigned,
                $project,
                actor: $actor,
                before: ['owner' => $before],
                after: ['case' => $case->id, 'owner' => $owner->name],
                location: $project->location,
            );

            $this->tellOwner($case, $owner, $actor);

            return $case;
        });
    }

    /**
     * A manager approves the warranty budget, with why, before the visit is
     * scheduled; $0 is allowed (step 22b; question 4). It can be changed
     * while the case is open or scheduled.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approveBudget(WarrantyCase $case, ?string $amount, ?string $reason, User $actor): WarrantyCase
    {
        $typed = str_replace([',', ' ', '$'], '', trim((string) $amount));
        $cents = preg_match('/^0+(\.0{1,2})?$/', $typed) === 1 ? 0 : (Money::toCents($amount)
            ?? throw ValidationException::withMessages(['warrantyBudget' => __('Give the budget in dollars, like 350 or 0.')]));
        $reason = $this->text($reason, 'warrantyBudgetReason', __('Say why this budget.'), WarrantyCase::OUTCOME_LENGTH);

        return $this->held($case, $actor, 'approveBudget', function (WarrantyCase $case, Project $project) use ($cents, $reason, $actor): WarrantyCase {
            $before = $case->budget_cents;
            $case->forceFill(['budget_cents' => $cents, 'budget_reason' => $reason, 'budget_approved_by' => $actor->id, 'budget_approved_by_name' => $actor->name, 'budget_approved_at' => now()])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyBudgetApproved,
                $project,
                actor: $actor,
                before: ['budget_cents' => $before],
                after: ['case' => $case->id, 'budget_cents' => $cents],
                location: $project->location,
                reason: $reason,
            );

            return $case;
        });
    }

    /**
     * Schedule the visit, or move it: the day and who does it. Not before
     * the budget is approved.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function schedule(WarrantyCase $case, ?string $day, ?string $doneByKind, ?string $doneByName, ?int $subcontractorId, User $actor): WarrantyCase
    {
        return $this->held($case, $actor, 'manage', function (WarrantyCase $case, Project $project) use ($day, $doneByKind, $doneByName, $subcontractorId, $actor): WarrantyCase {
            $this->refuseUnlessActive($case);

            if (! $case->hasBudget()) {
                throw ValidationException::withMessages(['warranty' => __('A manager approves the warranty budget first.')]);
            }

            $visit = RoughTimeframe::typed($day, 'warrantyVisitOn', __('Give the day of the visit.'));

            if ($visit->lessThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['warrantyVisitOn' => __('The visit cannot be in the past.')]);
            }

            if (! isset(WarrantyCase::DONE_BY[(string) $doneByKind])) {
                throw ValidationException::withMessages(['warrantyDoneByKind' => __('Choose who does it.')]);
            }

            [$subcontractor, $name] = in_array($doneByKind, WarrantyCase::BY_SUBCONTRACTOR, true)
                ? [$this->subcontractorFor($case, $project, (string) $doneByKind, $subcontractorId), null]
                : [null, $this->text($doneByName, 'warrantyDoneByName', __('Say who does it.'), WarrantyCase::DONE_BY_LENGTH)];

            $case->forceFill([
                'state' => WarrantyCase::SCHEDULED,
                'visit_on' => $visit->toDateString(),
                'done_by_kind' => $doneByKind,
                'done_by_name' => $name,
                'subcontractor_id' => $subcontractor?->id,
                'scheduled_by_name' => $actor->name,
                'scheduled_at' => now(),
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyVisitScheduled,
                $project,
                actor: $actor,
                after: ['case' => $case->id, 'visit_on' => $visit->toDateString(), 'done_by' => $case->doneByWords()],
                location: $project->location,
            );

            return $case;
        });
    }

    /**
     * Complete a scheduled case: what was done.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function complete(WarrantyCase $case, ?string $outcome, User $actor): WarrantyCase
    {
        $outcome = $this->text($outcome, 'warrantyOutcome', __('Say what was done.'), WarrantyCase::OUTCOME_LENGTH);

        return $this->held($case, $actor, 'manage', function (WarrantyCase $case, Project $project) use ($outcome, $actor): WarrantyCase {
            if ($case->state !== WarrantyCase::SCHEDULED) {
                throw ValidationException::withMessages(['warranty' => __('Only a scheduled case is completed.')]);
            }

            $case->forceFill(['state' => WarrantyCase::COMPLETED, 'outcome' => $outcome, 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyCaseCompleted,
                $project,
                actor: $actor,
                after: ['case' => $case->id, 'state' => WarrantyCase::COMPLETED],
                location: $project->location,
                reason: $outcome,
            );

            return $case;
        });
    }

    /**
     * Close a case without work, with why: not covered, or nothing to do.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function close(WarrantyCase $case, ?string $reason, User $actor): WarrantyCase
    {
        $reason = $this->text($reason, 'warrantyCloseReason', __('Say why it is closed without work.'), WarrantyCase::OUTCOME_LENGTH);

        return $this->held($case, $actor, 'manage', function (WarrantyCase $case, Project $project) use ($reason, $actor): WarrantyCase {
            $this->refuseUnlessActive($case);

            $case->forceFill(['state' => WarrantyCase::CLOSED, 'outcome' => $reason, 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyCaseClosed,
                $project,
                actor: $actor,
                after: ['case' => $case->id, 'state' => WarrantyCase::CLOSED],
                location: $project->location,
                reason: $reason,
            );

            return $case;
        });
    }

    /**
     * Read the case and its job again, held, and check who may do it.
     *
     * @template TResult
     *
     * @param  Closure(WarrantyCase, Project): TResult  $apply
     * @return TResult
     */
    protected function held(WarrantyCase $case, User $actor, string $ability, Closure $apply): mixed
    {
        return DB::transaction(function () use ($case, $actor, $ability, $apply): mixed {
            Project::query()->lockForUpdate()->findOrFail($case->project_id);
            $case = WarrantyCase::query()->with('project.location')->lockForUpdate()->findOrFail($case->id);

            Gate::forUser($actor)->authorize($ability, $case);

            return $apply($case, $case->project);
        });
    }

    /**
     * @throws ValidationException
     */
    protected function refuseUnlessActive(WarrantyCase $case): void
    {
        if (! $case->isActive()) {
            throw ValidationException::withMessages(['warranty' => __('This case is :state.', ['state' => mb_strtolower(__($case->stateLabel()))])]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function text(?string $typed, string $field, string $missing, int $length): string
    {
        $typed = trim((string) $typed);

        if ($typed === '' || mb_strlen($typed) > $length) {
            throw ValidationException::withMessages([$field => $typed === '' ? $missing : __('Keep this to :max characters or fewer.', ['max' => $length])]);
        }

        return $typed;
    }

    /**
     * The subcontractor who does it: one of the original work's, or another
     * who works at the Location.
     *
     * @throws ValidationException
     */
    private function subcontractorFor(WarrantyCase $case, Project $project, string $kind, ?int $subcontractorId): Subcontractor
    {
        $subcontractor = $subcontractorId === null ? null : Subcontractor::query()->find($subcontractorId);
        $fits = $subcontractor !== null && ($kind === 'original_subcontractor'
            ? in_array($subcontractor->id, $case->original_subcontractor_ids, true)
            : $subcontractor->locations()->whereKey($project->location_id)->exists());

        if (! $fits) {
            throw ValidationException::withMessages(['warrantySubcontractor' => $kind === 'original_subcontractor' ? __('Choose one of the subcontractors who did the original work.') : __('Choose a subcontractor who works at :location.', ['location' => $project->location->name])]);
        }

        return $subcontractor;
    }

    private function tellOwner(WarrantyCase $case, User $owner, User $actor): void
    {
        if ($owner->isNot($actor)) {
            $owner->notify(new ProjectNotice($case->project_id, ProjectNotice::WARRANTY_ASSIGNED));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function managersOf(Project $project, User $actor): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->with('accessGrants')
            ->get()
            ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::LocationManager, $project->location))
            ->values();
    }
}
