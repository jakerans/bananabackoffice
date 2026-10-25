<?php

use App\Actions\Compensation\ManagePay;
use App\Models\CompensationEntry;
use App\Models\PayPlan;
use App\Models\PayrollPeriod;
use App\Models\ProductionBonusCredit;
use App\Models\ProductionBonusMonth;
use App\Models\Project;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Compensation\ProductionBonus;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A person's pay (docs/slice-20-compensation-plan.md; decision 172,
 * question 4): the pay plans they have been on and their
 * variable-compensation ledger, by pay period. Their own at "My pay";
 * anyone's in Finance > Payroll, for Finance and the Owner, who set plans
 * and record adjustments here.
 *
 * Backoffice works out the amounts; payroll pays them.
 */
new #[Title('Pay')] class extends Component {
    #[Locked]
    public int $personId;

    /** Whether this is the person's own page, "My pay", not Finance's view of it. */
    #[Locked]
    public bool $isOwn = false;

    public string $plan = '';

    public string $planEffectiveFrom = '';

    public string $planReason = '';

    public string $adjustmentDirection = 'add';

    public string $adjustmentAmount = '';

    public string $adjustmentProject = '';

    public string $adjustmentReason = '';

    public string $payrollId = '';

    public function mount(?User $user = null): void
    {
        $person = $user ?? Auth::user();

        $this->authorize('viewPay', $person);

        $this->personId = $person->id;
        $this->isOwn = $user === null;
    }

    /**
     * A field that was refused and has since been changed no longer shows its
     * refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    #[Computed]
    public function person(): User
    {
        return User::query()->findOrFail($this->personId);
    }

    #[Computed]
    public function mayManage(): bool
    {
        return Gate::allows('managePay', $this->person);
    }


    /**
     * Every plan they have been on, the latest first.
     *
     * @return Collection<int, PayPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return PayPlan::query()->where('user_id', $this->personId)->orderByDesc('effective_from')->orderByDesc('id')->get();
    }

    /**
     * The plan of each family they are on today.
     *
     * @return array<string, PayPlan|null>
     */
    #[Computed]
    public function currentPlans(): array
    {
        $today = now()->setTimezone((string) config('app.display_timezone'))->toDateString();

        return [
            PayPlan::FAMILY_SALES => PayPlan::onDay($this->personId, PayPlan::FAMILY_SALES, $today),
            PayPlan::FAMILY_PRODUCTION => PayPlan::onDay($this->personId, PayPlan::FAMILY_PRODUCTION, $today),
        ];
    }

    /**
     * Their ledger, the latest first, with its jobs.
     *
     * @return Collection<int, CompensationEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return CompensationEntry::query()
            ->where('user_id', $this->personId)
            ->with('project.location')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The pay periods Finance has finalized, by month (step 20c).
     *
     * @return \Illuminate\Support\Collection<string, PayrollPeriod>
     */
    #[Computed]
    public function payrollPeriods(): \Illuminate\Support\Collection
    {
        return PayrollPeriod::query()->whereIn('period', $this->entries->pluck('period')->filter()->unique())->get()->keyBy('period');
    }

    /**
     * Their Production Bonus by month, the latest first (step 20b): this
     * month while they are on the plan, and each month a job of theirs
     * closed in the last year, with where it stands.
     *
     * @return list<array{month: string, revenue: int, margin: int, words: string}>
     */
    #[Computed]
    public function bonusMonths(): array
    {
        $now = PayPeriods::now();
        $months = ProductionBonusCredit::query()
            ->where('user_id', $this->personId)
            ->where('month', '>', PayPeriods::start($now)->subYear()->format('Y-m'))
            ->distinct()
            ->pluck('month')
            ->all();

        if (ProductionBonus::planAtEndOf($this->personId, $now) !== null) {
            $months[] = $now;
        }

        $settled = ProductionBonusMonth::query()->where('user_id', $this->personId)->whereIn('month', $months)->get()->keyBy('month');
        $rows = [];

        foreach (array_unique($months) as $month) {
            $figures = ProductionBonus::figures($this->personId, $month);
            $plan = $settled->get($month)?->payPlan ?? ProductionBonus::planAtEndOf($this->personId, $month);

            $rows[] = [
                'month' => $month,
                'revenue' => $figures['revenue'],
                'margin' => $figures['margin'],
                'words' => $plan === null ? 'No Production Bonus plan that month.' : ProductionBonus::standing($plan, $month, $figures['revenue'], $figures['margin'], $settled->get($month)),
            ];
        }

        usort($rows, fn (array $one, array $other): int => strcmp($other['month'], $one['month']));

        return $rows;
    }

    /**
     * The jobs an adjustment can be for: those with commission in anyone's
     * ledger, the latest first.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function adjustableProjects(): Collection
    {
        return Project::query()
            ->whereIn('id', CompensationEntry::query()->whereNotNull('project_id')->select('project_id'))
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'title']);
    }

    public function openPlan(): void
    {
        $this->authorize('managePay', $this->person);

        $this->plan = '';
        $this->planEffectiveFrom = now()->setTimezone((string) config('app.display_timezone'))->toDateString();
        $this->planReason = '';
        $this->resetValidation();

        Flux::modal('pay-plan')->show();
    }

    public function savePlan(ManagePay $managePay): void
    {
        $managePay->setPlan($this->person, $this->plan, $this->planEffectiveFrom, $this->planReason, Auth::user());

        $this->done('pay-plan', __('Pay plan set.'));
    }

    public function openPayrollId(): void
    {
        $this->authorize('managePay', $this->person);

        $this->payrollId = (string) $this->person->payroll_id;
        $this->resetValidation();

        Flux::modal('payroll-id')->show();
    }

    public function savePayrollId(ManagePay $managePay): void
    {
        $managePay->setPayrollId($this->person, $this->payrollId, Auth::user());

        unset($this->person);
        $this->done('payroll-id', __('Payroll ID saved.'));
    }

    public function openAdjustment(): void
    {
        $this->authorize('managePay', $this->person);

        $this->adjustmentDirection = 'add';
        $this->adjustmentAmount = '';
        $this->adjustmentProject = '';
        $this->adjustmentReason = '';
        $this->resetValidation();

        Flux::modal('pay-adjustment')->show();
    }

    public function saveAdjustment(ManagePay $managePay): void
    {
        $projectId = $this->adjustmentProject === '' ? null : (int) $this->adjustmentProject;

        $managePay->adjust($this->person, $this->adjustmentDirection, $this->adjustmentAmount, $projectId, $this->adjustmentReason, Auth::user());

        $this->done('pay-adjustment', __('Adjustment recorded.'));
    }

    private function done(string $modal, string $message): void
    {
        unset($this->plans, $this->currentPlans, $this->entries, $this->payrollPeriods);

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="pay-ledger">
    @php
        $person = $this->person;
        $entries = $this->entries;
        $payable = $entries->filter(fn (CompensationEntry $entry): bool => $entry->isPayable())->groupBy('period')->sortKeysDesc();
        $projections = $entries->reject(fn (CompensationEntry $entry): bool => $entry->isPayable());
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            @unless ($this->isOwn)
                <flux:link :href="route('finance.payroll')" wire:navigate class="text-sm">{{ __('Payroll') }}</flux:link>
            @endunless
            <flux:heading size="xl" level="1">{{ $this->isOwn ? __('My pay') : $person->name }}</flux:heading>
            <flux:subheading>{{ __('Pay plans and the variable-compensation ledger, by pay period. Backoffice works out the amounts; payroll pays them.') }}</flux:subheading>
        </div>

        @if ($this->mayManage)
            <div class="flex flex-wrap items-center gap-2">
                <flux:button size="sm" icon="plus" wire:click="openAdjustment" data-test="add-pay-adjustment">{{ __('Add an adjustment') }}</flux:button>
                <flux:button size="sm" variant="primary" wire:click="openPlan" data-test="set-pay-plan">{{ __('Set a pay plan') }}</flux:button>
            </div>
        @endif
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="pay-plans">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <flux:heading size="lg" level="2">{{ __('Pay plans') }}</flux:heading>
            <div class="flex items-center gap-2 text-sm" data-test="pay-payroll-id">
                <span class="text-zinc-500">{{ __('Payroll ID') }}</span>
                @if ($person->payroll_id === null)
                    <span class="text-amber-700">{{ __('Not set') }}</span>
                @else
                    <span class="font-semibold text-ink">{{ $person->payroll_id }}</span>
                @endif
                @if ($this->mayManage)
                    <flux:button size="xs" variant="ghost" wire:click="openPayrollId" data-test="set-payroll-id">{{ $person->payroll_id === null ? __('Set') : __('Change') }}</flux:button>
                @endif
            </div>
        </div>

        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ([PayPlan::FAMILY_SALES => __('Sales'), PayPlan::FAMILY_PRODUCTION => __('Production')] as $family => $familyLabel)
                @php($current = $this->currentPlans[$family])
                <div wire:key="pay-plan-current-{{ $family }}">
                    <dt class="text-sm text-zinc-500">{{ $familyLabel }}</dt>
                    <dd class="font-semibold text-ink" data-test="pay-plan-{{ $family }}">{{ $current === null ? __('None') : __($current->label()) }}</dd>
                    @if ($current !== null)
                        <dd class="text-xs text-zinc-500">{{ __('Since :day', ['day' => $current->effective_from->format('M j, Y')]) }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>

        @if ($this->plans->isNotEmpty())
            <ul class="flex flex-col divide-y divide-zinc-200 text-sm" data-test="pay-plan-history">
                @foreach ($this->plans as $history)
                    <li wire:key="pay-plan-{{ $history->id }}" class="flex flex-wrap items-baseline justify-between gap-2 py-2 first:pt-0 last:pb-0">
                        <span class="text-ink">{{ __($history->label()) }}</span>
                        <span class="text-zinc-500">
                            {{ __('From :day, set by :name', ['day' => $history->effective_from->format('M j, Y'), 'name' => $history->recorded_by_name ?? __('Unknown')]) }}@if ($history->reason !== null): {{ $history->reason }}@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @else
            <flux:text class="text-sm">{{ __('No pay plan is set in Backoffice.') }}</flux:text>
        @endif
    </div>

    @if ($this->bonusMonths !== [])
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="pay-bonus">
            <div>
                <flux:heading size="lg" level="2">{{ __('Production Bonus') }}</flux:heading>
                <flux:subheading>{{ __('Each month, on the jobs financially closed that month: 10% of the gross-margin dollars above $37,500, at a margin of 55% or more, paid at the end of the next month if it also finishes at 55% or more.') }}</flux:subheading>
            </div>

            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->bonusMonths as $row)
                    <li wire:key="pay-bonus-{{ $row['month'] }}" class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="pay-bonus-month">
                        <div class="flex min-w-0 flex-1 flex-col text-sm">
                            <span class="font-semibold text-ink">{{ PayPeriods::words($row['month']) }}</span>
                            <span class="text-zinc-700">{{ __($row['words']) }}</span>
                        </div>
                        <div class="flex flex-col items-end text-sm">
                            <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format($row['margin']) }}</span>
                            <span class="text-xs text-zinc-500">{{ ProductionBonus::marginPercent($row['revenue'], $row['margin']) === null ? __('No job closed') : __(':percent% of :revenue', ['percent' => ProductionBonus::marginPercent($row['revenue'], $row['margin']), 'revenue' => Money::format($row['revenue'])]) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="pay-entries">
        <flux:heading size="lg" level="2">{{ __('Ledger') }}</flux:heading>

        @if ($payable->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing is due yet.') }}</flux:text>
        @endif

        @foreach ($payable as $period => $periodEntries)
            <div wire:key="pay-period-{{ $period }}" class="flex flex-col gap-2" data-test="pay-period">
                @php($finalized = $this->payrollPeriods->get($period))
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-zinc-200 pb-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="md" level="3">{{ PayPeriods::words((string) $period) }}</flux:heading>
                        @if ($finalized === null)
                            <flux:badge size="sm" color="zinc" data-test="pay-period-state">{{ __('Open') }}</flux:badge>
                        @elseif ($finalized->paid_at === null)
                            <flux:badge size="sm" color="amber" data-test="pay-period-state">{{ __('Scheduled for payroll') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="emerald" data-test="pay-period-state">{{ __('Paid') }}</flux:badge>
                        @endif
                    </div>
                    <span class="font-nav font-bold tabular-nums text-ink" data-test="pay-period-total">{{ Money::format((int) $periodEntries->sum('amount_cents')) }}</span>
                </div>

                <ul class="flex flex-col divide-y divide-zinc-100">
                    @foreach ($periodEntries as $entry)
                        <x-pay.entry :$entry />
                    @endforeach
                </ul>
            </div>
        @endforeach

        @if ($projections->isNotEmpty())
            <div class="flex flex-col gap-2" data-test="pay-projections">
                <div class="border-b border-zinc-200 pb-1">
                    <flux:heading size="md" level="3">{{ __('Projected at the sale') }}</flux:heading>
                    <flux:text class="text-xs">{{ __('What each sale would pay at a 50% gross margin. Never paid: the final commission comes from the job\'s final gross margin.') }}</flux:text>
                </div>

                <ul class="flex flex-col divide-y divide-zinc-100">
                    @foreach ($projections as $entry)
                        <x-pay.entry :$entry />
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    @if ($this->mayManage)
        <flux:modal name="pay-plan" class="w-full max-w-lg">
            <form wire:submit="savePlan" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Set a pay plan') }}</flux:heading>
                    <flux:subheading>{{ __(':name is on it from the day it starts. Earlier plans are kept as they were.', ['name' => $person->name]) }}</flux:subheading>
                </div>

                <flux:select wire:model="plan" :label="__('Plan')" data-test="pay-plan-choice">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach (PayPlan::PLANS as $key => $terms)
                        <flux:select.option :value="$key" wire:key="pay-plan-option-{{ $key }}">{{ __($terms['label']) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="planEffectiveFrom" type="date" :label="__('Starts')" :description="__('A sale pays on the plan its salesperson was on the day it sold.')" data-test="pay-plan-from" />
                <flux:textarea wire:model="planReason" rows="2" :label="__('Why (required when it changes a plan)')" data-test="pay-plan-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-pay-plan">{{ __('Set') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="payroll-id" class="w-full max-w-md">
            <form wire:submit="savePayrollId" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Payroll ID') }}</flux:heading>
                    <flux:subheading>{{ __(':name\'s ID in payroll, so payroll matches what Backoffice sends. Leave it empty to clear it.', ['name' => $person->name]) }}</flux:subheading>
                </div>

                <flux:input wire:model="payrollId" maxlength="{{ ManagePay::PAYROLL_ID_LENGTH }}" :label="__('Payroll ID')" data-test="payroll-id-input" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-payroll-id">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="pay-adjustment" class="w-full max-w-lg">
            <form wire:submit="saveAdjustment" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Add an adjustment') }}</flux:heading>
                    <flux:subheading>{{ __('Due in this month\'s pay period, or the next open one. A split commission is a deduction for one person and an addition for the other.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="adjustmentDirection" :label="__('It')" data-test="pay-adjustment-direction">
                        <flux:select.option value="add">{{ __('Adds to their pay') }}</flux:select.option>
                        <flux:select.option value="deduct">{{ __('Deducts from their pay') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="adjustmentAmount" inputmode="decimal" icon="currency-dollar" :label="__('Amount')" data-test="pay-adjustment-amount" />
                </div>
                <flux:select wire:model="adjustmentProject" :label="__('Job (optional)')" data-test="pay-adjustment-project">
                    <flux:select.option value="">{{ __('No job') }}</flux:select.option>
                    @foreach ($this->adjustableProjects as $option)
                        <flux:select.option :value="$option->id" wire:key="pay-adjustment-project-{{ $option->id }}">{{ $option->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="adjustmentReason" rows="2" :label="__('Why (required)')" data-test="pay-adjustment-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-pay-adjustment">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
