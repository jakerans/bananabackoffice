<?php

namespace App\Support\Projects;

use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\WorkPackage;

/**
 * A job's way through Production, start to finish, as one line of steps
 * (docs/slice-39-production-from-estimate-plan.md; decision 237): which are
 * done, which is next, and where on the Project page each is done.
 *
 * Worked out from what is recorded, like "Ready to schedule?"; nobody ticks
 * a step. It changes nothing about what may be done when: it only shows the
 * order the work usually goes in.
 */
final readonly class ProductionPath
{
    /**
     * @param  list<array{key: string, label: string, done: bool, anchor: string, needed: bool}>  $steps
     */
    private function __construct(public array $steps) {}

    public static function for(Project $project): self
    {
        $project->loadMissing(['handoff', 'workPackages.currentAward']);

        $payments = Payments::for($project);
        $packages = $project->workPackages;
        $reached = fn (ProjectProductionState $state): bool => self::order($project->production_state) >= self::order($state);

        return new self([
            self::step('deposit', __('Deposit'), ! $project->awaiting_deposit && $payments->state() !== Payments::DEPOSIT_DUE, 'project-step-payments'),
            self::step('handoff', __('Handoff from Sales'), $project->handoff !== null, 'project-step-handoff'),
            self::step('production_manager', __('Production Manager'), $project->production_manager_id !== null, 'project-step-readiness'),
            self::step('welcome_call', __('Welcome call'), $project->welcome_call_on !== null, 'project-step-pre-production'),
            self::step('timeframe', __('Rough timeframe'), RoughTimeframe::of($project) !== null, 'project-step-pre-production'),
            // Colors are approved beside the rest and never hold the job up (slice 12).
            self::step('colors', __('Colors approved'), Readiness::for($project)->colorsApproved(), 'project-step-colors', needed: false),
            self::step('work_package', __('Work package'), $packages->isNotEmpty(), 'project-step-work'),
            self::step('given_out', __('Work given out'), $packages->isNotEmpty() && $packages->every(fn (WorkPackage $package): bool => $package->currentAward !== null), 'project-step-work'),
            self::step('dates', __('Dates confirmed'), $reached(ProjectProductionState::Scheduled), 'project-step-work'),
            self::step('started', __('Work started'), $reached(ProjectProductionState::InProgress), 'project-step-work'),
            self::step('finished', __('Work finished'), $reached(ProjectProductionState::WorkComplete), 'project-step-work'),
            self::step('signed_off', __('Walkthrough and signoff'), $reached(ProjectProductionState::SignedOff), 'project-step-completion'),
            self::step('final_invoice', __('Final invoice'), $payments->final() !== null, 'project-step-payments'),
            self::step('paid', __('Paid in full'), $payments->state() === Payments::PAID_IN_FULL, 'project-step-payments'),
        ]);
    }

    /**
     * The first step still to do that the job needs; none once everything is done.
     *
     * @return array{key: string, label: string, done: bool, anchor: string, needed: bool}|null
     */
    public function next(): ?array
    {
        foreach ($this->steps as $step) {
            if ($step['needed'] && ! $step['done']) {
                return $step;
            }
        }

        return null;
    }

    /**
     * How many of the steps the job needs are done.
     */
    public function done(): int
    {
        return count(array_filter($this->steps, fn (array $step): bool => $step['needed'] && $step['done']));
    }

    /**
     * How many steps the job needs.
     */
    public function needed(): int
    {
        return count(array_filter($this->steps, fn (array $step): bool => $step['needed']));
    }

    /**
     * @return array{key: string, label: string, done: bool, anchor: string, needed: bool}
     */
    private static function step(string $key, string $label, bool $done, string $anchor, bool $needed = true): array
    {
        return ['key' => $key, 'label' => $label, 'done' => $done, 'anchor' => $anchor, 'needed' => $needed];
    }

    private static function order(ProjectProductionState $state): int
    {
        return (int) array_search($state, ProjectProductionState::cases(), true);
    }
}
