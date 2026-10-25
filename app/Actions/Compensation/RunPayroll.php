<?php

namespace App\Actions\Compensation;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\CompensationEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Finance hands a pay period to payroll (docs/slice-20-compensation-plan.md,
 * step 20c; decision 172, question 3; decision 133): it finalizes a month
 * that is over, which schedules its entries and keeps the period as it is,
 * and then marks it paid with payroll's reference. Payroll may check the
 * amounts; it does not work them out again.
 */
class RunPayroll
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Finalize a month that is over, after every earlier month with entries.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function finalize(?string $period, User $actor): PayrollPeriod
    {
        Gate::forUser($actor)->authorize(Permission::ManageCompensation);

        $period = self::checked($period);

        if (! PayPeriods::hasEnded($period)) {
            throw ValidationException::withMessages(['payroll' => __(':period is not over yet.', ['period' => PayPeriods::words($period)])]);
        }

        return DB::transaction(function () use ($period, $actor): PayrollPeriod {
            // Read held; a period is finalized once, which its unique key also keeps.
            $finalized = PayrollPeriod::query()->lockForUpdate()->pluck('period')->all();

            if (in_array($period, $finalized, true)) {
                throw ValidationException::withMessages(['payroll' => __(':period is already finalized.', ['period' => PayPeriods::words($period)])]);
            }

            $earlier = CompensationEntry::query()->whereNotNull('period')->where('period', '<', $period)->whereNotIn('period', $finalized)->min('period');

            if ($earlier !== null) {
                throw ValidationException::withMessages(['payroll' => __('Finalize :period first.', ['period' => PayPeriods::words((string) $earlier)])]);
            }

            $entries = CompensationEntry::query()->where('period', $period);

            $payrollPeriod = new PayrollPeriod;
            $payrollPeriod->forceFill([
                'period' => $period,
                'total_cents' => (int) (clone $entries)->sum('amount_cents'),
                'entries_count' => (clone $entries)->count(),
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
                'finalized_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PayrollFinalized,
                $payrollPeriod,
                actor: $actor,
                after: ['period' => $period, 'total_cents' => $payrollPeriod->total_cents, 'entries' => $payrollPeriod->entries_count],
            );

            return $payrollPeriod;
        });
    }

    /**
     * Mark a finalized period paid, with payroll's reference.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function markPaid(?string $period, ?string $reference, User $actor): PayrollPeriod
    {
        Gate::forUser($actor)->authorize(Permission::ManageCompensation);

        $period = self::checked($period);
        $reference = trim((string) $reference);

        if ($reference === '' || mb_strlen($reference) > PayrollPeriod::REFERENCE_LENGTH) {
            throw ValidationException::withMessages(['payrollReference' => $reference === '' ? __('Give payroll\'s reference for this run.') : __('Keep this to :max characters or fewer.', ['max' => PayrollPeriod::REFERENCE_LENGTH])]);
        }

        return DB::transaction(function () use ($period, $reference, $actor): PayrollPeriod {
            $payrollPeriod = PayrollPeriod::query()->lockForUpdate()->where('period', $period)->first()
                ?? throw ValidationException::withMessages(['payroll' => __('Finalize :period first.', ['period' => PayPeriods::words($period)])]);

            if ($payrollPeriod->paid_at !== null) {
                throw ValidationException::withMessages(['payroll' => __(':period is already marked paid.', ['period' => PayPeriods::words($period)])]);
            }

            $payrollPeriod->forceFill(['paid_at' => now(), 'paid_by' => $actor->id, 'paid_by_name' => $actor->name, 'payroll_reference' => $reference])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PayrollPaid,
                $payrollPeriod,
                actor: $actor,
                after: ['period' => $period, 'total_cents' => $payrollPeriod->total_cents, 'payroll_reference' => $reference],
            );

            return $payrollPeriod;
        });
    }

    /**
     * @throws ValidationException
     */
    private static function checked(?string $period): string
    {
        $period = (string) $period;

        if (! PayPeriods::isPeriod($period)) {
            throw ValidationException::withMessages(['payroll' => __('Choose a pay period.')]);
        }

        return $period;
    }
}
