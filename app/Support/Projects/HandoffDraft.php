<?php

namespace App\Support\Projects;

use App\Models\EstimateArea;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\LeadDiscovery;
use App\Models\Project;
use App\Models\ProjectHandoff;
use App\Support\Pricing\RateSnapshot;
use Illuminate\Support\Str;

/**
 * The handoff, started for the salesperson (docs/slice-34-sales-workspace-plan.md,
 * step 34b; decision 212, question 3): what is included, from the option the
 * customer chose, each room with its surfaces and coats and the written line
 * items; and what matters to the customer, from discovery. The salesperson
 * checks it and answers the rest; nothing is saved until they do.
 */
class HandoffDraft
{
    /**
     * The answers it can start, by question; empty where there is nothing to start from.
     *
     * @return array{includedScope: string, customerPriorities: string}
     */
    public static function for(Project $project): array
    {
        $optionId = $project->proposal->estimate_option_id;
        $option = $optionId === null ? null : EstimateOption::query()->with(['areas.surfaces', 'lineItems'])->find($optionId);
        $discovery = LeadDiscovery::query()->where('lead_id', $project->lead_id)->first();

        return [
            'includedScope' => $option === null ? '' : self::scope($option),
            'customerPriorities' => $discovery === null ? '' : self::priorities($discovery),
        ];
    }

    /**
     * "Bedroom: Walls (2 coats), Doors", one room to a line, then the line items.
     */
    private static function scope(EstimateOption $option): string
    {
        $lines = $option->areas->map(function (EstimateArea $area): string {
            $surfaces = $area->surfaces->map(function (EstimateSurface $surface): string {
                $rate = RateSnapshot::fromArray($surface->rate);
                $coats = $rate->coatsFor($surface->coats);

                return $rate->usesCoats ? __(':surface (:count :coats)', ['surface' => $rate->customerName(), 'count' => $coats, 'coats' => Str::plural('coat', $coats)]) : $rate->customerName();
            });

            return $surfaces->isEmpty() ? $area->name : $area->name.': '.$surfaces->implode(', ');
        });

        if ($option->lineItems->isNotEmpty()) {
            $lines->push(__('Also: :items', ['items' => $option->lineItems->map(fn (EstimateLineItem $item): string => $item->name)->implode('; ')]));
        }

        return Str::limit($lines->implode("\n"), ProjectHandoff::ANSWER_LENGTH, '');
    }

    /**
     * "Wants: ..." for each answer given.
     */
    private static function priorities(LeadDiscovery $discovery): string
    {
        $lines = [];

        foreach ($discovery->answered() as $column => $answer) {
            $lines[] = __(LeadDiscovery::ANSWERS[$column]).': '.$answer;
        }

        return Str::limit(implode("\n", $lines), ProjectHandoff::ANSWER_LENGTH, '');
    }
}
