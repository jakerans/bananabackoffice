<?php

namespace App\Support\Projects;

use App\Enums\ColorReadiness;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether a job is ready to schedule
 * (docs/slice-10-pre-production-plan.md, step 10c; decision 141, question 8).
 *
 * Nothing is stored for it. It is worked out from what the handoff, the
 * Production Manager, the welcome call and the rough timeframe record, so it
 * cannot disagree with them, and nobody ticks it by hand.
 *
 * Four things are needed. The colors are shown beside them and are not one of
 * the four: a job is ready to schedule before its colors are approved.
 */
final readonly class Readiness
{
    public const string HANDOFF = 'handoff';

    public const string PRODUCTION_MANAGER = 'production_manager';

    public const string WELCOME_CALL = 'welcome_call';

    public const string TIMEFRAME = 'timeframe';

    /**
     * @param  list<array{key: string, done: bool, label: string, needs: string}>  $items  The four things, in the order they happen.
     */
    private function __construct(
        public array $items,
        public ColorReadiness $colors,
    ) {}

    public static function for(Project $project): self
    {
        $timeframe = RoughTimeframe::of($project);

        return new self([
            [
                'key' => self::HANDOFF,
                'done' => $project->handoff !== null,
                'label' => $project->handoff !== null ? __('Handoff from Sales complete') : __('Handoff from Sales not complete yet'),
                'needs' => __('the salesperson\'s handoff'),
            ],
            [
                'key' => self::PRODUCTION_MANAGER,
                'done' => $project->production_manager_id !== null,
                'label' => $project->production_manager_id !== null
                    ? __('Production Manager assigned: :name', ['name' => $project->production_manager_name ?? __('Unknown')])
                    : __('No Production Manager yet'),
                'needs' => __('a Production Manager'),
            ],
            [
                'key' => self::WELCOME_CALL,
                'done' => $project->welcome_call_on !== null,
                'label' => $project->welcome_call_on !== null
                    ? __('Customer called on :day', ['day' => $project->welcome_call_on->format('M j, Y')])
                    : __('Customer not yet called'),
                'needs' => __('the welcome call'),
            ],
            [
                'key' => self::TIMEFRAME,
                'done' => $timeframe !== null,
                'label' => $timeframe !== null ? __('Rough timeframe set: :timeframe', ['timeframe' => $timeframe]) : __('No rough timeframe yet'),
                'needs' => __('a rough timeframe'),
            ],
        ], $project->color_readiness);
    }

    /**
     * Whether all four are recorded. A cancelled Project is never ready; the
     * pages do not ask about one.
     */
    public function isReadyToSchedule(): bool
    {
        return $this->missing() === [];
    }

    /**
     * How many of the four are recorded.
     */
    public function done(): int
    {
        return count(array_filter($this->items, fn (array $item): bool => $item['done']));
    }

    /**
     * What it still needs, in words that follow "Still needs".
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_map(
            fn (array $item): string => $item['needs'],
            array_filter($this->items, fn (array $item): bool => ! $item['done']),
        ));
    }

    /**
     * Whether the customer has approved the colors. Shown beside the four,
     * and not needed to schedule.
     */
    public function colorsApproved(): bool
    {
        return $this->colors === ColorReadiness::Approved;
    }

    /**
     * Narrow a query to the jobs that have a Production Manager and are ready
     * to schedule. It asks the same four questions as for().
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function whereReady(Builder $query): Builder
    {
        return $query
            ->whereNotNull('projects.production_manager_id')
            ->whereNotNull('projects.welcome_call_on')
            ->whereNotNull('projects.timeframe_earliest')
            ->whereNotNull('projects.timeframe_latest')
            ->whereHas('handoff');
    }

    /**
     * Narrow a query to the jobs that have a Production Manager and still
     * need something before they are ready to schedule.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function whereGettingReady(Builder $query): Builder
    {
        return $query
            ->whereNotNull('projects.production_manager_id')
            ->where(function (Builder $missing): void {
                $missing->whereNull('projects.welcome_call_on')
                    ->orWhereNull('projects.timeframe_earliest')
                    ->orWhereNull('projects.timeframe_latest')
                    ->orWhereDoesntHave('handoff');
            });
    }

    /**
     * When each of these Projects first had a Production Manager, which is
     * when it came into pre-production. Changing the Production Manager later
     * does not start it again.
     *
     * @param  iterable<int>  $projectIds
     * @return array<int, CarbonImmutable>
     */
    public static function inPreProductionSince(iterable $projectIds): array
    {
        $rows = DB::table('project_production_managers')
            ->whereIn('project_id', collect($projectIds)->all())
            ->groupBy('project_id')
            ->selectRaw('project_id, min(created_at) as since')
            ->get();

        $since = [];

        foreach ($rows as $row) {
            $since[(int) $row->project_id] = CarbonImmutable::parse((string) $row->since, 'UTC');
        }

        return $since;
    }
}
