<?php

use App\Enums\Permission;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Support\Money;
use App\Support\Projects\Payments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Finance > Receivables (docs/slice-17-customer-payments-plan.md, step 17c;
 * product-architecture.md, 9.2 and 10.6; decision 162): every balance a
 * customer still owes at the Locations this person has Finance for, by how
 * long ago the final invoice went out, with who chases it; and the cash and
 * check payments waiting for Finance's approval.
 *
 * Read only: everything is recorded and approved on each job's page.
 */
new #[Title('Receivables')] class extends Component {
    /** The groups of final balances, by days since the final invoice went out, with how each is said. */
    public const array AGES = [
        'not_due' => 'Not yet due',
        'late' => 'Late, days 7 to 13',
        'escalated' => 'Late, days 14 to 29',
        'collections' => 'Collections, 30 days or more',
    ];

    #[Url(as: 'location')]
    public ?int $locationId = null;

    public function mount(): void
    {
        $this->authorize(Permission::FinanceAccess);
    }

    /**
     * The Locations this person has Finance for.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::FinanceAccess)->sortBy('name')->values();
    }

    /**
     * The Locations shown: the one chosen, or all of them.
     *
     * @return list<int>
     */
    private function shownLocationIds(): array
    {
        $ids = $this->locations->modelKeys();

        return $this->locationId !== null && in_array($this->locationId, $ids, true) ? [$this->locationId] : $ids;
    }

    /**
     * Credits, refunds and write-offs waiting for someone to decide them, at
     * the Locations shown (slice 24, question 8). Decided on each job.
     *
     * @return Collection<int, ProjectBalanceAdjustment>
     */
    #[Computed]
    public function adjustmentsWaiting(): Collection
    {
        return ProjectBalanceAdjustment::query()
            ->where('status', ProjectBalanceAdjustment::STATUS_REQUESTED)
            ->whereHas('project', fn (Builder $project) => $project->whereIn('location_id', $this->shownLocationIds()))
            ->with('project')
            ->orderBy('id')
            ->get();
    }

    /**
     * Where each job with an invoice still owed stands.
     *
     * @return \Illuminate\Support\Collection<int, Payments>
     */
    #[Computed]
    public function owed(): \Illuminate\Support\Collection
    {
        return Project::query()
            ->whereIn('location_id', $this->shownLocationIds())
            ->whereHas('invoices', fn (Builder $invoices) => $invoices->whereNull('voided_at'))
            ->with('location')
            ->orderBy('id')
            ->get()
            ->map(fn (Project $project): Payments => Payments::for($project))
            ->filter(fn (Payments $payments): bool => $payments->balanceCents() > 0)
            ->values();
    }

    /**
     * The final balances still owed, in their groups, the longest owed first.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Payments>>
     */
    #[Computed]
    public function finalByAge(): array
    {
        // A list of its own for each group: array_fill_keys would share one.
        $groups = array_map(fn (): \Illuminate\Support\Collection => collect(), self::AGES);

        foreach ($this->owed->filter(fn (Payments $payments): bool => $payments->hasOpenBalance())->sortByDesc(fn (Payments $payments): int => (int) $payments->daysSinceFinal()) as $payments) {
            $days = (int) $payments->daysSinceFinal();
            $key = match (true) {
                $days >= Payments::COLLECTIONS_DAYS => 'collections',
                $days >= 14 => 'escalated',
                $days >= Payments::DUE_DAYS => 'late',
                default => 'not_due',
            };
            $groups[$key]->push($payments);
        }

        return $groups;
    }

    /**
     * Deposits invoiced and not yet paid in full, on jobs with no final invoice.
     *
     * @return \Illuminate\Support\Collection<int, Payments>
     */
    #[Computed]
    public function depositsOwed(): \Illuminate\Support\Collection
    {
        return $this->owed->filter(fn (Payments $payments): bool => $payments->final() === null && $payments->deposit() !== null)->values();
    }

    /**
     * Cash and check received and waiting for Finance's approval.
     *
     * @return Collection<int, ProjectPayment>
     */
    #[Computed]
    public function awaitingApproval(): Collection
    {
        return ProjectPayment::query()
            ->whereHas('project', fn (Builder $project) => $project->whereIn('location_id', $this->shownLocationIds()))
            ->where('needs_approval', true)
            ->whereNull('approved_at')
            ->whereNull('reversed_at')
            ->with(['project', 'invoice'])
            ->orderBy('received_on')
            ->orderBy('id')
            ->get();
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="finance-receivables">
    @php
        $finalByAge = $this->finalByAge;
        $total = collect($finalByAge)->flatten(1)->sum(fn (Payments $payments): int => $payments->balanceCents()) + $this->depositsOwed->sum(fn (Payments $payments): int => $payments->balanceCents());
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Receivables') }}</flux:heading>
            <flux:subheading>{{ __('What customers still owe, by how long ago the final invoice went out. Record payments and contacts on each job.') }}</flux:subheading>
        </div>

        <div class="text-right">
            <div class="text-sm text-zinc-500">{{ __('Owed in all') }}</div>
            <div class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="receivables-total">{{ Money::format($total) }}</div>
        </div>
    </div>

    @if ($this->locations->count() > 1)
        <div>
            <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="receivables-location">
                <flux:select.option value="">{{ __('All my Locations') }}</flux:select.option>
                @foreach ($this->locations as $option)
                    <flux:select.option :value="$option->id" wire:key="receivables-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    @if ($this->awaitingApproval->isNotEmpty())
        <div class="flex flex-col gap-4 rounded-xl border border-amber-300 bg-card p-5" data-test="receivables-awaiting">
            <div>
                <flux:heading size="lg" level="2">{{ __('Cash and check waiting for approval') }}</flux:heading>
                <flux:subheading>{{ __('Approve each on its job once you see it in the bank account. Someone other than who recorded it approves it.') }}</flux:subheading>
            </div>

            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->awaitingApproval as $payment)
                    <li wire:key="awaiting-{{ $payment->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="receivables-awaiting-payment">
                        <div class="flex min-w-0 flex-1 flex-col">
                            <flux:link :href="route('projects.show', $payment->project)" wire:navigate class="font-semibold">{{ $payment->project->title }}</flux:link>
                            <span class="text-sm text-zinc-700">{{ $payment->amount() }} &middot; {{ __($payment->methodLabel()) }} &middot; {{ __('received :day', ['day' => $payment->received_on->format('M j, Y')]) }} &middot; {{ __('recorded by :name', ['name' => $payment->recorded_by_name ?? __('Unknown')]) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach (self::AGES as $key => $label)
        @php
            $group = $finalByAge[$key];
        @endphp

        <div wire:key="receivables-{{ $key }}" class="flex flex-col gap-4 rounded-xl border {{ $key === 'collections' ? 'border-rose-300' : 'border-zinc-200' }} bg-card p-5" data-test="receivables-{{ $key }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __($label) }}</flux:heading>
                <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format($group->sum(fn (Payments $payments): int => $payments->balanceCents())) }}</span>
            </div>

            @if ($group->isEmpty())
                <flux:text class="text-sm">{{ __('Nothing here.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($group as $owed)
                        @php
                            $lastContact = $owed->contacts->first();
                        @endphp
                        <li wire:key="receivable-{{ $owed->project->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="receivable">
                            <div class="flex min-w-0 flex-1 flex-col">
                                <flux:link :href="route('projects.show', $owed->project)" wire:navigate class="font-semibold">{{ $owed->project->title }}</flux:link>
                                <span class="text-sm text-zinc-700">
                                    {{ trans_choice('{0} Final invoice #:number, sent today; due :day|{1} Final invoice #:number, sent yesterday; due :day|[2,*] Final invoice #:number, sent :count days ago; due :day', (int) $owed->daysSinceFinal(), ['number' => $owed->final()->number, 'day' => $owed->balanceDueOn()->format('M j, Y')]) }}@if ($owed->isLate()) &middot; {{ __('late fee applies') }}@endif
                                </span>
                                <span class="text-sm text-zinc-500">
                                    {{ $owed->project->location->name }} &middot; {{ __('chased by :who', ['who' => __($owed->chasedBy())]) }}
                                    @if ($lastContact !== null)
                                        &middot; {{ __('last contacted :day', ['day' => $lastContact->contacted_on->format('M j')]) }}
                                    @endif
                                </span>
                            </div>
                            <span class="font-nav font-bold tabular-nums text-ink">{{ $owed->balance() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach

    {{-- Credits, refunds and write-offs to decide (slice 24). Shown only when there are some. --}}
    @if ($this->adjustmentsWaiting->isNotEmpty())
        <div class="flex flex-col gap-4 rounded-xl border border-amber-300 bg-card p-5" data-test="receivables-adjustments">
            <div>
                <flux:heading size="lg" level="2">{{ __('Credits, refunds and write-offs waiting for approval') }}</flux:heading>
                <flux:subheading>{{ __('Decide each on its job. Whoever asked cannot decide it.') }}</flux:subheading>
            </div>
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->adjustmentsWaiting as $adjustment)
                    <li wire:key="adjustment-waiting-{{ $adjustment->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="receivables-adjustment">
                        <div class="flex min-w-0 flex-1 flex-col">
                            <flux:link :href="route('projects.show', $adjustment->project)" wire:navigate class="font-semibold">{{ $adjustment->project->title }}</flux:link>
                            <span class="text-sm text-zinc-700">{{ __($adjustment->kindLabel()) }}: {{ $adjustment->reason }}</span>
                            <span class="text-sm text-zinc-500">{{ __('Asked by :name', ['name' => $adjustment->requested_by_name ?? __('Unknown')]) }}</span>
                        </div>
                        <span class="font-nav font-bold tabular-nums text-ink">{{ $adjustment->amount() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="receivables-deposits">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <flux:heading size="lg" level="2">{{ __('Deposits not yet paid') }}</flux:heading>
            <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format($this->depositsOwed->sum(fn (Payments $payments): int => $payments->balanceCents())) }}</span>
        </div>

        @if ($this->depositsOwed->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing here.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->depositsOwed as $owed)
                    <li wire:key="deposit-owed-{{ $owed->project->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="deposit-owed">
                        <div class="flex min-w-0 flex-1 flex-col">
                            <flux:link :href="route('projects.show', $owed->project)" wire:navigate class="font-semibold">{{ $owed->project->title }}</flux:link>
                            <span class="text-sm text-zinc-700">{{ __('Deposit invoice #:number, sent :day', ['number' => $owed->deposit()->number, 'day' => $owed->deposit()->sent_on->format('M j, Y')]) }}</span>
                            <span class="text-sm text-zinc-500">{{ $owed->project->location->name }}</span>
                        </div>
                        <span class="font-nav font-bold tabular-nums text-ink">{{ $owed->balance() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
