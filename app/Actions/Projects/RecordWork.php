<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageExtension;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The work in progress (docs/slice-14-work-and-closeout-plan.md, step 14a;
 * product-architecture.md, 6.8 and 7.9; decision 155, questions 1 to 3):
 * work started and finished on a package, and work running over given a
 * later last day with whose cause it was.
 *
 * The Project's Production Manager, or a manager there. The job is In
 * progress once any package has started and Work complete once every
 * package has finished. In the customer's Activity.
 */
class RecordWork
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * The work on a package started, on a day that has come. Only work given
     * to a subcontractor, with dates the customer confirmed.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function start(Project $project, int $packageId, ?string $day, User $actor): WorkPackage
    {
        return $this->held($project, $actor, function (Project $project, WorkPackage $package) use ($day, $actor): WorkPackage {
            if ($package->hasStarted()) {
                throw ValidationException::withMessages(['work' => __('This work has already started.')]);
            }

            if ($package->currentAward === null || ! $package->isScheduled()) {
                throw ValidationException::withMessages(['work' => __('Give this work to a subcontractor and confirm its dates with the customer first.')]);
            }

            $started = self::day($project, $day, 'workDay');

            $package->forceFill(['work_started_on' => $started])->save();

            $this->audit(AuditAction::ProjectWorkStarted, $project, $package, $started, $actor);

            return $package;
        }, $packageId);
    }

    /**
     * The work on a package finished, on or after the day it started.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function finish(Project $project, int $packageId, ?string $day, User $actor): WorkPackage
    {
        return $this->held($project, $actor, function (Project $project, WorkPackage $package) use ($day, $actor): WorkPackage {
            if (! $package->hasStarted()) {
                throw ValidationException::withMessages(['work' => __('Record that the work started first.')]);
            }

            if ($package->hasFinished()) {
                throw ValidationException::withMessages(['work' => __('This work has already finished.')]);
            }

            $finished = self::day($project, $day, 'workDay');

            if ($finished->lessThan($package->work_started_on)) {
                throw ValidationException::withMessages(['workDay' => __('It cannot finish before it started, on :day.', ['day' => $package->work_started_on->format('M j, Y')])]);
            }

            $package->forceFill(['work_finished_on' => $finished])->save();

            $this->audit(AuditAction::ProjectWorkFinished, $project, $package, $finished, $actor);

            return $package;
        }, $packageId);
    }

    /**
     * Give work that has started a later last day, with whose cause it was
     * (decision 155, question 3). The days it had are kept.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function extend(Project $project, int $packageId, ?string $lastDay, ?string $cause, ?string $reason, User $actor): WorkPackageExtension
    {
        return $this->held($project, $actor, function (Project $project, WorkPackage $package) use ($lastDay, $cause, $reason, $actor): WorkPackageExtension {
            if (! $package->hasStarted() || $package->hasFinished()) {
                throw ValidationException::withMessages(['work' => __('Only work under way can be given more days.')]);
            }

            $newLast = RoughTimeframe::typed($lastDay, 'extendLastDay', __('Choose the new last day.'));

            if (! $newLast->greaterThan($package->scheduled_last_day)) {
                throw ValidationException::withMessages(['extendLastDay' => __('Choose a day after :day, its last day now.', ['day' => $package->scheduled_last_day->format('M j, Y')])]);
            }

            if (! in_array($cause, WorkPackageExtension::CAUSES, true)) {
                throw ValidationException::withMessages(['extendCause' => __('Choose whose cause it was.')]);
            }

            $reason = trim((string) $reason);

            if ($cause === WorkPackageExtension::CAUSE_OTHER && $reason === '') {
                throw ValidationException::withMessages(['extendReason' => __('Say what the other reason was.')]);
            }

            if (mb_strlen($reason) > WorkPackageExtension::REASON_LENGTH) {
                throw ValidationException::withMessages(['extendReason' => __('Keep this to :max characters or fewer.', ['max' => WorkPackageExtension::REASON_LENGTH])]);
            }

            $extension = new WorkPackageExtension;
            $extension->forceFill([
                'work_package_id' => $package->id,
                'previous_last_day' => $package->scheduled_last_day,
                'new_last_day' => $newLast,
                'cause' => $cause,
                'reason' => $reason === '' ? null : $reason,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $package->forceFill(['scheduled_last_day' => $newLast])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectWorkExtended,
                $project,
                actor: $actor,
                before: ['package' => $package->name, 'day' => $extension->previous_last_day->toDateString()],
                after: ['package' => $package->name, 'day' => $newLast->toDateString(), 'cause' => $cause],
                location: $project->location,
                reason: $extension->reason,
            );

            return $extension;
        }, $packageId);
    }

    /**
     * Run one change with the Project held, once the person may make it, and
     * keep the production state in step.
     *
     * @template TResult
     *
     * @param  Closure(Project, WorkPackage): TResult  $apply
     * @return TResult
     */
    private function held(Project $project, User $actor, Closure $apply, int $packageId): mixed
    {
        return DB::transaction(function () use ($project, $actor, $apply, $packageId): mixed {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $package = $project->workPackages()->with('currentAward')->whereKey($packageId)->first()
                ?? throw ValidationException::withMessages(['work' => __('This work package is no longer here. Reload the page.')]);

            $result = $apply($project, $package);

            $this->keepStateInStep($project);

            return $result;
        });
    }

    /**
     * In progress once any package has started; Work complete once every one
     * has finished. A job signed off stays signed off.
     */
    private function keepStateInStep(Project $project): void
    {
        if ($project->production_state === ProjectProductionState::SignedOff) {
            return;
        }

        $packages = $project->workPackages()->get();
        $state = match (true) {
            $packages->isNotEmpty() && $packages->every(fn (WorkPackage $package): bool => $package->hasFinished()) => ProjectProductionState::WorkComplete,
            $packages->contains(fn (WorkPackage $package): bool => $package->hasStarted()) => ProjectProductionState::InProgress,
            default => $project->production_state,
        };

        if ($state !== $project->production_state) {
            $project->forceFill(['production_state' => $state])->save();
        }
    }

    private function audit(AuditAction $action, Project $project, WorkPackage $package, CarbonImmutable $day, User $actor): void
    {
        $this->recordAuditEvent->handle($action, $project, actor: $actor, after: ['package' => $package->name, 'day' => $day->toDateString()], location: $project->location);
    }

    /**
     * A day typed for the work, which cannot be in the future.
     *
     * @throws ValidationException
     */
    private static function day(Project $project, ?string $typed, string $field): CarbonImmutable
    {
        $day = RoughTimeframe::typed($typed, $field, __('Choose the day.'));

        if ($day->greaterThan(RoughTimeframe::today($project->location))) {
            throw ValidationException::withMessages([$field => __('The day cannot be in the future.')]);
        }

        return $day;
    }
}
