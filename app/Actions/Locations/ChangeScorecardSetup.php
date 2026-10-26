<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\User;
use App\Support\Money;
use App\Support\Scorecards\ScorecardOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Choose who owns a Location's weekly scorecard, its general manager, and
 * set its NSLI, cost-per-close and Net Profit % targets
 * (docs/slice-27-leadership-scorecards-plan.md, step 27a; decision 187,
 * question 3; Net Profit % from slice 28, step 28c). Whoever manages Locations. A blank target is no target.
 * Scorecards already submitted keep the targets they had.
 */
class ChangeScorecardSetup
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Location $location, ?int $ownerId, ?string $nsliTarget, ?string $costPerCloseTarget, ?string $netProfitTarget, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $location);

        if ($ownerId !== null && ! ScorecardOwnership::candidates($location)->contains('id', $ownerId)) {
            throw ValidationException::withMessages(['scorecardOwnerId' => __('Choose one of this Location\'s managers.')]);
        }

        $nsli = $this->target($nsliTarget, 'scorecardNsliTarget');
        $costPerClose = $this->target($costPerCloseTarget, 'scorecardCostPerCloseTarget');
        $netProfit = $this->percent($netProfitTarget);

        DB::transaction(function () use ($location, $ownerId, $nsli, $costPerClose, $netProfit, $actor): void {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $before = $this->setup($location);

            $location->forceFill([
                'scorecard_owner_id' => $ownerId,
                'nsli_target_cents' => $nsli,
                'cost_per_close_target_cents' => $costPerClose,
                'net_profit_target_percent' => $netProfit,
            ])->save();

            $after = $this->setup($location);

            if ($before === $after) {
                return;
            }

            $this->recordAuditEvent->handle(
                AuditAction::LocationScorecardChanged,
                $location,
                actor: $actor,
                before: $before,
                after: $after,
                location: $location,
            );
        });
    }

    /**
     * @throws ValidationException
     */
    private function target(?string $typed, string $field): ?int
    {
        if (trim((string) $typed) === '') {
            return null;
        }

        return Money::toCents($typed) ?? throw ValidationException::withMessages([$field => __('Give the target in dollars, like 1,250, or leave it blank.')]);
    }

    /**
     * A whole percent, or nothing when blank.
     *
     * @throws ValidationException
     */
    private function percent(?string $typed): ?int
    {
        $typed = trim((string) $typed, " \t%");

        if ($typed === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}$/', $typed) !== 1) {
            throw ValidationException::withMessages(['scorecardNetProfitTarget' => __('Give a whole percent from 0 to 99, or leave it blank.')]);
        }

        return (int) $typed;
    }

    /**
     * @return array{scorecard_owner_id: int|null, nsli_target_cents: int|null, cost_per_close_target_cents: int|null, net_profit_target_percent: int|null}
     */
    private function setup(Location $location): array
    {
        return [
            'scorecard_owner_id' => $location->scorecard_owner_id,
            'nsli_target_cents' => $location->nsli_target_cents,
            'cost_per_close_target_cents' => $location->cost_per_close_target_cents,
            'net_profit_target_percent' => $location->net_profit_target_percent,
        ];
    }
}
