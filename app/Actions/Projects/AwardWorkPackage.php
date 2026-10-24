<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\SubcontractorStanding;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageAward;
use App\Models\WorkPackageWindow;
use App\Support\Projects\Readiness;
use App\Support\Projects\RoughTimeframe;
use App\Support\Subcontractors\Compliance;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Give a Work Package to one subcontractor, take it back, and record their
 * acceptance again after the payout changes (docs/slice-13-giving-work-plan.md,
 * step 13b; product-architecture.md, 6.6, 7.4 "direct to one subcontractor"
 * and 7.8; decision 151, questions 2 to 6).
 *
 * The Project's Production Manager, or a manager there, who may also see
 * payouts. Only on a job that is ready to schedule. In the customer's
 * Activity: the work given, by business name, and an award taken back or
 * accepted again; the payout never.
 */
class AwardWorkPackage
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Give a package to a subcontractor who is Active, compliant and works at
     * the job's Location (question 3). They take one of the windows offered
     * to the customer, or the confirmed dates when there are some (question
     * 4). Who accepted, how and on which day are recorded (question 5).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function award(Project $project, int $packageId, ?int $subcontractorId, ?int $windowId, ?string $acceptedBy, ?string $method, ?string $acceptedOn, User $actor): WorkPackageAward
    {
        return $this->held($project, $actor, function (Project $project) use ($packageId, $subcontractorId, $windowId, $acceptedBy, $method, $acceptedOn, $actor): WorkPackageAward {
            $package = self::packageOf($project, $packageId);

            if ($package->payout_cents === null) {
                throw ValidationException::withMessages(['work' => __('Set the payout before giving the work out.')]);
            }

            if ($package->currentAward !== null) {
                throw ValidationException::withMessages(['work' => __(':name has this work. Take it back first to give it to someone else.', ['name' => $package->currentAward->subcontractor->business_name])]);
            }

            $subcontractor = self::eligible($project, $subcontractorId);
            [$first, $last, $window] = self::days($project, $package, $windowId);
            $acceptance = self::acceptance($project, $acceptedBy, $method, $acceptedOn);

            $award = $this->write($package, $subcontractor, $first, $last, $window?->id, $acceptance, $actor);

            $this->recordAuditEvent->handle(AuditAction::ProjectWorkAwarded, $project, actor: $actor, after: self::summary($award, $package, $subcontractor), location: $project->location);

            return $award;
        });
    }

    /**
     * Take back an award, with a reason and whose doing it was (question 6).
     * The award is kept, ended.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function takeBack(Project $project, int $packageId, ?string $cause, ?string $reason, User $actor): WorkPackageAward
    {
        return $this->held($project, $actor, function (Project $project) use ($packageId, $cause, $reason, $actor): WorkPackageAward {
            $package = self::packageOf($project, $packageId);
            $award = $package->currentAward
                ?? throw ValidationException::withMessages(['work' => __('Nobody has this work now. Reload the page.')]);

            if (! in_array($cause, [WorkPackageAward::ENDED_SUBCONTRACTOR_WITHDREW, WorkPackageAward::ENDED_CUSTOMER_OR_BANANA], true)) {
                throw ValidationException::withMessages(['awardEndCause' => __('Choose whose doing it was.')]);
            }

            $reason = trim((string) $reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['awardEndReason' => __('Say why.')]);
            }

            if (mb_strlen($reason) > WorkPackageAward::REASON_LENGTH) {
                throw ValidationException::withMessages(['awardEndReason' => __('Keep this to :max characters or fewer.', ['max' => WorkPackageAward::REASON_LENGTH])]);
            }

            $this->end($award, $cause, $reason, $actor);

            $this->recordAuditEvent->handle(
                AuditAction::ProjectAwardEnded,
                $project,
                actor: $actor,
                before: ['package' => $package->name, 'subcontractor' => $award->subcontractor->business_name],
                after: ['cause' => $cause],
                location: $project->location,
                reason: $reason,
            );

            return $award;
        });
    }

    /**
     * Record the subcontractor's acceptance again after the payout changed
     * (question 2). The award at the old payout is kept, ended, and a new one
     * starts at the payout now, for the same days.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function acceptAgain(Project $project, int $packageId, ?string $acceptedBy, ?string $method, ?string $acceptedOn, User $actor): WorkPackageAward
    {
        return $this->held($project, $actor, function (Project $project) use ($packageId, $acceptedBy, $method, $acceptedOn, $actor): WorkPackageAward {
            $package = self::packageOf($project, $packageId);
            $award = $package->currentAward
                ?? throw ValidationException::withMessages(['work' => __('Nobody has this work now. Reload the page.')]);

            if ($award->matches($package)) {
                throw ValidationException::withMessages(['work' => __('They accepted the work as it is now. Nothing needs recording.')]);
            }

            $acceptance = self::acceptance($project, $acceptedBy, $method, $acceptedOn);

            $this->end($award, WorkPackageAward::ENDED_PAYOUT_CHANGED, null, $actor);
            $again = $this->write($package, $award->subcontractor, CarbonImmutable::instance($award->first_day), CarbonImmutable::instance($award->last_day), $award->work_package_window_id, $acceptance, $actor);

            $this->recordAuditEvent->handle(AuditAction::ProjectAwardAcceptedAgain, $project, actor: $actor, after: self::summary($again, $package, $award->subcontractor), location: $project->location);

            return $again;
        });
    }

    /**
     * The subcontractors this package can be given to: Active, compliant,
     * working at the job's Location, by business name.
     *
     * @return Collection<int, Subcontractor>
     */
    public static function candidates(Project $project): Collection
    {
        return Subcontractor::query()
            ->where('standing', SubcontractorStanding::Active)
            ->whereHas('locations', fn ($locations) => $locations->whereKey($project->location_id))
            ->with(['locations', 'complianceRecords'])
            ->orderBy('business_name')
            ->get()
            ->filter(fn (Subcontractor $subcontractor): bool => Compliance::for($subcontractor)->isCompliant())
            ->values();
    }

    /**
     * @param  array{accepted_by: string, acceptance_method: ColorApprovalMethod, accepted_on: CarbonImmutable}  $acceptance
     */
    private function write(WorkPackage $package, Subcontractor $subcontractor, CarbonImmutable $first, CarbonImmutable $last, ?int $windowId, array $acceptance, User $actor): WorkPackageAward
    {
        $award = new WorkPackageAward;
        $award->forceFill([
            'work_package_id' => $package->id,
            'subcontractor_id' => $subcontractor->id,
            'payout_cents' => $package->payout_cents,
            'accepted_scope' => $package->scope,
            'first_day' => $first,
            'last_day' => $last,
            'work_package_window_id' => $windowId,
            ...$acceptance,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ])->save();

        return $award;
    }

    private function end(WorkPackageAward $award, string $cause, ?string $reason, User $actor): void
    {
        $award->forceFill([
            'ended_at' => now(),
            'ended_cause' => $cause,
            'ended_reason' => $reason,
            'ended_by' => $actor->id,
            'ended_by_name' => $actor->name,
        ])->save();
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
    private function held(Project $project, User $actor, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $apply): mixed {
            // Read as it is now, and held so two awards cannot cross.
            $project = Project::query()->with(['handoff', 'location'])->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePayouts', $project);

            $readiness = Readiness::for($project);

            if (! $readiness->isReadyToSchedule()) {
                throw ValidationException::withMessages(['work' => __('This job is not ready to schedule yet. It still needs :things.', ['things' => implode(', ', $readiness->missing())])]);
            }

            return $apply($project);
        });
    }

    /**
     * @throws ValidationException
     */
    private static function packageOf(Project $project, int $packageId): WorkPackage
    {
        return $project->workPackages()->with(['windows', 'currentAward.subcontractor'])->whereKey($packageId)->first()
            ?? throw ValidationException::withMessages(['work' => __('This work package is no longer here. Reload the page.')]);
    }

    /**
     * The subcontractor chosen, if the work can be given to them.
     *
     * @throws ValidationException
     */
    private static function eligible(Project $project, ?int $subcontractorId): Subcontractor
    {
        if ($subcontractorId === null) {
            throw ValidationException::withMessages(['awardSubcontractorId' => __('Choose the subcontractor.')]);
        }

        $subcontractor = Subcontractor::query()->with(['locations', 'complianceRecords'])->find($subcontractorId);

        return match (true) {
            $subcontractor === null, ! $subcontractor->locations->contains('id', $project->location_id) => throw ValidationException::withMessages(['awardSubcontractorId' => __('Choose a subcontractor who works at :location.', ['location' => $project->location->name])]),
            $subcontractor->standing !== SubcontractorStanding::Active => throw ValidationException::withMessages(['awardSubcontractorId' => __(':name is :standing, not Active.', ['name' => $subcontractor->business_name, 'standing' => mb_strtolower(__($subcontractor->standing->label()))])]),
            ! Compliance::for($subcontractor)->isCompliant() => throw ValidationException::withMessages(['awardSubcontractorId' => __(':name is pending compliance: :things.', ['name' => $subcontractor->business_name, 'things' => implode(', ', Compliance::for($subcontractor)->missing())])]),
            default => $subcontractor,
        };
    }

    /**
     * The days they take: the confirmed dates, or one of the windows offered.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: WorkPackageWindow|null}
     *
     * @throws ValidationException
     */
    private static function days(Project $project, WorkPackage $package, ?int $windowId): array
    {
        if ($package->isScheduled()) {
            return [CarbonImmutable::instance($package->scheduled_first_day), CarbonImmutable::instance($package->scheduled_last_day), null];
        }

        $window = $windowId === null ? null : $package->windows->firstWhere('id', $windowId);

        if ($window === null) {
            throw ValidationException::withMessages(['awardWindowId' => $package->windows->isEmpty() ? __('Offer dates to the customer first.') : __('Choose the dates they take.')]);
        }

        if ($window->first_day->lessThan(RoughTimeframe::today($project->location))) {
            throw ValidationException::withMessages(['awardWindowId' => __('These dates have started or passed. Offer new ones.')]);
        }

        return [CarbonImmutable::instance($window->first_day), CarbonImmutable::instance($window->last_day), $window];
    }

    /**
     * Who accepted for the subcontractor, how and on which day: as the
     * customer's confirmations are read.
     *
     * @return array{accepted_by: string, acceptance_method: ColorApprovalMethod, accepted_on: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private static function acceptance(Project $project, ?string $acceptedBy, ?string $method, ?string $acceptedOn): array
    {
        $acceptedBy = trim((string) $acceptedBy);
        $how = ColorApprovalMethod::tryFrom((string) $method);
        $typed = trim((string) $acceptedOn);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $typed, 'UTC') : null;
        $day = $day instanceof CarbonImmutable && $day->toDateString() === $typed ? $day : null;
        $errors = [];

        if ($acceptedBy === '') {
            $errors['acceptedBy'] = __('Say who accepted for the subcontractor.');
        } elseif (mb_strlen($acceptedBy) > Subcontractor::NAME_LENGTH) {
            $errors['acceptedBy'] = __('Keep this to :max characters or fewer.', ['max' => Subcontractor::NAME_LENGTH]);
        }

        if ($how === null) {
            $errors['acceptanceMethod'] = __('Choose how they accepted.');
        }

        if ($day === null) {
            $errors['acceptedOn'] = __('Choose the day they accepted.');
        } elseif ($day->greaterThan(RoughTimeframe::today($project->location))) {
            $errors['acceptedOn'] = __('The day they accepted cannot be in the future.');
        }

        if ($errors !== [] || $how === null || $day === null) {
            throw ValidationException::withMessages($errors);
        }

        return ['accepted_by' => $acceptedBy, 'acceptance_method' => $how, 'accepted_on' => $day];
    }

    /**
     * What the customer's Activity and the audit record keep: no payout.
     *
     * @return array<string, mixed>
     */
    private static function summary(WorkPackageAward $award, WorkPackage $package, Subcontractor $subcontractor): array
    {
        return [
            'package' => $package->name,
            'subcontractor' => $subcontractor->business_name,
            'first_day' => $award->first_day->toDateString(),
            'last_day' => $award->last_day->toDateString(),
            'accepted_by' => $award->accepted_by,
            'method' => $award->acceptance_method->value,
            'accepted_on' => $award->accepted_on->toDateString(),
        ];
    }
}
