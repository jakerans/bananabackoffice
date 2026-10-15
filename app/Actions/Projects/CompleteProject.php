<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\ProjectCompletionStep;
use App\Models\PunchItem;
use App\Models\User;
use App\Models\WorkPackageAward;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The walkthrough and the customer's signoff (docs/slice-14-work-and-closeout-plan.md,
 * step 14b; product-architecture.md, 6.8; decision 155, questions 5 to 8):
 * the internal inspection first, then the customer walkthrough and its punch
 * list, then the customer's 100%-satisfied signoff.
 *
 * The Project's Production Manager, or a manager there, once the work is
 * complete. The inspection, walkthrough and signoff are in the customer's
 * Activity; punch items are in the audit record only.
 */
class CompleteProject
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * The internal inspection was done (question 5): the day and who.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function inspect(Project $project, ?string $day, ?string $inspector, ?string $note, User $actor): ProjectCompletionStep
    {
        return $this->held($project, $actor, function (Project $project) use ($day, $inspector, $note, $actor): ProjectCompletionStep {
            return $this->step($project, ProjectCompletionStep::KIND_INSPECTION, $day, $inspector, null, null, $note, $actor, AuditAction::ProjectInspected);
        });
    }

    /**
     * The customer walkthrough: the day, and who conducted it, the Production
     * Manager or a subcontractor of the job they chose (question 6). After the
     * inspection.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function walkthrough(Project $project, ?string $day, ?string $conductor, ?int $subcontractorId, ?string $note, User $actor): ProjectCompletionStep
    {
        return $this->held($project, $actor, function (Project $project) use ($day, $conductor, $subcontractorId, $note, $actor): ProjectCompletionStep {
            if (self::latest($project, ProjectCompletionStep::KIND_INSPECTION) === null) {
                throw ValidationException::withMessages(['completion' => __('Record the internal inspection first.')]);
            }

            if ($subcontractorId !== null && ! self::jobSubcontractorIds($project)->contains($subcontractorId)) {
                throw ValidationException::withMessages(['walkthroughSubcontractorId' => __('Choose a subcontractor who did this job.')]);
            }

            return $this->step($project, ProjectCompletionStep::KIND_WALKTHROUGH, $day, $conductor, $subcontractorId, null, $note, $actor, AuditAction::ProjectWalkthroughDone);
        });
    }

    /**
     * Add something to touch up, with who fixes it (question 7). After the
     * walkthrough.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function addPunchItem(Project $project, ?string $description, ?string $fixer, User $actor): PunchItem
    {
        return $this->held($project, $actor, function (Project $project) use ($description, $fixer, $actor): PunchItem {
            if (self::latest($project, ProjectCompletionStep::KIND_WALKTHROUGH) === null) {
                throw ValidationException::withMessages(['completion' => __('Record the customer walkthrough first.')]);
            }

            $description = trim((string) $description);

            if ($description === '') {
                throw ValidationException::withMessages(['punchDescription' => __('Say what needs touching up.')]);
            }

            if (mb_strlen($description) > PunchItem::DESCRIPTION_LENGTH) {
                throw ValidationException::withMessages(['punchDescription' => __('Keep this to :max characters or fewer.', ['max' => PunchItem::DESCRIPTION_LENGTH])]);
            }

            if (! in_array($fixer, [PunchItem::FIXER_SUBCONTRACTOR, PunchItem::FIXER_BANANA], true)) {
                throw ValidationException::withMessages(['punchFixer' => __('Choose who fixes it.')]);
            }

            $item = new PunchItem;
            $item->forceFill(['project_id' => $project->id, 'description' => $description, 'fixer' => $fixer, 'created_by' => $actor->id, 'created_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(AuditAction::ProjectPunchItemAdded, $project, actor: $actor, after: ['punch_item' => $description, 'fixer' => $fixer], location: $project->location);

            return $item;
        });
    }

    /**
     * Tick a punch item done.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function finishPunchItem(Project $project, int $itemId, ?string $day, User $actor): PunchItem
    {
        return $this->held($project, $actor, function (Project $project) use ($itemId, $day, $actor): PunchItem {
            $item = PunchItem::query()->where('project_id', $project->id)->whereNull('done_on')->find($itemId)
                ?? throw ValidationException::withMessages(['completion' => __('This punch item is done or no longer here. Reload the page.')]);

            $done = self::day($project, $day, 'punchDoneOn');

            $item->forceFill(['done_on' => $done, 'done_by' => $actor->id, 'done_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(AuditAction::ProjectPunchItemDone, $project, actor: $actor, after: ['punch_item' => $item->description, 'day' => $done->toDateString()], location: $project->location);

            return $item;
        });
    }

    /**
     * The customer's signoff (question 8): who, how, the day, and whether
     * they are 100% satisfied. Satisfied, with every punch item done, the job
     * is Signed off; not fully satisfied, what is wrong is recorded and the
     * job stays at Work complete.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function signOff(Project $project, ?string $signedBy, ?string $method, ?string $day, bool $satisfied, ?string $note, User $actor): ProjectCompletionStep
    {
        return $this->held($project, $actor, function (Project $project) use ($signedBy, $method, $day, $satisfied, $note, $actor): ProjectCompletionStep {
            if (self::latest($project, ProjectCompletionStep::KIND_WALKTHROUGH) === null) {
                throw ValidationException::withMessages(['completion' => __('Record the customer walkthrough first.')]);
            }

            $how = ColorApprovalMethod::tryFrom((string) $method)
                ?? throw ValidationException::withMessages(['signoffMethod' => __('Choose how they signed off.')]);

            if (! $satisfied) {
                if (trim((string) $note) === '') {
                    throw ValidationException::withMessages(['signoffNote' => __('Say what they are not satisfied with.')]);
                }

                return $this->step($project, ProjectCompletionStep::KIND_NOT_SATISFIED, $day, $signedBy, null, $how, $note, $actor, AuditAction::ProjectNotSatisfied);
            }

            $open = PunchItem::query()->where('project_id', $project->id)->whereNull('done_on')->count();

            if ($open > 0) {
                throw ValidationException::withMessages(['completion' => trans_choice('Tick the :count open punch item done before the customer signs off.|Tick the :count open punch items done before the customer signs off.', $open)]);
            }

            $step = $this->step($project, ProjectCompletionStep::KIND_SIGNOFF, $day, $signedBy, null, $how, $note, $actor, AuditAction::ProjectSignedOff);

            $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

            return $step;
        });
    }

    /**
     * The latest step of a kind.
     */
    public static function latest(Project $project, string $kind): ?ProjectCompletionStep
    {
        return ProjectCompletionStep::query()->where('project_id', $project->id)->where('kind', $kind)->latest('id')->first();
    }

    /**
     * The subcontractors who did work on this job.
     *
     * @return Collection<int, int>
     */
    public static function jobSubcontractorIds(Project $project): Collection
    {
        return WorkPackageAward::query()
            ->whereIn('work_package_id', $project->workPackages()->select('id'))
            ->where(fn ($awards) => $awards->whereNull('ended_at')->orWhere('ended_cause', WorkPackageAward::ENDED_PAYOUT_CHANGED))
            ->pluck('subcontractor_id')
            ->unique()
            ->values();
    }

    private function step(Project $project, string $kind, ?string $day, ?string $person, ?int $subcontractorId, ?ColorApprovalMethod $method, ?string $note, User $actor, AuditAction $action): ProjectCompletionStep
    {
        $person = trim((string) $person);
        $note = trim((string) $note);

        if ($person === '') {
            throw ValidationException::withMessages(['completionPerson' => __('Say who.')]);
        }

        if (mb_strlen($person) > 120) {
            throw ValidationException::withMessages(['completionPerson' => __('Keep this to :max characters or fewer.', ['max' => 120])]);
        }

        if (mb_strlen($note) > ProjectCompletionStep::NOTE_LENGTH) {
            throw ValidationException::withMessages(['completionNote' => __('Keep this to :max characters or fewer.', ['max' => ProjectCompletionStep::NOTE_LENGTH])]);
        }

        $on = self::day($project, $day, 'completionDay');

        $step = new ProjectCompletionStep;
        $step->forceFill([
            'project_id' => $project->id,
            'kind' => $kind,
            'day' => $on,
            'person' => $person,
            'subcontractor_id' => $subcontractorId,
            'method' => $method,
            'note' => $note === '' ? null : $note,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle($action, $project, actor: $actor, after: ['day' => $on->toDateString(), 'person' => $person], location: $project->location, reason: $step->note);

        return $step;
    }

    /**
     * Run one change with the Project held, once the person may make it and
     * the work is complete.
     *
     * @template TResult
     *
     * @param  Closure(Project): TResult  $apply
     * @return TResult
     */
    private function held(Project $project, User $actor, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $apply): mixed {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            if ($project->production_state === ProjectProductionState::SignedOff) {
                throw ValidationException::withMessages(['completion' => __('The customer has signed off this job.')]);
            }

            if ($project->production_state !== ProjectProductionState::WorkComplete) {
                throw ValidationException::withMessages(['completion' => __('Record that all the work has finished first.')]);
            }

            return $apply($project);
        });
    }

    /**
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
