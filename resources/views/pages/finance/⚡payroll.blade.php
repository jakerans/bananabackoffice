<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CompensationEntry;
use App\Models\PayPlan;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Finance > Payroll (docs/slice-20-compensation-plan.md; decision 172,
 * question 4; product-architecture.md, 9.4): everyone with variable pay,
 * the plan each is on, and what is due this month. Each person's plans and
 * ledger are on their own page. For Finance and the Owner only.
 */
new #[Title('Payroll')] class extends Component {
    public function mount(): void
    {
        $this->authorize(Permission::ManageCompensation);
    }

    /**
     * Salespeople and Production Managers, and anyone else with a pay plan or
     * a ledger entry, by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('accessGrants', fn (Builder $grant) => $grant->whereIn('role', [Role::SalesConsultant->value, Role::ProductionManager->value]))
                ->orWhereHas('payPlans')
                ->orWhereHas('compensationEntries'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The plan of each family each person is on today.
     *
     * @return array<int, array<string, PayPlan|null>>
     */
    #[Computed]
    public function plans(): array
    {
        $today = now()->setTimezone((string) config('app.display_timezone'))->toDateString();
        $plans = [];

        foreach ($this->people as $person) {
            $plans[$person->id] = [
                PayPlan::FAMILY_SALES => PayPlan::onDay($person->id, PayPlan::FAMILY_SALES, $today),
                PayPlan::FAMILY_PRODUCTION => PayPlan::onDay($person->id, PayPlan::FAMILY_PRODUCTION, $today),
            ];
        }

        return $plans;
    }

    /**
     * What each person has due in this month's pay period, in cents.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function dueThisPeriod(): array
    {
        return CompensationEntry::query()
            ->where('period', PayPeriods::now())
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(amount_cents) as due')
            ->pluck('due', 'user_id')
            ->map(fn (mixed $due): int => (int) $due)
            ->all();
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="finance-payroll">
    <div>
        <flux:heading size="xl" level="1">{{ __('Payroll') }}</flux:heading>
        <flux:subheading>{{ __('Variable pay: sales commission and the Production Bonus. Backoffice works out the amounts; payroll pays them with the base salaries.') }}</flux:subheading>
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="payroll-people">
        <div>
            <flux:heading size="lg" level="2">{{ __('People and pay plans') }}</flux:heading>
            <flux:subheading>{{ __('Due in :period so far. Open a person to set their plan or add an adjustment.', ['period' => PayPeriods::words(PayPeriods::now())]) }}</flux:subheading>
        </div>

        @if ($this->people->isEmpty())
            <flux:text class="text-sm">{{ __('Nobody has variable pay yet.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->people as $person)
                    @php($plans = $this->plans[$person->id])
                    <li wire:key="payroll-person-{{ $person->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="payroll-person">
                        <div class="flex min-w-0 flex-1 flex-col">
                            <flux:link :href="route('finance.payroll.person', $person)" wire:navigate class="font-semibold">{{ $person->name }}</flux:link>
                            @foreach (array_filter($plans) as $plan)
                                <span wire:key="payroll-person-{{ $person->id }}-{{ $plan->family }}" class="text-sm text-zinc-700">{{ __($plan->label()) }}</span>
                            @endforeach
                            @if (array_filter($plans) === [])
                                <span class="text-sm text-amber-700">{{ __('No pay plan') }}</span>
                            @endif
                        </div>
                        <span class="font-nav font-bold tabular-nums text-ink" data-test="payroll-person-due">{{ Money::format($this->dueThisPeriod[$person->id] ?? 0) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
