<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Models\PriceBook;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Change a price book's hourly rate, the rate for an estimate type that
 * differs, and its tax rate (docs/slice-31-estimating-plan.md, step 31a).
 * Owner and Leadership only. Estimates already priced keep their rates
 * (decision 197, question 4).
 */
class ChangeHourlyRates
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, string|null>  $typeRates  A typed hourly rate by estimate type; empty uses the book's.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, string $hourlyRate, array $typeRates, string $taxPercent, User $actor): void
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        $hourlyCents = Money::toCents($hourlyRate);

        if ($hourlyCents === null) {
            throw ValidationException::withMessages(['hourlyRate' => __('Give the hourly rate in dollars, such as 65.')]);
        }

        $byType = [];
        foreach (EstimateType::cases() as $type) {
            $typed = trim((string) ($typeRates[$type->value] ?? ''));

            if ($typed === '') {
                continue;
            }

            $cents = Money::toCents($typed);

            if ($cents === null) {
                throw ValidationException::withMessages(['typeRates.'.$type->value => __('Give the hourly rate in dollars, or leave it empty to use the price book\'s.')]);
            }

            $byType[$type->value] = $cents;
        }

        $tax = trim($taxPercent, " \t%");

        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $tax) !== 1) {
            throw ValidationException::withMessages(['taxPercent' => __('Give the tax as a percent from 0 to 99.99, such as 7.25.')]);
        }

        $taxBasisPoints = (int) round(((float) $tax) * 100);

        DB::transaction(function () use ($book, $hourlyCents, $byType, $taxBasisPoints, $actor): void {
            $book = PriceBook::query()->lockForUpdate()->findOrFail($book->id);
            $before = $this->recorded($book);

            $book->forceFill([
                'hourly_rate_cents' => $hourlyCents,
                'estimate_type_hourly_rates' => $byType === [] ? null : $byType,
                'tax_rate_basis_points' => $taxBasisPoints,
            ])->save();

            $after = $this->recorded($book);

            if ($before !== $after) {
                $this->recordAuditEvent->handle(AuditAction::PricingHourlyRatesChanged, $book, actor: $actor, before: $before, after: $after, location: $book->location);
            }
        });
    }

    /**
     * @return array<string, int|string|null>
     */
    private function recorded(PriceBook $book): array
    {
        $recorded = ['hourly_rate_cents' => $book->hourly_rate_cents];

        foreach (EstimateType::cases() as $type) {
            $own = $book->estimate_type_hourly_rates[$type->value] ?? null;
            $recorded[$type->label().' hourly rate'] = is_int($own) ? Money::format($own) : null;
        }

        return $recorded + ['tax' => rtrim(rtrim(number_format($book->tax_rate_basis_points / 100, 2, '.', ''), '0'), '.').'%'];
    }
}
