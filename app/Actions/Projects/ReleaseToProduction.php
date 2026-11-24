<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Projects\Payments;

/**
 * A sold job is kept out of Production's lists until its deposit is
 * collected, or waived (docs/slice-39-production-from-estimate-plan.md;
 * decision 237): "Production should not see any new projects until the
 * deposit." Once it is, Production is told if the salesperson's handoff is
 * already done; otherwise it is told when the handoff is.
 *
 * Released once, it stays released: a payment reversed later does not take
 * the job away from the Production Manager who has it.
 */
class ReleaseToProduction
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * At the sale: held when a deposit is asked for, and not paid yet.
     */
    public function holdIfDepositIsDue(Project $project): void
    {
        $payments = Payments::for($project);

        if ($payments->requiredDepositCents() > 0 && ! $payments->depositIsPaid()) {
            $project->forceFill(['awaiting_deposit' => true])->save();
        }
    }

    /**
     * After the deposit changed or a payment was recorded: released when no
     * deposit is asked for any more, or the deposit invoice is paid in full.
     * Inside the caller's save.
     */
    public function consider(Project $project, ?User $actor): void
    {
        if (! $project->awaiting_deposit) {
            return;
        }

        $payments = Payments::for($project);
        $waived = $payments->requiredDepositCents() === 0;

        if (! $waived && ! $payments->depositIsPaid()) {
            return;
        }

        $project->forceFill(['awaiting_deposit' => false])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectReleasedToProduction,
            $project,
            actor: $actor,
            after: ['because' => $waived ? 'No deposit is asked for' : 'The deposit is paid'],
            location: $project->location,
        );

        if ($project->handoff()->exists()) {
            $this->tellProduction($project, $actor);
        }
    }

    /**
     * Tell the Production Managers at the Project's Location that a sold job
     * is ready to be taken (product-architecture.md, 20). If it already has a
     * Production Manager, only they are told.
     */
    public function tellProduction(Project $project, ?User $actor): void
    {
        $location = $project->location;

        $people = $project->production_manager_id !== null
            ? User::query()->whereKey($project->production_manager_id)->get()
            : User::query()->where('is_active', true)->with('accessGrants')->get()->filter(fn (User $user): bool => $user->holdsRoleAt(Role::ProductionManager, $location));

        foreach ($people->where('id', '!=', $actor?->id) as $person) {
            $person->notify(new ProjectNotice($project->id, ProjectNotice::READY_FOR_PRODUCTION, name: $location->name));
        }
    }
}
