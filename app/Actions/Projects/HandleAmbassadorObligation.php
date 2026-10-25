<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\ProjectAmbassadorObligation;
use App\Models\User;
use App\Support\Projects\AmbassadorPackage;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Production Manager handles what a customer promised for their Brand
 * Ambassador discount (docs/slice-21-brand-ambassador-plan.md, step 21b;
 * decision 175; product-architecture.md, 6.10): each item is marked
 * completed (and who physically did it), partial (with a note), deferred to
 * a day, or waived (with why). The yard sign is marked up on a day and is
 * then due to come down 30 days later. The job's Production Manager or a
 * manager there; each change is audited on the job.
 */
class HandleAmbassadorObligation
{
    /** Putting a yard sign up: deferred until it is due to come down. */
    public const string SIGN_UP = 'sign_up';

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  string|null  $how  completed, partial, deferred, waived, or sign_up for the yard sign.
     * @param  array<string, mixed>  $input  Any of: note, doneBy, day.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, int $obligationId, ?string $how, array $input, User $actor): ProjectAmbassadorObligation
    {
        return DB::transaction(function () use ($project, $obligationId, $how, $input, $actor): ProjectAmbassadorObligation {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('handleAmbassador', $project);

            $obligation = ProjectAmbassadorObligation::query()->where('project_id', $project->id)->find($obligationId)
                ?? throw ValidationException::withMessages(['obligation' => __('This item was changed since. Reload the page.')]);

            $note = trim((string) ($input['note'] ?? ''));
            $doneBy = trim((string) ($input['doneBy'] ?? ''));
            $today = RoughTimeframe::today($project->location);

            if (mb_strlen($note) > ProjectAmbassadorObligation::NOTE_LENGTH) {
                throw ValidationException::withMessages(['obligationNote' => __('Keep this to :max characters or fewer.', ['max' => ProjectAmbassadorObligation::NOTE_LENGTH])]);
            }

            if (mb_strlen($doneBy) > ProjectAmbassadorObligation::DONE_BY_LENGTH) {
                throw ValidationException::withMessages(['obligationDoneBy' => __('Keep this to :max characters or fewer.', ['max' => ProjectAmbassadorObligation::DONE_BY_LENGTH])]);
            }

            $values = match ($how) {
                ProjectAmbassadorObligation::COMPLETED => ['state' => $how, 'note' => $note ?: null, 'done_by_name' => $doneBy ?: null, 'deferred_until' => null],
                ProjectAmbassadorObligation::PARTIAL => ['state' => $how, 'note' => $note !== '' ? $note : throw ValidationException::withMessages(['obligationNote' => __('Say what is done and what is not.')]), 'deferred_until' => null],
                ProjectAmbassadorObligation::WAIVED => ['state' => $how, 'note' => $note !== '' ? $note : throw ValidationException::withMessages(['obligationNote' => __('Say why it is waived.')]), 'deferred_until' => null],
                ProjectAmbassadorObligation::DEFERRED => ['state' => $how, 'note' => $note ?: null, 'deferred_until' => $this->deferredTo($input, $today)],
                self::SIGN_UP => $obligation->isYardSign() ? $this->signUp($input, $today, $note) : throw ValidationException::withMessages(['obligationHow' => __('Only the yard sign goes up.')]),
                default => throw ValidationException::withMessages(['obligationHow' => __('Choose how it stands.')]),
            };

            $before = ['state' => $obligation->state, 'deferred_until' => $obligation->deferred_until?->toDateString()];

            $obligation->forceFill([
                ...$values,
                'reminded_for' => null,
                'handled_by' => $actor->id,
                'handled_by_name' => $actor->name,
                'handled_at' => now(),
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectAmbassadorObligationHandled,
                $project,
                actor: $actor,
                before: $before,
                after: ['item' => $obligation->label(), 'state' => $obligation->state, 'deferred_until' => $obligation->deferred_until?->toDateString(), 'done_by' => $obligation->done_by_name],
                location: $project->location,
                reason: $obligation->note,
            );

            return $obligation;
        });
    }

    /**
     * The yard sign went up on a day: it is due to come down 30 days later.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function signUp(array $input, CarbonImmutable $today, string $note): array
    {
        $up = $this->day($input, __('Give the day it went up.'));

        if ($up->greaterThan($today)) {
            throw ValidationException::withMessages(['obligationDay' => __('The day it went up cannot be in the future.')]);
        }

        return [
            'state' => ProjectAmbassadorObligation::DEFERRED,
            'sign_up_on' => $up->toDateString(),
            'deferred_until' => $up->addDays(AmbassadorPackage::YARD_SIGN_DAYS)->toDateString(),
            'note' => $note ?: null,
        ];
    }

    /**
     * A later day an item is deferred to.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function deferredTo(array $input, CarbonImmutable $today): string
    {
        $day = $this->day($input, __('Give the day it is deferred to.'));

        if (! $day->greaterThan($today)) {
            throw ValidationException::withMessages(['obligationDay' => __('Defer it to a day after today.')]);
        }

        return $day->toDateString();
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function day(array $input, string $missing): CarbonImmutable
    {
        return RoughTimeframe::typed(is_string($input['day'] ?? null) ? $input['day'] : null, 'obligationDay', $missing);
    }
}
