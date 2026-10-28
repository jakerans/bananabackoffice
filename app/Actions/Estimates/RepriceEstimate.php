<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Discount;
use App\Models\Estimate;
use App\Models\EstimateDiscount;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\LineItemTemplate;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Money;
use App\Support\Pricing\PaintSnapshot;
use App\Support\Pricing\RateSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reprice an estimate with today's prices, when the salesperson chooses
 * (docs/slice-31-estimating-plan.md, step 31c; decision 197, question 4).
 *
 * Everything is taken again from the Lead's Location's price book as it is
 * now, the Location's own once it is customized: the hourly rate for the
 * estimate's type, each surface's rate and paint, the line items' own
 * hourly rates, the discounts and the tax. A rate, paint, discount or tax
 * rate the book no longer has is kept as it was and counted, so nothing is
 * lost. Recorded on the Lead with what changed.
 */
class RepriceEstimate
{
    public function __construct(
        private ChangeEstimate $changeEstimate,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @return array{changed: int, kept: int, totals_before: array<string, string>, totals_after: array<string, string>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, User $actor): array
    {
        $totalsBefore = $this->totals($estimate);

        $counts = $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate): array {
            $book = PriceBook::forLocation($estimate->location) ?? throw ValidationException::withMessages(['reprice' => __('There is no price book to reprice from.')]);
            $changed = 0;
            $kept = 0;

            $rates = ProductionRate::query()->where('price_book_id', $book->id)->get();
            $paints = PaintProduct::query()->where('price_book_id', $book->id)->get();

            $surfaces = EstimateSurface::query()->whereHas('area.option', fn ($query) => $query->where('estimate_id', $estimate->id))->with(['productionRate', 'paintProduct'])->get();

            foreach ($surfaces as $surface) {
                $rate = $this->match($rates, $surface->productionRate, $surface->rateSnapshot()->name);
                $paint = $surface->paint === null ? null : $this->match($paints, $surface->paintProduct, (string) data_get($surface->paint, 'name'));

                if ($rate === null || ($surface->paint !== null && $paint === null)) {
                    $kept++;

                    continue;
                }

                $snapshot = RateSnapshot::fromProductionRate($rate);
                $attributes = [
                    'production_rate_id' => $rate->id,
                    'rate' => $snapshot->toArray(),
                    'paint_product_id' => $paint?->id,
                    'paint' => $paint === null ? null : PaintSnapshot::fromPaintProduct($paint)->toArray(),
                    'coats' => $snapshot->coatsFor($surface->coats),
                ];

                if ($attributes['rate'] != $surface->rate || $attributes['paint'] != $surface->paint || $attributes['coats'] !== $surface->coats) {
                    $changed++;
                }

                $surface->forceFill($attributes)->save();
            }

            $templates = LineItemTemplate::query()->where('price_book_id', $book->id)->get();

            foreach (EstimateLineItem::query()->whereHas('option', fn ($query) => $query->where('estimate_id', $estimate->id))->whereNotNull('line_item_template_id')->with('template')->get() as $item) {
                $template = $this->match($templates, $item->template, $item->name);

                if ($template !== null && $template->hourly_rate_cents !== $item->hourly_rate_cents && $item->hourly_rate_cents === $item->template?->hourly_rate_cents) {
                    $item->forceFill(['hourly_rate_cents' => $template->hourly_rate_cents, 'line_item_template_id' => $template->id])->save();
                    $changed++;
                }
            }

            $discounts = Discount::query()->where('price_book_id', $book->id)->get();

            foreach (EstimateDiscount::query()->where('estimate_id', $estimate->id)->with('discount')->get() as $given) {
                $discount = $this->match($discounts, $given->discount, $given->name);

                if ($discount === null) {
                    $kept++;
                } elseif ($discount->kind !== $given->kind || (float) $discount->value !== (float) $given->value || $discount->name !== $given->name) {
                    $given->forceFill(['discount_id' => $discount->id, 'name' => $discount->name, 'kind' => $discount->kind, 'value' => $discount->value])->save();
                    $changed++;
                }
            }

            if ($estimate->tax_name !== null) {
                $tax = TaxRate::query()->where('price_book_id', $book->id)->where('name', $estimate->tax_name)->first();

                if ($tax === null) {
                    $kept++;
                } elseif ((float) $tax->percent !== (float) $estimate->tax_percent) {
                    $estimate->tax_percent = $tax->percent;
                    $changed++;
                }
            }

            $hourlyRate = $book->hourlyRateFor($estimate->estimate_type);

            if ($hourlyRate !== $estimate->hourly_rate_cents) {
                $changed++;
            }

            $estimate->forceFill(['price_book_id' => $book->id, 'hourly_rate_cents' => $hourlyRate, 'priced_at' => now()])->save();

            return ['changed' => $changed, 'kept' => $kept];
        });

        $totalsAfter = $this->totals($estimate->refresh());

        $this->recordAuditEvent->handle(
            AuditAction::LeadEstimateRepriced,
            $estimate->lead,
            actor: $actor,
            before: $totalsBefore,
            after: $totalsAfter + ['prices_changed' => $counts['changed'], 'kept_as_they_were' => $counts['kept']],
            location: $estimate->location,
        );

        return $counts + ['totals_before' => $totalsBefore, 'totals_after' => $totalsAfter];
    }

    /**
     * The same record in the book now: itself when it is in the book, or the
     * one with its name (a Location's book is a copy of the default).
     *
     * @template TModel of ProductionRate|PaintProduct|LineItemTemplate|Discount
     *
     * @param  Collection<int, TModel>  $records
     * @param  TModel|null  $current
     * @return TModel|null
     */
    private function match(Collection $records, ProductionRate|PaintProduct|LineItemTemplate|Discount|null $current, string $name): ProductionRate|PaintProduct|LineItemTemplate|Discount|null
    {
        return $records->firstWhere('id', $current?->id)
            ?? ($current?->source_key !== null ? $records->firstWhere('source_key', $current->source_key) : null)
            ?? $records->firstWhere('name', $name);
    }

    /**
     * Each option's total, in words, for the record.
     *
     * @return array<string, string>
     */
    private function totals(Estimate $estimate): array
    {
        return EstimateOption::query()->where('estimate_id', $estimate->id)->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (EstimateOption $option): array => [$option->name => Money::format($option->total_cents)])->all();
    }
}
