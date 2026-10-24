<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\ProjectChangeOrder;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageAward;
use App\Models\WorkPackagePayout;
use App\Models\WorkPackageRevision;
use App\Models\WorkPackageSchedule;
use App\Models\WorkPackageWindow;
use App\Notifications\ProjectNotice;
use App\Support\Money;
use App\Support\Projects\Readiness;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
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
 * withdrawn, dates confirmed and a reschedule are also in the customer's
 * Activity.
 *
 * A job whose packages all have confirmed dates is Scheduled (step 11b); a
 * package added later takes it back to pre-production until it has its own.
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

            $this->audit(AuditAction::ProjectWorkPackageAdded, $project, $actor, [], [...$added->summary(), ...$this->keepStateInStep($project)]);

            return $added;
        });
    }

    /**
     * Change a package's name, scope, working days or crew. Once the work is
     * given to a subcontractor, a change of scope is a revision (slice 18,
     * step 18b; decision 166, question 7): by someone who may see payouts,
     * with why ("revisionReason") and, if it comes from one, the customer
     * change order ("changeOrderId"); it waits for the subcontractor's
     * acceptance and a new work order.
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
            $scopeBefore = $existing->scope;

            $existing->forceFill(self::readPackage($package));

            if (! $existing->isDirty()) {
                return $existing;
            }

            $revised = $existing->isDirty('scope') && $existing->currentAward()->exists();

            if ($revised) {
                Gate::forUser($actor)->authorize('managePayouts', $project);

                [$reason, $changeOrderId] = self::readRevision($project, $package['revisionReason'] ?? null, $package['changeOrderId'] ?? null, 'package.revisionReason', 'package.changeOrderId', __('This work is given to a subcontractor. Say why the scope changes; they accept the revision, and the work order is sent again.'));
            }

            $existing->save();

            if ($revised) {
                $this->revise($project, $existing, WorkPackageRevision::KIND_SCOPE, ['scope_before' => $scopeBefore, 'scope_after' => $existing->scope], $reason, $changeOrderId, $actor);
            }

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

            if ($existing->isScheduled()) {
                throw ValidationException::withMessages(['work' => __('This work has dates the customer confirmed. Reschedule it rather than removing it.')]);
            }

            if ($existing->currentAward()->exists()) {
                throw ValidationException::withMessages(['work' => __('This work is given to a subcontractor. Take the award back before removing it.')]);
            }

            $before = [...$existing->summary(), 'windows' => $existing->windows->map(fn (WorkPackageWindow $window): array => self::windowSummary($window))->all()];

            $existing->windows()->delete();
            $existing->delete();

            $this->audit(AuditAction::ProjectWorkPackageRemoved, $project, $actor, $before, $this->keepStateInStep($project));
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

            if ($package->isScheduled()) {
                throw ValidationException::withMessages(['work' => __('This work has dates the customer confirmed. Reschedule it instead.')]);
            }

            [$first, $last] = self::readDays($project, $firstDay, $lastDay, 'windowFirstDay', 'windowLastDay');

            $refusal = match (true) {
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
     * Record that the customer confirmed one of the windows offered for a
     * package. The package gets those dates; once every package has its
     * dates, the job is Scheduled and the salesperson is told in Backoffice.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function confirmWindow(Project $project, int $windowId, ?string $confirmedBy, ?string $method, ?string $confirmedOn, User $actor): WorkPackageSchedule
    {
        return $this->change($project, $actor, function (Project $project) use ($windowId, $confirmedBy, $method, $confirmedOn, $actor): WorkPackageSchedule {
            $window = self::windowOf($project, $windowId);
            $package = $window->package;

            if ($package->isScheduled()) {
                throw ValidationException::withMessages(['work' => __('This work already has dates the customer confirmed. Reschedule it to change them.')]);
            }

            if ($window->first_day->lessThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['work' => __('These dates have started or passed. Offer new ones.')]);
            }

            // Given to a subcontractor, the customer confirms the days the subcontractor took (7.8).
            $award = $package->currentAward()->with('subcontractor')->first();

            if ($award !== null && (! $award->first_day->isSameDay($window->first_day) || ! $award->last_day->isSameDay($window->last_day))) {
                throw ValidationException::withMessages(['work' => __(':name took :days. Confirm those dates with the customer, or take the award back first.', [
                    'name' => $award->subcontractor->business_name,
                    'days' => WorkPackageWindow::describe($award->first_day, $award->last_day),
                ])]);
            }

            $confirmation = self::readConfirmation($project, $confirmedBy, $method, $confirmedOn);
            $wasScheduled = $project->production_state === ProjectProductionState::Scheduled;

            $schedule = $this->schedule($package, CarbonImmutable::instance($window->first_day), CarbonImmutable::instance($window->last_day), $confirmation, null, $actor, $window->id);

            $this->audit(AuditAction::ProjectScheduleConfirmed, $project, $actor, [], [...self::scheduleSummary($schedule, $package), ...$this->keepStateInStep($project)]);

            if (! $wasScheduled) {
                $this->tellTheSalesperson($project, ProjectNotice::SCHEDULED, $actor);
            }

            return $schedule;
        });
    }

    /**
     * Change the confirmed dates of a package, with a reason every time
     * (decision 144, question 7). The dates it had are kept. The salesperson
     * is told when the job is Scheduled.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function reschedule(Project $project, int $packageId, ?string $firstDay, ?string $lastDay, ?string $reason, ?string $confirmedBy, ?string $method, ?string $confirmedOn, User $actor): WorkPackageSchedule
    {
        return $this->change($project, $actor, function (Project $project) use ($packageId, $firstDay, $lastDay, $reason, $confirmedBy, $method, $confirmedOn, $actor): WorkPackageSchedule {
            $package = self::packageOf($project, $packageId);

            if (! $package->isScheduled()) {
                throw ValidationException::withMessages(['work' => __('This work has no confirmed dates yet. Offer dates and record the customer\'s confirmation.')]);
            }

            [$first, $last] = self::readDays($project, $firstDay, $lastDay, 'scheduleFirstDay', 'scheduleLastDay');
            $reason = trim((string) $reason);

            $errors = match (true) {
                $package->scheduled_first_day?->isSameDay($first) && $package->scheduled_last_day?->isSameDay($last) => ['scheduleFirstDay' => __('These are the dates it has now.')],
                $reason === '' => ['rescheduleReason' => __('Say why the dates are changing.')],
                mb_strlen($reason) > WorkPackageSchedule::REASON_LENGTH => ['rescheduleReason' => __('Keep this to :max characters or fewer.', ['max' => WorkPackageSchedule::REASON_LENGTH])],
                default => null,
            };

            if ($errors !== null) {
                throw ValidationException::withMessages($errors);
            }

            $confirmation = self::readConfirmation($project, $confirmedBy, $method, $confirmedOn);
            $before = ['package' => $package->name, 'first_day' => $package->scheduled_first_day?->toDateString(), 'last_day' => $package->scheduled_last_day?->toDateString()];

            $schedule = $this->schedule($package, $first, $last, $confirmation, $reason, $actor, null);

            $this->recordAuditEvent->handle(
                AuditAction::ProjectRescheduled,
                $project,
                actor: $actor,
                before: $before,
                after: [...self::scheduleSummary($schedule, $package), ...$this->keepStateInStep($project)],
                location: $project->location,
                reason: $reason,
            );

            if ($project->production_state === ProjectProductionState::Scheduled) {
                $this->tellTheSalesperson($project, ProjectNotice::RESCHEDULED, $actor);
            }

            return $schedule;
        });
    }

    /**
     * Set what Banana will pay for a package (docs/slice-13-giving-work-plan.md,
     * step 13a; decision 151, questions 1 and 2). Only someone who may see
     * payouts. Every amount is kept; saving the same amount records nothing.
     * Until the work is awarded, a reason is optional.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function setPayout(Project $project, int $packageId, ?string $amount, ?string $reason, User $actor, ?int $changeOrderId = null): WorkPackage
    {
        return $this->change($project, $actor, function (Project $project) use ($packageId, $amount, $reason, $actor, $changeOrderId): WorkPackage {
            Gate::forUser($actor)->authorize('managePayouts', $project);

            $package = self::packageOf($project, $packageId);
            $cents = Money::toCents($amount)
                ?? throw ValidationException::withMessages(['payoutAmount' => __('Give the payout in dollars, like 4,250 or 4,250.50.')]);
            $reason = trim((string) $reason);

            if (mb_strlen($reason) > WorkPackagePayout::REASON_LENGTH) {
                throw ValidationException::withMessages(['payoutReason' => __('Keep this to :max characters or fewer.', ['max' => WorkPackagePayout::REASON_LENGTH])]);
            }

            if ($package->payout_cents === $cents) {
                return $package;
            }

            // Once the work is given out, a change needs a reason (decision 151, question 2), and is a revision (decision 166).
            $revised = $package->currentAward()->exists();

            if ($revised) {
                [$reason, $changeOrderId] = self::readRevision($project, $reason, $changeOrderId, 'payoutReason', 'payoutChangeOrderId', __('This work is given to a subcontractor. Say why the payout changes.'));
            }

            $before = $package->payout_cents;

            $payout = new WorkPackagePayout;
            $payout->forceFill([
                'work_package_id' => $package->id,
                'amount_cents' => $cents,
                'reason' => $reason === '' ? null : $reason,
                'set_by' => $actor->id,
                'set_by_name' => $actor->name,
            ])->save();

            $package->forceFill(['payout_cents' => $cents])->save();

            // Audit only: a payout is never in the customer's Activity.
            $this->recordAuditEvent->handle(
                AuditAction::ProjectPayoutSet,
                $project,
                actor: $actor,
                before: $before === null ? [] : ['package' => $package->name, 'payout_cents' => $before],
                after: ['package' => $package->name, 'payout_cents' => $cents],
                location: $project->location,
                reason: $payout->reason,
            );

            if ($revised) {
                $this->revise($project, $package, WorkPackageRevision::KIND_PAYOUT, ['payout_before_cents' => $before, 'payout_after_cents' => $cents], $reason, $changeOrderId, $actor);
            }

            return $package;
        });
    }

    /**
     * Read why a revision is made, and the customer change order it comes
     * from: one of this job's, signed, or none.
     *
     * @return array{0: string, 1: int|null}
     *
     * @throws ValidationException
     */
    private static function readRevision(Project $project, mixed $reason, mixed $changeOrderId, string $reasonField, string $changeOrderField, string $missing): array
    {
        $reason = trim(is_scalar($reason) ? (string) $reason : '');

        if ($reason === '') {
            throw ValidationException::withMessages([$reasonField => $missing]);
        }

        if (mb_strlen($reason) > WorkPackageRevision::REASON_LENGTH) {
            throw ValidationException::withMessages([$reasonField => __('Keep this to :max characters or fewer.', ['max' => WorkPackageRevision::REASON_LENGTH])]);
        }

        $changeOrderId = is_numeric($changeOrderId) ? (int) $changeOrderId : null;

        if ($changeOrderId !== null && ! ProjectChangeOrder::query()->where('project_id', $project->id)->where('status', ProjectChangeOrder::STATUS_SIGNED)->whereKey($changeOrderId)->exists()) {
            throw ValidationException::withMessages([$changeOrderField => __('Choose one of this job\'s signed change orders.')]);
        }

        return [$reason, $changeOrderId];
    }

    /**
     * Keep a revision, and say so in the job's history; the payout itself
     * stays in the audit record only.
     *
     * @param  array<string, mixed>  $change
     */
    private function revise(Project $project, WorkPackage $package, string $kind, array $change, string $reason, ?int $changeOrderId, User $actor): void
    {
        $revision = new WorkPackageRevision;
        $revision->forceFill([
            'work_package_id' => $package->id,
            'kind' => $kind,
            ...$change,
            'reason' => $reason,
            'project_change_order_id' => $changeOrderId,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectWorkRevised,
            $project,
            actor: $actor,
            after: [
                'package' => $package->name,
                'kind' => $kind,
                'change_order' => $changeOrderId === null ? null : ProjectChangeOrder::query()->find($changeOrderId)?->number,
            ],
            location: $project->location,
            reason: $reason,
        );
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

            if (WorkPackageAward::query()->whereNull('ended_at')->where('work_package_window_id', $window->id)->exists()) {
                throw ValidationException::withMessages(['work' => __('A subcontractor took these dates. Take the award back before withdrawing them.')]);
            }

            $before = self::windowSummary($window);

            $window->delete();

            $this->audit(AuditAction::ProjectWindowWithdrawn, $project, $actor, $before, []);
        });
    }

    /**
     * Give a package its dates and keep them in its history.
     *
     * @param  array{confirmed_by_customer: string, confirmation_method: ColorApprovalMethod, confirmed_on: CarbonImmutable}  $confirmation
     */
    private function schedule(WorkPackage $package, CarbonImmutable $first, CarbonImmutable $last, array $confirmation, ?string $reason, User $actor, ?int $windowId): WorkPackageSchedule
    {
        $schedule = new WorkPackageSchedule;
        $schedule->forceFill([
            'work_package_id' => $package->id,
            'first_day' => $first,
            'last_day' => $last,
            'work_package_window_id' => $windowId,
            ...$confirmation,
            'reason' => $reason,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ])->save();

        $package->forceFill(['scheduled_first_day' => $first, 'scheduled_last_day' => $last])->save();

        return $schedule;
    }

    /**
     * Make the job Scheduled when every package has its dates, and take it
     * back to pre-production when one does not. Says what changed, for the
     * audit record.
     *
     * @return array<string, string>
     */
    private function keepStateInStep(Project $project): array
    {
        $packages = $project->workPackages()->get();
        $allScheduled = $packages->isNotEmpty() && $packages->every(fn (WorkPackage $package): bool => $package->isScheduled());

        // Once work has started, the work's own progress moves the state (slice 14), not its dates.
        if (! in_array($project->production_state, [ProjectProductionState::PreProduction, ProjectProductionState::Scheduled], true)) {
            return [];
        }

        $state = match (true) {
            $allScheduled => ProjectProductionState::Scheduled,
            $project->production_state === ProjectProductionState::Scheduled => ProjectProductionState::PreProduction,
            default => $project->production_state,
        };

        if ($state === $project->production_state) {
            return [];
        }

        $project->forceFill(['production_state' => $state])->save();

        return ['production' => $state->value];
    }

    /**
     * Tell the salesperson who sold the job, unless they did it themselves.
     * The days are named; the customer and the address are not.
     *
     * @param  ProjectNotice::SCHEDULED|ProjectNotice::RESCHEDULED  $kind
     */
    private function tellTheSalesperson(Project $project, string $kind, User $actor): void
    {
        $project->unsetRelation('workPackages');
        $days = $project->scheduledDays();

        if ($days === null || $project->salesperson_id === null || $project->salesperson_id === $actor->id) {
            return;
        }

        User::query()->find($project->salesperson_id)?->notify(new ProjectNotice($project->id, $kind, name: WorkPackageWindow::describe($days[0], $days[1])));
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
     * A window offered for one of this job's packages, or a refusal.
     *
     * @throws ValidationException
     */
    private static function windowOf(Project $project, int $windowId): WorkPackageWindow
    {
        return WorkPackageWindow::query()
            ->with('package')
            ->whereKey($windowId)
            ->whereIn('work_package_id', $project->workPackages()->select('id'))
            ->first()
            ?? throw ValidationException::withMessages(['work' => __('These dates are no longer offered. Reload the page.')]);
    }

    /**
     * Confirmed dates as the audit record keeps them.
     *
     * @return array<string, mixed>
     */
    private static function scheduleSummary(WorkPackageSchedule $schedule, WorkPackage $package): array
    {
        return [
            'package' => $package->name,
            'first_day' => $schedule->first_day->toDateString(),
            'last_day' => $schedule->last_day->toDateString(),
            'confirmed_by' => $schedule->confirmed_by_customer,
            'method' => $schedule->confirmation_method->value,
            'confirmed_on' => $schedule->confirmed_on->toDateString(),
        ];
    }

    /**
     * Two days a person chose: not in the past, within two years, the right
     * way round, not too long and with a weekday in them.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private static function readDays(Project $project, ?string $firstDay, ?string $lastDay, string $firstField, string $lastField): array
    {
        $today = RoughTimeframe::today($project->location);

        $first = RoughTimeframe::typed($firstDay, $firstField, __('Choose the first day.'));
        $last = RoughTimeframe::typed($lastDay, $lastField, __('Choose the last day.'));

        $refusal = match (true) {
            $first->lessThan($today) => [$firstField => __('The first day cannot be in the past.')],
            $first->greaterThan($today->addDays(WorkPackageWindow::MAX_DAYS_AHEAD)) => [$firstField => __('Offer dates within the next two years.')],
            $last->lessThan($first) => [$lastField => __('The last day cannot be before the first.')],
            $first->diffInDays($last) >= WorkPackageWindow::MAX_LENGTH_DAYS => [$lastField => __('A window can be :max days long at most.', ['max' => WorkPackageWindow::MAX_LENGTH_DAYS])],
            WorkPackageWindow::workingDaysBetween($first, $last) === 0 => [$lastField => __('A window needs at least one working day, Monday to Friday.')],
            default => null,
        };

        if ($refusal !== null) {
            throw ValidationException::withMessages($refusal);
        }

        return [$first, $last];
    }

    /**
     * Who confirmed, how and on which day, as a person typed them. The day
     * cannot be in the future, in the job's own Location.
     *
     * @return array{confirmed_by_customer: string, confirmation_method: ColorApprovalMethod, confirmed_on: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private static function readConfirmation(Project $project, ?string $confirmedBy, ?string $method, ?string $confirmedOn): array
    {
        $confirmedBy = trim((string) $confirmedBy);
        $how = ColorApprovalMethod::tryFrom((string) $method);
        $errors = [];
        $day = null;

        if ($confirmedBy === '') {
            $errors['confirmedBy'] = __('Say who confirmed the dates.');
        } elseif (mb_strlen($confirmedBy) > WorkPackageSchedule::NAME_LENGTH) {
            $errors['confirmedBy'] = __('Keep this to :max characters or fewer.', ['max' => WorkPackageSchedule::NAME_LENGTH]);
        }

        if ($how === null) {
            $errors['confirmationMethod'] = __('Choose how they confirmed.');
        }

        $typed = trim((string) $confirmedOn);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) === 1) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $typed, 'UTC');
            $day = $parsed instanceof CarbonImmutable && $parsed->toDateString() === $typed ? $parsed : null;
        }

        if ($day === null) {
            $errors['confirmedOn'] = __('Choose the day they confirmed.');
        } elseif ($day->greaterThan(RoughTimeframe::today($project->location))) {
            $errors['confirmedOn'] = __('The day they confirmed cannot be in the future.');
        }

        if ($errors !== [] || $how === null || $day === null) {
            throw ValidationException::withMessages($errors);
        }

        return ['confirmed_by_customer' => $confirmedBy, 'confirmation_method' => $how, 'confirmed_on' => $day];
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
