<?php

namespace App\Actions\Subcontractors;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\SubcontractorScorecard;
use App\Models\SubcontractorScorecardNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Score a subcontractor for a piece of finished work, and add a note to a
 * scorecard (docs/slice-14-work-and-closeout-plan.md, step 14c;
 * product-architecture.md, 7.10; decision 155, question 9).
 *
 * The Project's Production Manager, or a manager there, who may also see the
 * subcontractor. One scorecard for each award whose work finished; it is not
 * changed afterwards. In the subcontractor's history, never in the customer's
 * Activity.
 */
class ScoreSubcontractor
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, mixed>  $scores  The six measures, the three checks, overall, feedback and internalNotes.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function score(Project $project, int $packageId, array $scores, User $actor): SubcontractorScorecard
    {
        return DB::transaction(function () use ($project, $packageId, $scores, $actor): SubcontractorScorecard {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $package = $project->workPackages()->with('currentAward.subcontractor.locations')->whereKey($packageId)->first()
                ?? throw ValidationException::withMessages(['scorecard' => __('This work package is no longer here. Reload the page.')]);
            $award = $package->currentAward;

            if ($award === null || ! $package->hasFinished()) {
                throw ValidationException::withMessages(['scorecard' => __('A subcontractor is scored once their work has finished.')]);
            }

            Gate::forUser($actor)->authorize('view', $award->subcontractor);

            if (SubcontractorScorecard::query()->where('work_package_award_id', $award->id)->exists()) {
                throw ValidationException::withMessages(['scorecard' => __('This work is scored already. Add a note to the scorecard to correct it.')]);
            }

            $values = self::read($scores);

            $scorecard = new SubcontractorScorecard;
            $scorecard->forceFill([
                'work_package_award_id' => $award->id,
                'subcontractor_id' => $award->subcontractor_id,
                'project_id' => $project->id,
                ...$values,
                'filled_by' => $actor->id,
                'filled_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::SubcontractorScored,
                $award->subcontractor,
                actor: $actor,
                after: ['project' => $project->title, 'package' => $package->name, 'overall' => $values['overall']],
                location: $project->location,
            );

            return $scorecard;
        });
    }

    /**
     * Add a note to a scorecard: the way it is corrected.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function addNote(SubcontractorScorecard $scorecard, ?string $note, User $actor): SubcontractorScorecardNote
    {
        $project = $scorecard->project;

        Gate::forUser($actor)->authorize('managePreProduction', $project);

        $note = trim((string) $note);

        if ($note === '' || mb_strlen($note) > SubcontractorScorecard::TEXT_LENGTH) {
            throw ValidationException::withMessages(['scorecardNote' => $note === '' ? __('Write the note.') : __('Keep this to :max characters or fewer.', ['max' => SubcontractorScorecard::TEXT_LENGTH])]);
        }

        $added = new SubcontractorScorecardNote;
        $added->forceFill(['subcontractor_scorecard_id' => $scorecard->id, 'note' => $note, 'written_by' => $actor->id, 'written_by_name' => $actor->name])->save();

        return $added;
    }

    /**
     * @param  array<string, mixed>  $scores
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function read(array $scores): array
    {
        $values = [];
        $errors = [];
        $score = fn (string $key): ?int => is_numeric($scores[$key] ?? null) && in_array((int) $scores[$key], [1, 2, 3, 4, 5], true) && (string) (int) $scores[$key] === trim((string) $scores[$key]) ? (int) $scores[$key] : null;

        foreach ([...array_keys(SubcontractorScorecard::MEASURES), 'overall'] as $key) {
            $values[$key] = $score($key);

            if ($values[$key] === null) {
                $errors['scores.'.$key] = __('Choose from 1 to 5.');
            }
        }

        foreach (array_keys(SubcontractorScorecard::CHECKS) as $key) {
            $values[$key] = filter_var($scores[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        foreach (['feedback' => 'feedback', 'internalNotes' => 'internal_notes'] as $key => $column) {
            $text = trim(is_scalar($scores[$key] ?? null) ? (string) $scores[$key] : '');

            if (mb_strlen($text) > SubcontractorScorecard::TEXT_LENGTH) {
                $errors['scores.'.$key] = __('Keep this to :max characters or fewer.', ['max' => SubcontractorScorecard::TEXT_LENGTH]);
            }

            $values[$column] = $text === '' ? null : $text;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }
}
