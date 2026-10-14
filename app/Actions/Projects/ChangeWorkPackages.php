<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageWindow;
use App\Support\Projects\Readiness;
use App\Support\Projects\RoughTimeframe;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A job's Work Packages and the windows of dates offered to the customer for
 * each (docs/slice-11-schedule-plan.md, step 11a; product-architecture.md,
 * 6.5 and 6.6; decision 144).
 *
 * The Project's Production Manager, or a manager where it is, as for the rest
 * of pre-production. Only on a job that is ready to schedule (question 4).
 * Every change is an audit event saved with it; a window offered or
 * withdrawn is also in the customer's Activity.
 */
class ChangeWorkPackages
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add a package of work: the first is the whole job, and more split it.
     *
     * @param  array<string, mixed>  $package  name, scope, workingDays, minimumCrew.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function addPackage(Project $project, array $package, User $actor): WorkPackage
    {
        return $this->change($project, $actor, function (Project $project) use ($package, $actor): WorkPackage {
            $values = self::readPackage($package);

            if ($project->workPackages()->count() >= WorkPackage::MAX_PER_PROJECT) {
                throw ValidationException::withMessages(['work' => __('A job can be split into :max packages at most.', ['max' => WorkPackage::MAX_PER_PROJECT])]);
            }

            $added = new WorkPackage;
            $added->forceFill([
                'project_id' => $project->id,
                'position' => ((int) $project->workPackages()->max('position')) + 1,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
                ...$values,
            ])->save();

            $this->audit(AuditAction::ProjectWorkPackageAdded, $project, $actor, [], $added->summary());

            return $added;
        });
    }

    /**
     * Change a package's name, scope, working days or crew.
     *
     * @param  array<string, mixed>  $package
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function changePackage(Project $project, int $packageId, array $package, User $actor): WorkPackage
    {
        return $this->change($project, $actor, function (Project $project) use ($packageId, $package, $actor): WorkPackage {
            $existing = self::packageOf($project, $packageId);
            $before = $existing->summary();

            $existing->forceFill(self::readPackage($package));

            if (! $existing->isDirty()) {
                return $existing;
            }

            $existing->save();

            $this->audit(AuditAction::ProjectWorkPackageChanged, $project, $actor, $before, $existing->summary());

            return $existing;
        });
    }

    /**
     * Take a package out, with any windows offered for it. Nothing is
     * confirmed yet in this step, so nothing booked is lost.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function removePackage(Project $project, int $packageId, User $actor): void
    {
        $this->change($project, $actor, function (Project $project) use ($packageId, $actor): void {
            $existing = self::packageOf($project, $packageId);
            $before = [...$existing->summary(), 'windows' => $existing->windows->map(fn (WorkPackageWindow $window): array => self::windowSummary($window))->all()];

            $existing->windows()->delete();
            $existing->delete();

            $this->audit(AuditAction::ProjectWorkPackageRemoved, $project, $actor, $before, []);
        });
    }

    /**
     * Offer the customer a window for a package: its first and its last
     * working day (question 3). An offer, not a booking.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function proposeWindow(Project $project, int $packageId, ?string $firstDay, ?string $lastDay, User $actor): WorkPackageWindow
    {
        return $this->change($project, $actor, function (Project $project) use ($packageId, $firstDay, $lastDay, $actor): WorkPackageWindow {
            $package = self::packageOf($project, $packageId);
            $today = RoughTimeframe::today($project->location);

            $first = RoughTimeframe::typed($firstDay, 'windowFirstDay', __('Choose the first day.'));
            $last = RoughTimeframe::typed($lastDay, 'windowLastDay', __('Choose the last day.'));

            $refusal = match (true) {
                $first->lessThan($today) => ['windowFirstDay' => __('The first day cannot be in the past.')],
                $first->greaterThan($today->addDays(WorkPackageWindow::MAX_DAYS_AHEAD)) => ['windowFirstDay' => __('Offer dates within the next two years.')],
                $last->lessThan($first) => ['windowLastDay' => __('The last day cannot be before the first.')],
                $first->diffInDays($last) >= WorkPackageWindow::MAX_LENGTH_DAYS => ['windowLastDay' => __('A window can be :max days long at most.', ['max' => WorkPackageWindow::MAX_LENGTH_DAYS])],
                WorkPackageWindow::workingDaysBetween($first, $last) === 0 => ['windowLastDay' => __('A window needs at least one working day, Monday to Friday.')],
                $package->windows->contains(fn (WorkPackageWindow $window): bool => $window->first_day->isSameDay($first) && $window->last_day->isSameDay($last)) => ['windowFirstDay' => __('These dates are already offered for this work.')],
                $package->windows->count() >= WorkPackage::MAX_WINDOWS => ['windowFirstDay' => __(':max windows are offered for this work already. Withdraw one first.', ['max' => WorkPackage::MAX_WINDOWS])],
                default => null,
            };

            if ($refusal !== null) {
                throw ValidationException::withMessages($refusal);
            }

            $window = new WorkPackageWindow;
            $window->forceFill([
                'work_package_id' => $package->id,
                'first_day' => $first,
                'last_day' => $last,
                'proposed_by' => $actor->id,
                'proposed_by_name' => $actor->name,
            ])->save();

            $this->audit(AuditAction::ProjectWindowProposed, $project, $actor, [], self::windowSummary($window, $package));

            return $window;
        });
    }

    /**
     * Take back a window that was offered.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function withdrawWindow(Project $project, int $windowId, User $actor): void
    {
        $this->change($project, $actor, function (Project $project) use ($windowId, $actor): void {
            $window = WorkPackageWindow::query()
                ->whereKey($windowId)
                ->whereIn('work_package_id', $project->workPackages()->select('id'))
                ->first()
                ?? throw ValidationException::withMessages(['work' => __('These dates are no longer offered. Reload the page.')]);

            $before = self::windowSummary($window);

            $window->delete();

            $this->audit(AuditAction::ProjectWindowWithdrawn, $project, $actor, $before, []);
        });
    }

    /**
     * Run one change with the Project held, once the person may make it and
     * the job is ready to schedule.
     *
     * @template TResult
     *
     * @param  Closure(Project): TResult  $apply
     * @return TResult
     */
    private function change(Project $project, User $actor, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $apply): mixed {
            // Read as it is now, and held so two changes cannot cross.
            $project = Project::query()->with(['handoff', 'location'])->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $readiness = Readiness::for($project);

            if (! $readiness->isReadyToSchedule()) {
                throw ValidationException::withMessages(['work' => __('This job is not ready to schedule yet. It still needs :things.', ['things' => implode(', ', $readiness->missing())])]);
            }

            return $apply($project);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(AuditAction $action, Project $project, User $actor, array $before, array $after): void
    {
        $this->recordAuditEvent->handle($action, $project, actor: $actor, before: $before, after: $after, location: $project->location);
    }

    /**
     * A package of this job, or a refusal.
     *
     * @throws ValidationException
     */
    private static function packageOf(Project $project, int $packageId): WorkPackage
    {
        return $project->workPackages()->with('windows')->whereKey($packageId)->first()
            ?? throw ValidationException::withMessages(['work' => __('This work package is no longer here. Reload the page.')]);
    }

    /**
     * A window as the audit record keeps it.
     *
     * @return array<string, mixed>
     */
    private static function windowSummary(WorkPackageWindow $window, ?WorkPackage $package = null): array
    {
        return [
            'package' => ($package ?? $window->package)->name,
            'first_day' => $window->first_day->toDateString(),
            'last_day' => $window->last_day->toDateString(),
        ];
    }

    /**
     * Read a package as a person typed it.
     *
     * @param  array<string, mixed>  $package
     * @return array{name: string, scope: string, working_days: int, minimum_crew: int}
     *
     * @throws ValidationException
     */
    private static function readPackage(array $package): array
    {
        $text = fn (string $key): string => trim(is_scalar($package[$key] ?? null) ? (string) $package[$key] : '');
        $number = fn (string $key): ?int => preg_match('/^\d{1,4}$/', $text($key)) === 1 ? (int) $text($key) : null;
        $errors = [];

        if ($text('name') === '') {
            $errors['package.name'] = __('Give the work a name.');
        } elseif (mb_strlen($text('name')) > WorkPackage::NAME_LENGTH) {
            $errors['package.name'] = __('Keep this to :max characters or fewer.', ['max' => WorkPackage::NAME_LENGTH]);
        }

        if ($text('scope') === '') {
            $errors['package.scope'] = __('Say what the work is.');
        } elseif (mb_strlen($text('scope')) > WorkPackage::SCOPE_LENGTH) {
            $errors['package.scope'] = __('Keep this to :max characters or fewer.', ['max' => WorkPackage::SCOPE_LENGTH]);
        }

        $workingDays = $number('workingDays');

        if ($workingDays === null || $workingDays < 1 || $workingDays > WorkPackage::MAX_WORKING_DAYS) {
            $errors['package.workingDays'] = __('Give a whole number of working days, from 1 to :max.', ['max' => WorkPackage::MAX_WORKING_DAYS]);
        }

        $minimumCrew = $number('minimumCrew');

        if ($minimumCrew === null || $minimumCrew < 1 || $minimumCrew > WorkPackage::MAX_CREW) {
            $errors['package.minimumCrew'] = __('Give the smallest crew as a number of people, from 1 to :max.', ['max' => WorkPackage::MAX_CREW]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $text('name'),
            'scope' => $text('scope'),
            'working_days' => (int) $workingDays,
            'minimum_crew' => (int) $minimumCrew,
        ];
    }
}
