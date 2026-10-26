<?php

use App\Actions\Projects\RecordBalanceAdjustments;
use App\Models\Project;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectPayment;
use App\Support\Money;
use App\Support\Projects\Payments;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A job's credits, refunds and bad-debt write-offs
 * (docs/slice-24-refunds-credits-plan.md; decision 181;
 * product-architecture.md, 10.5 and 10.7): each with who asked, why, and who
 * decided. Its Production Manager or a manager asks for a credit or a
 * refund; Finance proposes a write-off; another person decides each; Finance
 * records a refund paid.
 */
new class extends Component {
    #[Locked]
    public int $projectId;

    #[Locked]
    public string $askingFor = '';

    public string $adjustmentAmount = '';

    public string $adjustmentReason = '';

    #[Locked]
    public ?int $adjustmentId = null;

    public string $adjustmentReference = '';

    public string $adjustmentDeclineReason = '';

    public string $refundMethod = '';

    public string $refundPaidOn = '';

    public string $refundReference = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;
    }

    /**
     * A field that was refused and has since been changed no longer shows its
     * refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    #[On('project-payments-changed')]
    public function refresh(): void
    {
        unset($this->project, $this->payments, $this->adjustments);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with('location')->findOrFail($this->projectId);
    }

    #[Computed]
    public function payments(): Payments
    {
        return Payments::for($this->project);
    }

    /**
     * @return Collection<int, ProjectBalanceAdjustment>
     */
    #[Computed]
    public function adjustments(): Collection
    {
        return $this->project->balanceAdjustments()->get();
    }

    /**
     * Whether this person may decide one: by its kind, and never their own.
     */
    public function mayDecide(ProjectBalanceAdjustment $adjustment): bool
    {
        return $adjustment->isRequested()
            && $adjustment->requested_by !== Auth::id()
            && Gate::allows($adjustment->kind === ProjectBalanceAdjustment::WRITE_OFF ? 'approveWriteOff' : 'decideAdjustment', $this->project);
    }

    public function openAsk(string $kind): void
    {
        abort_unless(in_array($kind, array_keys(ProjectBalanceAdjustment::KINDS), true), 404);
        $this->authorize($kind === ProjectBalanceAdjustment::WRITE_OFF ? 'proposeWriteOff' : 'requestAdjustment', $this->project);

        $this->askingFor = $kind;
        $this->adjustmentAmount = $kind === ProjectBalanceAdjustment::WRITE_OFF ? ltrim(Money::format(max(0, $this->payments->balanceCents())), '$') : '';
        $this->adjustmentReason = '';
        $this->resetValidation();

        Flux::modal('adjustment-ask')->show();
    }

    public function ask(RecordBalanceAdjustments $recordBalanceAdjustments): void
    {
        match ($this->askingFor) {
            ProjectBalanceAdjustment::CREDIT => $recordBalanceAdjustments->requestCredit($this->project, $this->adjustmentAmount, $this->adjustmentReason, Auth::user()),
            ProjectBalanceAdjustment::REFUND => $recordBalanceAdjustments->requestRefund($this->project, $this->adjustmentAmount, $this->adjustmentReason, Auth::user()),
            default => $recordBalanceAdjustments->proposeWriteOff($this->project, $this->adjustmentAmount, $this->adjustmentReason, Auth::user()),
        };

        $this->done('adjustment-ask', __('Asked. Someone else decides it.'));
    }

    public function openApprove(int $adjustmentId): void
    {
        $this->chosen($adjustmentId);
        $this->adjustmentReference = '';

        Flux::modal('adjustment-approve')->show();
    }

    public function approve(RecordBalanceAdjustments $recordBalanceAdjustments): void
    {
        $recordBalanceAdjustments->approve($this->project, (int) $this->adjustmentId, $this->adjustmentReference, Auth::user());

        $this->done('adjustment-approve', __('Approved.'));
    }

    public function openDecline(int $adjustmentId): void
    {
        $this->chosen($adjustmentId);
        $this->adjustmentDeclineReason = '';

        Flux::modal('adjustment-decline')->show();
    }

    public function decline(RecordBalanceAdjustments $recordBalanceAdjustments): void
    {
        $recordBalanceAdjustments->decline($this->project, (int) $this->adjustmentId, $this->adjustmentDeclineReason, Auth::user());

        $this->done('adjustment-decline', __('Declined.'));
    }

    public function openRefundPaid(int $adjustmentId): void
    {
        $this->authorize('recordRefundPaid', $this->project);

        $this->adjustmentId = $adjustmentId;
        $this->refundMethod = '';
        $this->refundPaidOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->refundReference = '';
        $this->resetValidation();

        Flux::modal('refund-paid')->show();
    }

    public function recordRefundPaid(RecordBalanceAdjustments $recordBalanceAdjustments): void
    {
        $recordBalanceAdjustments->recordRefundPaid($this->project, (int) $this->adjustmentId, $this->refundMethod, $this->refundPaidOn, $this->refundReference, Auth::user());

        $this->done('refund-paid', __('Refund recorded paid.'));
    }

    private function chosen(int $adjustmentId): void
    {
        $adjustment = $this->adjustments->firstWhere('id', $adjustmentId) ?? abort(404);
        abort_unless($this->mayDecide($adjustment), 403);

        $this->adjustmentId = $adjustment->id;
        $this->resetValidation();
    }

    private function done(string $modal, string $message): void
    {
        unset($this->project, $this->payments, $this->adjustments);

        // The balance, the contract and the budget follow (slice 24).
        $this->dispatch('project-payments-changed');
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-adjustments">
    @php
        $payments = $this->payments;
        $chosen = $this->adjustmentId === null ? null : $this->adjustments->firstWhere('id', $this->adjustmentId);
        $askLabels = [
            ProjectBalanceAdjustment::CREDIT => __('Ask for a credit'),
            ProjectBalanceAdjustment::REFUND => __('Ask for a refund'),
            ProjectBalanceAdjustment::WRITE_OFF => __('Propose a write-off'),
        ];
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Credits, refunds and write-offs') }}</flux:heading>
            <flux:subheading>{{ __('Each is asked for by one person and decided by another. QuickBooks posts them.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('requestAdjustment', $this->project)
                @if ($payments->final() !== null)
                    <flux:button size="sm" wire:click="openAsk('credit')" data-test="ask-credit">{{ $askLabels['credit'] }}</flux:button>
                @endif
                @if ($payments->refundableCents() > 0)
                    <flux:button size="sm" wire:click="openAsk('refund')" data-test="ask-refund">{{ $askLabels['refund'] }}</flux:button>
                @endif
            @endcan
            @can('proposeWriteOff', $this->project)
                @if ($payments->inCollections())
                    <flux:button size="sm" variant="danger" wire:click="openAsk('write_off')" data-test="propose-write-off">{{ $askLabels['write_off'] }}</flux:button>
                @endif
            @endcan
        </div>
    </div>

    @if ($this->adjustments->isEmpty())
        <flux:text class="text-sm">{{ __('None.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($this->adjustments as $adjustment)
                <li wire:key="adjustment-{{ $adjustment->id }}" class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="adjustment" data-kind="{{ $adjustment->kind }}">
                    <div class="flex min-w-0 flex-1 flex-col text-sm">
                        <span class="font-semibold text-ink">{{ __($adjustment->kindLabel()) }} &middot; {{ $adjustment->amount() }}</span>
                        <span class="text-zinc-700">{{ $adjustment->reason }}</span>
                        <span class="text-zinc-500">{{ __('Asked by :name', ['name' => $adjustment->requested_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$adjustment->created_at" /></span>
                        @if ($adjustment->decided_by_name !== null)
                            <span class="text-zinc-500">{{ $adjustment->isApproved() ? __('Approved by :name', ['name' => $adjustment->decided_by_name]) : __('Declined by :name: :reason', ['name' => $adjustment->decided_by_name, 'reason' => $adjustment->declined_reason]) }}@if ($adjustment->quickbooks_reference !== null) &middot; {{ __('QuickBooks :reference', ['reference' => $adjustment->quickbooks_reference]) }}@endif</span>
                        @endif
                        @if ($adjustment->paid_on !== null)
                            <span class="text-zinc-500">{{ __('Paid back :day by :method, reference :reference', ['day' => $adjustment->paid_on->format('M j, Y'), 'method' => lcfirst(__(ProjectPayment::METHODS[$adjustment->paid_method] ?? (string) $adjustment->paid_method)), 'reference' => $adjustment->paid_reference ?? '—']) }}</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($adjustment->isRequested())
                            <flux:badge size="sm" color="amber">{{ __('Waiting for approval') }}</flux:badge>
                        @elseif ($adjustment->isApproved())
                            <flux:badge size="sm" :color="$adjustment->counts() ? 'emerald' : 'sky'">{{ $adjustment->counts() ? __('Approved') : __('Approved, to pay back') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Declined') }}</flux:badge>
                        @endif

                        @if ($this->mayDecide($adjustment))
                            <flux:button size="xs" variant="primary" wire:click="openApprove({{ $adjustment->id }})" data-test="approve-adjustment">{{ __('Approve') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="openDecline({{ $adjustment->id }})" data-test="decline-adjustment">{{ __('Decline') }}</flux:button>
                        @endif
                        @if ($adjustment->kind === ProjectBalanceAdjustment::REFUND && $adjustment->isApproved() && $adjustment->paid_on === null && Gate::allows('recordRefundPaid', $this->project))
                            <flux:button size="xs" variant="primary" wire:click="openRefundPaid({{ $adjustment->id }})" data-test="record-refund-paid">{{ __('Record paid') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="adjustment-ask" class="w-full max-w-lg">
        <form wire:submit="ask" class="flex flex-col gap-5">
            <flux:error name="adjustment" />

            <div>
                <flux:heading size="lg">{{ $askLabels[$askingFor] ?? '' }}</flux:heading>
                <flux:subheading>
                    @switch($askingFor)
                        @case('credit')
                            {{ __('It comes off the final invoice and the job\'s revenue, so its margin and commission follow. Finance or a manager decides it.') }}
                            @break
                        @case('refund')
                            {{ __('Up to :amount can be refunded. Finance or a manager decides it; Finance records it paid back.', ['amount' => Money::format($payments->refundableCents())]) }}
                            @break
                        @default
                            {{ __('The balance Collections could not collect, :amount. A manager decides it. It closes the balance so the job can be closed; the salesperson\'s commission on it is taken back.', ['amount' => $payments->balance()]) }}
                    @endswitch
                </flux:subheading>
            </div>

            <flux:input wire:model="adjustmentAmount" inputmode="decimal" icon="currency-dollar" :label="__('Amount')" data-test="adjustment-amount" />
            <flux:textarea wire:model="adjustmentReason" rows="2" :label="__('Why (required)')" data-test="adjustment-reason" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="save-adjustment">{{ __('Ask') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirmation-modal
        name="adjustment-approve"
        :title="__('Approve this :kind of :amount?', ['kind' => mb_strtolower(__($chosen?->kindLabel() ?? '')), 'amount' => $chosen?->amount() ?? ''])"
        :description="__('It changes what the customer owes as soon as it is approved, or, for a refund, once it is paid back.')"
        action="approve"
        :confirmLabel="__('Approve')"
        confirmVariant="primary"
        :error="['adjustment']"
    >
        <flux:input wire:model="adjustmentReference" maxlength="{{ ProjectBalanceAdjustment::REFERENCE_LENGTH }}" :label="__('QuickBooks reference (optional)')" data-test="adjustment-reference" />
    </x-confirmation-modal>

    <x-confirmation-modal
        name="adjustment-decline"
        :title="__('Decline this request?')"
        :description="__('Nothing changes. Say why; whoever asked sees it.')"
        action="decline"
        :confirmLabel="__('Decline')"
        :error="['adjustment']"
    >
        <flux:textarea wire:model="adjustmentDeclineReason" rows="2" :label="__('Why (required)')" data-test="adjustment-decline-reason" />
    </x-confirmation-modal>

    <flux:modal name="refund-paid" class="w-full max-w-md">
        <form wire:submit="recordRefundPaid" class="flex flex-col gap-5">
            <flux:error name="adjustment" />

            <div>
                <flux:heading size="lg">{{ __('Record the refund paid back') }}</flux:heading>
                <flux:subheading>{{ __('It is added back to what the customer owes, as the money left.') }}</flux:subheading>
            </div>

            <flux:select wire:model="refundMethod" :label="__('How')" data-test="refund-method">
                <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                @foreach (ProjectPayment::METHODS as $key => $label)
                    <flux:select.option :value="$key" wire:key="refund-method-{{ $key }}">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="refundPaidOn" type="date" :label="__('Day')" data-test="refund-paid-on" />
            <flux:input wire:model="refundReference" maxlength="{{ ProjectBalanceAdjustment::REFERENCE_LENGTH }}" :label="__('Reference (optional)')" data-test="refund-reference" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="save-refund-paid">{{ __('Record') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
