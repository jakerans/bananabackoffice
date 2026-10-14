<?php

namespace App\Support\Projects;

use App\Enums\ProjectProductionState;
use App\Models\Location;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a job ready to schedule needs its dates soon
 * (docs/slice-11-schedule-plan.md, step 11c; decision 144, question 8).
 *
 * Exact dates are usually settled three to six weeks before the work starts
 * (product-architecture.md, 6.3). A job is flagged when it is ready to
 * schedule, is not yet Scheduled, and its rough timeframe starts in less than
 * three weeks, by its Location's calendar. One whose rough start has already
 * passed is flagged too.
 */
final class NeedsDatesSoon
{
    /** How many weeks before the rough start a job without dates is flagged. */
    public const int WEEKS = 3;

    /**
     * Whether this job is flagged now.
     */
    public static function applies(Project $project): bool
    {
        return $project->production_state !== ProjectProductionState::Scheduled
            && ! $project->isCancelled()
            && $project->timeframe_earliest !== null
            && Readiness::for($project)->isReadyToSchedule()
            && $project->timeframe_earliest->lessThan(self::cutoff($project->location));
    }

    /**
     * Narrow a query of one Location's jobs to the ones flagged now. It asks
     * the same questions as applies().
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function where(Builder $query, Location $location): Builder
    {
        return Readiness::whereReady($query)
            ->whereNull('projects.cancelled_at')
            ->where('projects.production_state', '!=', ProjectProductionState::Scheduled)
            ->where('projects.timeframe_earliest', '<', self::cutoff($location));
    }

    /**
     * The first day that is three weeks or more away: a rough start before it
     * is soon.
     */
    public static function cutoff(?Location $location): CarbonImmutable
    {
        return RoughTimeframe::today($location)->addWeeks(self::WEEKS);
    }
}
