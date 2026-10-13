<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProjectNotice;

class CancelProjectForCorrectedSale
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Cancel the Project of a proposal whose sale a manager has just
     * corrected (docs/slice-8-projects-plan.md, question 4; decision 130). It
     * is part of the correction and is saved with it or not at all. The caller
     * has already claimed the Lead's row.
     *
     * The Project keeps its row, marked "Cancelled: sale corrected" with who,
     * when and the manager's note. If the proposal is sold again the same
     * Project is brought back. Jacob accepted this rule "for now".
     */
    public function apply(Proposal $proposal, string $note, User $actor): ?Project
    {
        $project = Project::query()->where('proposal_id', $proposal->id)->standing()->first();

        if ($project === null) {
            return null;
        }

        $project->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancelled_by_name' => $actor->name,
            'cancellation_note' => $note,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectCancelled,
            $project,
            actor: $actor,
            before: ['cancelled' => false, 'title' => $project->title, 'sold_for' => $project->soldFor()],
            after: ['cancelled' => true],
            location: $project->location,
            reason: $note,
        );

        // Its Production Manager is told (docs/slice-8-projects-plan.md, question 4).
        if ($project->production_manager_id !== null && $project->production_manager_id !== $actor->id) {
            User::query()->find($project->production_manager_id)?->notify(new ProjectNotice($project->id, ProjectNotice::CANCELLED));
        }

        return $project;
    }
}
