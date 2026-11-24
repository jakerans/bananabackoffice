<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Estimate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Choose the deposit an estimate asks for, before the customer signs
 * (docs/slice-38-quickbooks-tax-plan.md, step 38c; decisions 233 and 235):
 * the Location's policy, another percent of the price before tax, a flat
 * amount, or none. Whoever may build the estimate, its salesperson included.
 * A deposit waived, or under a quarter of the price, pays no commission
 * advance; the page says so before it is saved.
 */
class ChangeEstimateDeposit
{
    public const string POLICY = 'policy';

    public const string PERCENT = 'percent';

    public const string FLAT = 'flat';

    public const string NONE = 'none';

    public function __construct(private ChangeEstimate $changeEstimate, private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, ?string $kind, ?string $value, User $actor): void
    {
        [$percent, $flatCents] = match ($kind) {
            self::POLICY => [null, null],
            self::NONE => [null, 0],
            self::PERCENT => [$this->percent($value), null],
            self::FLAT => [null, $this->flat($value)],
            default => throw ValidationException::withMessages(['depositKind' => __('Choose how the deposit is set.')]),
        };

        $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($percent, $flatCents, $actor): void {
            $before = $estimate->depositWords();

            $estimate->forceFill(['deposit_percent' => $percent, 'deposit_flat_cents' => $flatCents])->save();

            if ($before !== $estimate->depositWords()) {
                $this->recordAuditEvent->handle(
                    AuditAction::LeadEstimateDepositChanged,
                    $estimate->lead,
                    actor: $actor,
                    before: ['deposit' => $before],
                    after: ['deposit' => $estimate->depositWords()],
                    location: $estimate->location,
                );
            }
        });
    }

    /**
     * @throws ValidationException
     */
    private function percent(?string $typed): int
    {
        $typed = trim((string) $typed);

        if (preg_match('/^\d{1,3}$/', $typed) !== 1 || (int) $typed < 1 || (int) $typed > 100) {
            throw ValidationException::withMessages(['depositValue' => __('Give the deposit as a whole percent from 1 to 100. For none, choose "No deposit".')]);
        }

        return (int) $typed;
    }

    /**
     * @throws ValidationException
     */
    private function flat(?string $typed): int
    {
        $cents = Money::toCents($typed);

        if ($cents === null || $cents <= 0) {
            throw ValidationException::withMessages(['depositValue' => __('Give the deposit in dollars, like 2,000. For none, choose "No deposit".')]);
        }

        return $cents;
    }
}
