<?php

namespace App\Support\Projects;

use App\Models\EstimateOption;
use App\Models\Project;
use App\Support\Pricing\EstimatePricing;

/**
 * What a job's first work package starts from, read from the estimate
 * option the customer signed (docs/slice-39-production-from-estimate-plan.md;
 * decision 237): what the work is, how many hours the estimate priced, and
 * so how many working days it takes a crew.
 *
 * A start only: the Production Manager changes any of it before saving.
 */
final readonly class WorkFromEstimate
{
    /** The hours one person works in a day. */
    public const int HOURS_A_DAY = 8;

    /** The crew a job is planned for until someone says otherwise. */
    public const int USUAL_CREW = 2;

    private function __construct(
        public string $name,
        public string $scope,
        public float $hours,
        public int $crew,
        public int $workingDays,
    ) {}

    /**
     * Nothing when the job was not sold from an estimate, or its option is gone.
     */
    public static function for(Project $project): ?self
    {
        $optionId = $project->proposal->estimate_option_id;
        $option = $optionId === null ? null : EstimateOption::query()->with('estimate')->find($optionId);

        if ($option === null) {
            return null;
        }

        $priced = app(EstimatePricing::class)->price($option->estimate, $option);
        $hours = $priced->hours + $priced->prepHours;

        return new self(
            $option->name,
            HandoffDraft::for($project)['includedScope'],
            $hours,
            self::USUAL_CREW,
            self::daysFor($hours, self::USUAL_CREW),
        );
    }

    /**
     * The working days the hours take a crew, never fewer than one.
     */
    public static function daysFor(float $hours, int $crew): int
    {
        return max(1, (int) ceil($hours / (self::HOURS_A_DAY * max(1, $crew))));
    }

    /**
     * "46 hours", without a trailing ".0".
     */
    public function hoursWords(): string
    {
        return rtrim(rtrim(number_format($this->hours, 1), '0'), '.');
    }
}
