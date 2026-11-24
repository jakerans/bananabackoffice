<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\ProjectHandoff;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Keep the handoff answers as they are typed, before the handoff is
 * complete (docs/slice-41-handoff-plan.md; decision 239): a salesperson
 * filling it in on a phone loses nothing to a lost signal or a closed tab.
 *
 * A draft is nobody's record yet: nothing is checked, nobody is told, and it
 * is not in the Project's history. It is gone once the handoff is complete,
 * which is when the answers are checked and kept for good.
 */
class SaveHandoffDraft
{
    /** The answers a draft keeps. */
    public const array ANSWERS = ['includedScope', 'exclusions', 'promises', 'timing', 'access', 'colorsStatus', 'colorsDetails', 'conditionPrep', 'customerPriorities', 'otherNotes', 'companyCamUrl'];

    /**
     * Whoever may complete the handoff. Returns whether a draft was kept:
     * not once the handoff is complete.
     *
     * @param  array<string, mixed>  $answers
     *
     * @throws AuthorizationException
     */
    public function handle(Project $project, array $answers, User $actor): bool
    {
        Gate::forUser($actor)->authorize('completeHandoff', $project);

        if ($project->handoff()->exists()) {
            return false;
        }

        $draft = [];

        foreach (self::ANSWERS as $question) {
            $draft[$question] = mb_substr(is_string($answers[$question] ?? null) ? $answers[$question] : '', 0, ProjectHandoff::ANSWER_LENGTH);
        }

        // Its own two columns only: the Project is not otherwise changed, and keeps when it was last changed.
        Project::query()->whereKey($project->id)->toBase()->update(['handoff_draft' => json_encode($draft), 'handoff_draft_saved_at' => now()]);

        return true;
    }
}
