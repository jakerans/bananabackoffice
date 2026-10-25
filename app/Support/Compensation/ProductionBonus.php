<?php

namespace App\Support\Compensation;

use App\Models\PayPlan;
use App\Models\ProductionBonusCredit;
use App\Models\ProductionBonusMonth;
use App\Support\Money;

/**
 * The Production Bonus worked out (docs/slice-20-compensation-plan.md, step
 * 20b; decision 172, question 8; product-architecture.md, 6.1): each month,
 * on the jobs a Production Manager closed that month, (gross-margin dollars
 * − the floor) × the rate, earned only at the plan's margin or more, and paid
 * at the end of the following month only if that month holds it too.
 */
final class ProductionBonus
{
    /**
     * A Production Manager's revenue and gross-margin dollars in a month.
     *
     * @return array{revenue: int, margin: int}
     */
    public static function figures(int $userId, string $month): array
    {
        $credits = ProductionBonusCredit::query()->where('user_id', $userId)->where('month', $month)->get(['revenue_cents', 'margin_cents']);

        return ['revenue' => (int) $credits->sum('revenue_cents'), 'margin' => (int) $credits->sum('margin_cents')];
    }

    public static function marginPercent(int $revenueCents, int $marginCents): ?float
    {
        return $revenueCents > 0 ? round($marginCents * 100 / $revenueCents, 1) : null;
    }

    /**
     * Whether a month finished at the plan's margin or more. A month with no
     * job closed has no margin, so it does not.
     */
    public static function holds(PayPlan $plan, int $revenueCents, int $marginCents): bool
    {
        return $revenueCents > 0 && $marginCents * 100 >= (int) $plan->bonus_minimum_margin_percent * $revenueCents;
    }

    /**
     * What a month earns: the rate on the gross-margin dollars above the
     * floor, when it holds the margin.
     */
    public static function earnedCents(PayPlan $plan, int $revenueCents, int $marginCents): int
    {
        return self::holds($plan, $revenueCents, $marginCents) ? max(0, $plan->shareOf($marginCents - (int) $plan->bonus_floor_cents)) : 0;
    }

    /**
     * The Production plan a person is on at the end of a month.
     */
    public static function planAtEndOf(int $userId, string $month): ?PayPlan
    {
        $plan = PayPlan::onDay($userId, PayPlan::FAMILY_PRODUCTION, PayPeriods::start(PayPeriods::next($month))->subDay()->toDateString());

        return $plan !== null && $plan->paysAnything() ? $plan : null;
    }

    /**
     * How the month's figures are said: "$52,000 at 58.2%".
     */
    public static function figureWords(int $revenueCents, int $marginCents): string
    {
        $percent = self::marginPercent($revenueCents, $marginCents);

        return $percent === null ? 'no job closed' : Money::format($marginCents).' of gross-margin dollars at '.rtrim(rtrim(number_format($percent, 1), '0'), '.').'%';
    }

    /**
     * Where a month stands, in plain English, for the person's page.
     */
    public static function standing(PayPlan $plan, string $month, int $revenueCents, int $marginCents, ?ProductionBonusMonth $settled = null): string
    {
        $next = PayPeriods::words(PayPeriods::next($month));
        $earned = $settled->earned_cents ?? self::earnedCents($plan, $revenueCents, $marginCents);
        $minimum = (int) $plan->bonus_minimum_margin_percent;

        if ($month === PayPeriods::now()) {
            return $earned > 0 ? 'In progress: so far it earns '.Money::format($earned).'.' : sprintf('In progress: it earns at a %d%% margin and above %s of gross-margin dollars.', $minimum, Money::format((int) $plan->bonus_floor_cents));
        }

        if ($earned === 0) {
            return self::holds($plan, $revenueCents, $marginCents)
                ? sprintf('Not earned: the gross-margin dollars were not above the %s floor.', Money::format((int) $plan->bonus_floor_cents))
                : sprintf('Not earned: the month did not finish at %d%% or more.', $minimum);
        }

        if ($settled === null) {
            return sprintf('Earned %s. Paid at the end of %s if %s finishes at %d%% or more.', Money::format($earned), $next, $next, $minimum);
        }

        return $settled->payable
            ? sprintf('Earned %s, paid in %s: %s held %d%%.', Money::format($earned), $next, $next, $minimum)
            : sprintf('Earned %s, not paid: %s finished with %s.', Money::format($earned), $next, self::figureWords($settled->next_revenue_cents, $settled->next_margin_cents));
    }
}
