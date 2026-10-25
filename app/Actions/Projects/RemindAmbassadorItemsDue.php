<?php

namespace App\Actions\Projects;

use App\Models\ProjectAmbassadorObligation;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Projects\RoughTimeframe;

/**
 * Remind each Production Manager of the Brand Ambassador items on their
 * jobs that are deferred to a day that has come, such as a yard sign due to
 * come down (docs/slice-21-brand-ambassador-plan.md, step 21b; decision
 * 175). Once for each due day: deferring it again reminds again.
 */
class RemindAmbassadorItemsDue
{
    /**
     * @return int How many were reminded.
     */
    public function handle(): int
    {
        $reminded = 0;

        ProjectAmbassadorObligation::query()
            ->where('state', ProjectAmbassadorObligation::DEFERRED)
            ->whereNotNull('deferred_until')
            ->whereDate('deferred_until', '<=', now()->addDay()->toDateString())
            ->with('project.location')
            ->get()
            ->each(function (ProjectAmbassadorObligation $obligation) use (&$reminded): void {
                $project = $obligation->project;

                if ($project->isCancelled() || ! $obligation->isDue(RoughTimeframe::today($project->location)) || $obligation->reminded_for?->equalTo($obligation->deferred_until)) {
                    return;
                }

                User::query()->find($project->production_manager_id)?->notify(new ProjectNotice($project->id, ProjectNotice::AMBASSADOR_DUE, name: $obligation->label()));
                $obligation->forceFill(['reminded_for' => $obligation->deferred_until])->save();
                $reminded++;
            });

        return $reminded;
    }
}
