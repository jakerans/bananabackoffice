<?php

namespace App\Support\Subcontractors;

use App\Models\Subcontractor;
use App\Models\SubcontractorScorecard;
use App\Models\WorkPackageAward;

/**
 * A subcontractor's track record so far (docs/slice-14-work-and-closeout-plan.md,
 * step 14c; product-architecture.md, 6.8 and 7.11): how many jobs they have
 * finished for Banana, and their average overall rating from scorecards.
 *
 * These are the first of the VIP eligibility figures (five completed
 * Projects and a 4.5+ rating), and the bar for conducting a walkthrough
 * (6.8). Nothing is stored for it.
 */
final readonly class TrackRecord
{
    /** Completed Projects needed to conduct a walkthrough, and for VIP (6.8, 7.11). */
    public const int QUALIFYING_JOBS = 5;

    /** The average overall rating needed for both. */
    public const float QUALIFYING_RATING = 4.5;

    private function __construct(
        public int $completedJobs,
        public ?float $averageRating,
        public int $scorecards,
    ) {}

    public static function for(Subcontractor $subcontractor): self
    {
        $completed = WorkPackageAward::query()
            ->where('subcontractor_id', $subcontractor->id)
            ->where(fn ($awards) => $awards->whereNull('ended_at')->orWhere('ended_cause', WorkPackageAward::ENDED_PAYOUT_CHANGED))
            ->whereHas('package', fn ($package) => $package->whereNotNull('work_finished_on'))
            ->with('package:id,project_id')
            ->get()
            ->pluck('package.project_id')
            ->unique()
            ->count();

        $scorecards = SubcontractorScorecard::query()->where('subcontractor_id', $subcontractor->id);
        $count = (clone $scorecards)->count();
        $average = $count === 0 ? null : round((float) $scorecards->avg('overall'), 1);

        return new self($completed, $average, $count);
    }

    /**
     * Whether they meet the bar for conducting a walkthrough and for VIP:
     * five completed Projects and a 4.5+ rating. The Production Manager
     * still decides (6.8, 7.11).
     */
    public function meetsTheBar(): bool
    {
        return $this->completedJobs >= self::QUALIFYING_JOBS && $this->averageRating !== null && $this->averageRating >= self::QUALIFYING_RATING;
    }

    /**
     * The record as it is said: "3 jobs completed · rating 4.7 from 3 scorecards".
     */
    public function words(): string
    {
        $jobs = trans_choice(':count job completed|:count jobs completed', $this->completedJobs);

        return $this->averageRating === null
            ? $jobs.' · '.__('not scored yet')
            : $jobs.' · '.trans_choice('rating :rating from :count scorecard|rating :rating from :count scorecards', $this->scorecards, ['rating' => number_format($this->averageRating, 1)]);
    }
}
