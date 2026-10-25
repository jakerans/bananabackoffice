<?php

use App\Actions\Compensation\RunPayroll;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CompensationEntry;
use App\Models\PayPlan;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance > Payroll (docs/slice-20-compensation-plan.md; decision 172,
 * questions 3 and 4; product-architecture.md, 9.4): each pay period, what
 * each person has due in it, and where it stands: open, then scheduled once
 * Finance finalizes it, then paid once payroll has paid it (step 20c). Below,
 * everyone with variable pay and the plan each is on; each person's plans
 * and ledger are on their own page. For Finance and the Owner only.
 */
new #[Title('Payroll')] class extends Component {
    #[Url(as: 'period')]
    public string $period = '';

    public string $payrollReference = '';

    public function mount(): void
    {
        $this->authorize(Permission::ManageCompensation);

        if (! PayPeriods::isPeriod($this->period)) {
            $this->period = $this->firstToRun();
        }
    }

    /**
     * The period Finance most likely runs next: the earliest month that is
     * over and has entries but is not finalized, or else this month.
     */
    private function firstToRun(): string
    {
        $finalized = PayrollPeriod::query()->pluck('period')->all();

        $waiting = CompensationEntry::query()
            ->whereNotNull('period')
            ->where('period', '<', PayPeriods::now())
            ->whereNotIn('period', $finalized)
            ->min('period');

        return $waiting === null ? PayPeriods::now() : (string) $waiting;
    }

    /**
     * The periods to choose from: every month with entries or finalized, and
     * this month, the latest first.
     *
     * @return list<string>
     */
    #[Computed]
    public function periods(): array
    {
        $periods = CompensationEntry::query()->whereNotNull('period')->distinct()->pluck('period')
            ->merge(PayrollPeriod::query()->pluck('period'))
            ->push(PayPeriods::now(), $this->period)
            ->filter(fn (mixed $period): bool => is_string($period) && PayPeriods::isPeriod($period))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $periods;
    }

    #[Computed]
    public function payrollPeriod(): ?PayrollPeriod
    {
        return PayrollPeriod::query()->where('period', $this->period)->first();
    }

    /**
     * What each person has due in the period, by name.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, entries: int, total: int}>
     */
    #[Computed]
    public function dues(): \Illuminate\Support\Collection
    {
        return CompensationEntry::query()
            ->where('period', $this->period)
            ->get(['user_id', 'user_name', 'amount_cents'])
            ->groupBy('user_id')
            ->map(fn ($entries, int $userId): array => [
                'id' => $userId,
                'name' => (string) $entries->last()?->user_name,
                'entries' => $entries->count(),
                'total' => (int) $entries->sum('amount_cents'),
            ])
            ->sortBy('name')
            ->values();
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

    public function updatedPeriod(): void
    {
        unset($this->payrollPeriod, $this->dues);
        $this->resetValidation();
    }

    public function openFinalize(): void
    {
        $this->authorize(Permission::ManageCompensation);
        $this->resetValidation();

        Flux::modal('finalize-payroll')->show();
    }

    public function finalize(RunPayroll $runPayroll): void
    {
        $runPayroll->finalize($this->period, Auth::user());

        $this->done('finalize-payroll', __(':period is finalized for payroll.', ['period' => PayPeriods::words($this->period)]));
    }

    public function openPaid(): void
    {
        $this->authorize(Permission::ManageCompensation);

        $this->payrollReference = '';
        $this->resetValidation();

        Flux::modal('payroll-paid')->show();
    }

    public function markPaid(RunPayroll $runPayroll): void
    {
        $runPayroll->markPaid($this->period, $this->payrollReference, Auth::user());

        $this->done('payroll-paid', __(':period is marked paid.', ['period' => PayPeriods::words($this->period)]));
    }

    /**
     * A finalized period for payroll, as a spreadsheet: each person's total
     * and every entry behind it.
     */
    public function download(): StreamedResponse
    {
        $this->authorize(Permission::ManageCompensation);

        $payrollPeriod = $this->payrollPeriod;
        abort_if($payrollPeriod === null, 404);

        $entries = CompensationEntry::query()->where('period', $payrollPeriod->period)->with('project')->orderBy('user_name')->orderBy('id')->get();

        return response()->streamDownload(function () use ($entries): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Person', 'Kind', 'Job', 'Amount', 'How it was worked out', 'Why'], escape: '');

            foreach ($entries as $entry) {
                fputcsv($out, [$entry->user_name, $entry->kindLabel(), $entry->project?->title ?? '', number_format($entry->amount_cents / 100, 2, '.', ''), $entry->explanation, $entry->reason ?? ''], escape: '');
            }

            fclose($out);
        }, 'payroll-'.$payrollPeriod->period.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function done(string $modal, string $message): void
    {
        unset($this->payrollPeriod, $this->dues, $this->periods);

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="finance-payroll">
    @php
        $payrollPeriod = $this->payrollPeriod;
        $dues = $this->dues;
        $periodWords = PayPeriods::words($this->period);
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Payroll') }}</flux:heading>
        <flux:subheading>{{ __('Variable pay: sales commission and the Production Bonus. Backoffice works out the amounts; payroll pays them with the base salaries.') }}</flux:subheading>
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="payroll-period">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <flux:select wire:model.live="period" :label="__('Pay period')" class="max-w-xs" data-test="payroll-period-choice">
                @foreach ($this->periods as $option)
                    <flux:select.option :value="$option" wire:key="payroll-period-{{ $option }}">{{ PayPeriods::words($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex flex-wrap items-center gap-2">
                @if ($payrollPeriod === null)
                    @if (PayPeriods::hasEnded($this->period))
                        <flux:button size="sm" variant="primary" wire:click="openFinalize" data-test="finalize-payroll">{{ __('Finalize for payroll') }}</flux:button>
                    @endif
                @else
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download" data-test="download-payroll">{{ __('Download') }}</flux:button>
                    @if ($payrollPeriod->paid_at === null)
                        <flux:button size="sm" variant="primary" wire:click="openPaid" data-test="mark-payroll-paid">{{ __('Mark paid') }}</flux:button>
                    @endif
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-1 rounded-lg bg-zinc-50 p-3 text-sm" data-test="payroll-period-state">
            @if ($payrollPeriod === null)
                <span class="font-semibold text-ink">{{ __('Open') }}</span>
                <span class="text-zinc-600">{{ PayPeriods::hasEnded($this->period) ? __(':period is over. Finalize it to schedule its entries for payroll; anything added later is due in the next open period.', ['period' => $periodWords]) : __(':period is not over yet. Entries are added to it as jobs close and adjustments are made.', ['period' => $periodWords]) }}</span>
            @elseif ($payrollPeriod->paid_at === null)
                <span class="font-semibold text-ink">{{ __('Scheduled') }}</span>
                <span class="text-zinc-600">{{ __('Finalized by :name on :day: :count entries, :total. Kept as it is; anything added later is due in the next open period.', ['name' => $payrollPeriod->finalized_by_name ?? __('Unknown'), 'day' => $payrollPeriod->finalized_at->setTimezone(config('app.display_timezone'))->format('M j, Y'), 'count' => $payrollPeriod->entries_count, 'total' => $payrollPeriod->total()]) }}</span>
            @else
                <span class="font-semibold text-emerald-700">{{ __('Paid') }}</span>
                <span class="text-zinc-600">{{ __('Marked paid by :name on :day, payroll reference :reference: :count entries, :total.', ['name' => $payrollPeriod->paid_by_name ?? __('Unknown'), 'day' => $payrollPeriod->paid_at->setTimezone(config('app.display_timezone'))->format('M j, Y'), 'reference' => $payrollPeriod->payroll_reference, 'count' => $payrollPeriod->entries_count, 'total' => $payrollPeriod->total()]) }}</span>
            @endif
        </div>

        @if ($dues->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing is due in :period.', ['period' => $periodWords]) }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($dues as $due)
                    <li wire:key="payroll-due-{{ $due['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="payroll-due">
                        <div class="flex min-w-0 flex-1 flex-col">
                            <flux:link :href="route('finance.payroll.person', $due['id'])" wire:navigate class="font-semibold">{{ $due['name'] }}</flux:link>
                            <span class="text-sm text-zinc-500">{{ trans_choice(':count entry|:count entries', $due['entries']) }}</span>
                        </div>
                        <span class="font-nav font-bold tabular-nums {{ $due['total'] < 0 ? 'text-rose-700' : 'text-ink' }}">{{ Money::format($due['total']) }}</span>
                    </li>
                @endforeach
                <li class="flex items-center justify-between gap-3 pt-2" data-test="payroll-total">
                    <span class="font-semibold text-ink">{{ __('Total') }}</span>
                    <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format((int) $dues->sum('total')) }}</span>
                </li>
            </ul>
        @endif
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="payroll-people">
        <div>
            <flux:heading size="lg" level="2">{{ __('People and pay plans') }}</flux:heading>
            <flux:subheading>{{ __('Open a person to see their ledger, set their plan or add an adjustment.') }}</flux:subheading>
        </div>

        @if ($this->people->isEmpty())
            <flux:text class="text-sm">{{ __('Nobody has variable pay yet.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->people as $person)
                    @php($plans = array_filter($this->plans[$person->id]))
                    <li wire:key="payroll-person-{{ $person->id }}" class="flex flex-col py-3 first:pt-0 last:pb-0" data-test="payroll-person">
                        <flux:link :href="route('finance.payroll.person', $person)" wire:navigate class="font-semibold">{{ $person->name }}</flux:link>
                        @foreach ($plans as $plan)
                            <span wire:key="payroll-person-{{ $person->id }}-{{ $plan->family }}" class="text-sm text-zinc-700">{{ __($plan->label()) }}</span>
                        @endforeach
                        @if ($plans === [])
                            <span class="text-sm text-amber-700">{{ __('No pay plan') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-confirmation-modal
        name="finalize-payroll"
        :title="__('Finalize :period for payroll?', ['period' => $periodWords])"
        :description="__('Its :count entries, :total in all, are scheduled for payroll and kept as they are. Anything added later is due in the next open period.', ['count' => (int) $dues->sum('entries'), 'total' => Money::format((int) $dues->sum('total'))])"
        action="finalize"
        :confirmLabel="__('Finalize')"
        confirmVariant="primary"
        :error="['payroll']"
    />

    <x-confirmation-modal
        name="payroll-paid"
        :title="__('Mark :period paid?', ['period' => $periodWords])"
        :description="__('Payroll has paid what was scheduled. Give payroll\'s reference for the run.')"
        action="markPaid"
        :confirmLabel="__('Mark paid')"
        confirmVariant="primary"
        :error="['payroll']"
    >
        <flux:input wire:model="payrollReference" maxlength="{{ PayrollPeriod::REFERENCE_LENGTH }}" :label="__('Payroll reference (required)')" data-test="payroll-reference" />
    </x-confirmation-modal>
</section>
