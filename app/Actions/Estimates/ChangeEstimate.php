<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ProposalResult;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Pricing\EstimatePricing;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Make one change to an estimate (docs/slice-31-estimating-plan.md, step
 * 31c): the person must be allowed to build it, the estimate is locked while
 * the change is saved, and every option's total is worked out again, all
 * together or not at all. Each action that changes an estimate goes through
 * here.
 *
 * Once presented (step 31e; decision 207): a sold or lost estimate is
 * refused, and while it is pending its presented price follows its options,
 * the change kept in the Audit Log as a change of its price (decision 204,
 * question 6).
 */
class ChangeEstimate
{
    /** Feet, hours or a count, as typed: up to 999,999.99. */
    public const array MEASUREMENT_RULES = ['string', 'max:12', 'regex:/^\s*\d{0,6}(\.\d{1,2})?\s*$/', 'not_regex:/^\s*\.?\s*$/'];

    public function __construct(
        private EstimatePricing $pricing,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(Estimate): TResult  $change  Given the locked estimate.
     * @return TResult
     *
     * @throws AuthorizationException
     */
    public function handle(Estimate $estimate, User $actor, Closure $change, string $ability = 'update'): mixed
    {
        Gate::forUser($actor)->authorize($ability, $estimate);

        return DB::transaction(function () use ($estimate, $change, $actor): mixed {
            // The Lead first, as recording a result claims it: a sale and a change never cross.
            Lead::query()->lockForUpdate()->findOrFail($estimate->lead_id);
            $locked = Estimate::query()->lockForUpdate()->findOrFail($estimate->id);

            if ($locked->isSettled()) {
                throw new AuthorizationException(__('This estimate is :result and can no longer be changed.', ['result' => mb_strtolower($locked->proposal->result->label())]));
            }

            $result = $change($locked);

            $locked->touch();
            $this->pricing->refreshTotals($locked);
            $this->followPresentedPrice($locked, $actor);

            return $result;
        });
    }

    /**
     * A pending estimate's presented price is its options' range, as they
     * are priced now.
     */
    private function followPresentedPrice(Estimate $estimate, User $actor): void
    {
        $proposal = $estimate->proposal;

        if ($proposal === null || $proposal->result !== ProposalResult::Pending) {
            return;
        }

        $totals = $estimate->options()->pluck('total_cents');
        $from = (int) $totals->min();
        $to = (int) $totals->max();

        if ($from === $proposal->price_from_cents && $to === $proposal->price_cents) {
            return;
        }

        $before = $proposal->price();
        $proposal->forceFill(['price_from_cents' => $from, 'price_cents' => $to])->save();

        $lead = $estimate->lead()->with('location')->firstOrFail();

        $this->recordAuditEvent->handle(
            AuditAction::LeadProposalEdited,
            $lead,
            actor: $actor,
            before: ['proposal' => $proposal->name, 'price' => $before],
            after: ['proposal' => $proposal->name, 'price' => $proposal->price()],
            location: $lead->location,
        );

        AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);
    }

    /**
     * Refuse an option, area, surface or line item that is not this estimate's.
     *
     * @throws AuthorizationException
     */
    public static function ensureBelongs(Estimate $estimate, EstimateOption|EstimateArea|EstimateSurface|EstimateLineItem $part): void
    {
        $optionId = match (true) {
            $part instanceof EstimateOption => $part->id,
            $part instanceof EstimateArea, $part instanceof EstimateLineItem => $part->estimate_option_id,
            $part instanceof EstimateSurface => EstimateArea::query()->whereKey($part->estimate_area_id)->value('estimate_option_id'),
        };

        if (! EstimateOption::query()->whereKey($optionId)->where('estimate_id', $estimate->id)->exists()) {
            throw new AuthorizationException;
        }
    }
}
