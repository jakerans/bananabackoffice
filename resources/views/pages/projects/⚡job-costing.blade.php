<?php

use App\Actions\Projects\RecordJobCosts;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Models\ProjectCost;
use App\Support\Money;
use App\Support\Projects\JobCosting;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A job's costing (docs/slice-19-job-costing-plan.md; decision 170;
 * product-architecture.md, 6.9 and 10.3): its budget, from the contract and
 * the Location's target gross margin, and every direct cost with its
 * receipt.
 *
 * Finance-sensitive: only those who may see payouts. Its Production Manager
 * or a manager there records costs.
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    public string $costKind = '';

    public string $costAmount = '';

    public string $costVendor = '';

    public string $costSpentOn = '';

    public string $costPurchaseOrder = '';

    public string $costReason = '';

    /** @var TemporaryUploadedFile|null The receipt. */
    public $costReceipt = null;

    #[Locked]
    public ?int $costId = null;

    public string $costVoidReason = '';

    public function mount(Project $project): void
    {
        $this->authorize('viewPayouts', $project);

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

    /**
     * A change order or payout changed the contract or the payouts.
     */
    #[On('project-payments-changed')]
    public function refresh(): void
    {
        unset($this->project, $this->costing, $this->allCosts);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with('location')->findOrFail($this->projectId);
    }

    #[Computed]
    public function costing(): JobCosting
    {
        return JobCosting::for($this->project);
    }

    /**
     * Every cost, voided ones too, with its receipts.
     *
     * @return Collection<int, ProjectCost>
     */
    #[Computed]
    public function allCosts(): Collection
    {
        return $this->project->costs()->with('receipts')->get();
    }

    #[Computed]
    public function mayRecord(): bool
    {
        return Gate::allows('manageCosts', $this->project);
    }

    public function openCost(): void
    {
        $this->authorize('manageCosts', $this->project);

        $this->costKind = '';
        $this->costAmount = '';
        $this->costVendor = '';
        $this->costSpentOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->costPurchaseOrder = '';
        $this->costReason = '';
        $this->costReceipt = null;
        $this->resetValidation();

        Flux::modal('job-cost')->show();
    }

    public function saveCost(RecordJobCosts $recordJobCosts): void
    {
        $receipt = $this->costReceipt instanceof TemporaryUploadedFile ? $this->costReceipt : null;

        $recordJobCosts->add($this->project, $this->costKind, $this->costAmount, $this->costVendor, $this->costSpentOn, $this->costPurchaseOrder, $this->costReason, $receipt, Auth::user());

        $this->costReceipt = null;
        $this->done('job-cost', $receipt === null ? __('Cost recorded. Attach its receipt when you have it.') : __('Cost recorded.'));
    }

    public function openSubmit(): void
    {
        $this->authorize('manageCosts', $this->project);
        $this->resetValidation();

        Flux::modal('submit-job-costing')->show();
    }

    public function submit(RecordJobCosts $recordJobCosts): void
    {
        $recordJobCosts->submit($this->project, Auth::user());

        $this->done('submit-job-costing', __('Job costing submitted. Finance reconciles it next.'));
    }

    /**
     * How many accuracy strikes the job's Production Manager has in the last
     * 90 days (question 7).
     */
    #[Computed]
    public function managerStrikes(): int
    {
        return $this->project->production_manager_id === null ? 0 : ProjectAccuracyStrike::query()
            ->where('production_manager_id', $this->project->production_manager_id)
            ->where('created_at', '>=', now()->subDays(ProjectAccuracyStrike::WINDOW_DAYS))
            ->count();
    }

    public function openReceipt(int $costId): void
    {
        $this->authorize('manageCosts', $this->project);

        $this->costId = $this->costOf($costId)->id;
        $this->costReceipt = null;
        $this->resetValidation();

        Flux::modal('job-cost-receipt')->show();
    }

    public function saveReceipt(RecordJobCosts $recordJobCosts): void
    {
        $receipt = $this->costReceipt instanceof TemporaryUploadedFile ? $this->costReceipt : null;

        if ($receipt === null) {
            $this->addError('costReceipt', __('Choose the receipt.'));

            return;
        }

        $recordJobCosts->attachReceipt($this->project, $this->costId ?? 0, $receipt, Auth::user());

        $this->costId = null;
        $this->costReceipt = null;
        $this->done('job-cost-receipt', __('Receipt attached.'));
    }

    public function openVoid(int $costId): void
    {
        $this->authorize('manageCosts', $this->project);

        $this->costId = $this->costOf($costId)->id;
        $this->costVoidReason = '';
        $this->resetValidation();

        Flux::modal('void-job-cost')->show();
    }

    public function voidCost(RecordJobCosts $recordJobCosts): void
    {
        $recordJobCosts->void($this->project, $this->costId ?? 0, $this->costVoidReason, Auth::user());

        $this->costId = null;
        $this->done('void-job-cost', __('Cost voided.'));
    }

    private function costOf(int $costId): ProjectCost
    {
        return $this->costing->costs->firstWhere('id', $costId) ?? abort(409, __('This cost was changed since. Reload the page.'));
    }

    private function done(string $modal, string $message): void
    {
        unset($this->project, $this->costing, $this->allCosts, $this->managerStrikes);

        $this->dispatch('project-job-costing-changed');
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-job-costing">
    @php
        $costing = $this->costing;
        $mayRecord = $this->mayRecord;
        $percent = $costing->marginPercent();
        $missing = $costing->missingReceipts();
        $state = $costing->state();
        $mayChangeCosts = $mayRecord && ! $costing->isLocked();
        $provisional = $costing->provisional();
        $final = $costing->final();
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Job costing') }}</flux:heading>
            <flux:subheading>{{ __('The budget, and every direct cost with its receipt. Before allocations, which Finance adds at the monthly close.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge :color="match ($state) { 'closed' => 'emerald', 'approved' => 'sky', 'submitted', 'reopened' => 'amber', default => 'zinc' }" data-test="job-costing-state">{{ __($costing->stateLabel()) }}</flux:badge>
            @if ($mayChangeCosts)
                <flux:button size="sm" icon="plus" wire:click="openCost" data-test="add-job-cost">{{ __('Add a cost') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Submitted, approved and final (steps 19b and 19c). --}}
    @if ($provisional !== null)
        <div class="flex flex-col gap-1 rounded-lg bg-zinc-50 p-3 text-sm" data-test="job-costing-steps">
            <span class="text-ink">{{ __('Provisional gross margin, as submitted by :name: :figure', ['name' => $provisional->recorded_by_name ?? __('Unknown'), 'figure' => $provisional->marginWords()]) }}</span>
            @if ($final !== null)
                <span class="font-semibold text-ink" data-test="job-final-margin">{{ __('Final gross margin, approved by :name: :figure', ['name' => $final->recorded_by_name ?? __('Unknown'), 'figure' => $final->marginWords()]) }}</span>
            @endif
            @foreach ($costing->steps->whereIn('kind', ['reopened', 'closed']) as $step)
                <span wire:key="job-step-{{ $step->id }}" class="text-zinc-600">
                    {{ $step->kind === 'closed' ? __('Financially closed by :name', ['name' => $step->recorded_by_name ?? __('Unknown')]) : __('Reopened by :name: ":reason"', ['name' => $step->recorded_by_name ?? __('Unknown'), 'reason' => $step->reason]) }}
                    &middot; <x-local-time :at="$step->created_at" />
                </span>
            @endforeach
        </div>
    @endif

    @if ($costing->strikes->isNotEmpty() || $this->managerStrikes > 0)
        <div class="flex flex-col gap-1 text-sm" data-test="job-strikes">
            @foreach ($costing->strikes as $strike)
                <span wire:key="job-strike-{{ $strike->id }}" class="text-rose-700">{{ __('Accuracy strike: :cause, :amount', ['cause' => mb_strtolower(__($strike->causeLabel())), 'amount' => Money::format((int) $strike->amount_cents)]) }} &middot; <x-local-time :at="$strike->created_at" /></span>
            @endforeach
            <span class="text-zinc-600" data-test="job-manager-strikes">{{ trans_choice(':name has :count accuracy strike in the last 90 days.|:name has :count accuracy strikes in the last 90 days.', $this->managerStrikes, ['name' => $this->project->production_manager_name ?? __('The Production Manager')]) }}</span>
        </div>
    @endif

    {{-- The budget (product-architecture.md, 10.3). --}}
    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-test="job-budget">
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Contract') }}</dt>
            <dd class="font-nav font-bold tabular-nums text-ink">{{ Money::format($costing->contractCents()) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Target cost, for a :percent% margin', ['percent' => $costing->targetPercent()]) }}</dt>
            <dd class="font-nav font-bold tabular-nums text-ink">{{ Money::format($costing->targetCostCents()) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Committed') }}</dt>
            <dd class="font-nav font-bold tabular-nums text-ink">{{ Money::format($costing->committedCents()) }}</dd>
            <dd class="text-xs text-zinc-500">{{ __(':payouts payouts, :costs costs', ['payouts' => Money::format($costing->payoutsCents()), 'costs' => Money::format($costing->costsCents())]) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Remaining budget') }}</dt>
            <dd class="font-nav font-bold tabular-nums {{ $costing->remainingCents() < 0 ? 'text-rose-700' : 'text-ink' }}" data-test="job-remaining">{{ Money::format($costing->remainingCents()) }}</dd>
        </div>
    </dl>

    <div class="flex flex-wrap items-center gap-2 text-sm" data-test="job-margin">
        <span class="text-zinc-500">{{ __('Projected gross margin') }}</span>
        <span class="font-semibold tabular-nums {{ $percent !== null && $percent < $costing->targetPercent() ? 'text-rose-700' : 'text-ink' }}">{{ $costing->marginWords() }}</span>
        <span class="text-zinc-500">{{ __('before allocations') }}</span>
        @if ($costing->afterPayouts->packagesWithoutPayout > 0)
            <span class="text-amber-700">&middot; {{ trans_choice(':count work package has no payout yet.|:count work packages have no payout yet.', $costing->afterPayouts->packagesWithoutPayout) }}</span>
        @endif
    </div>

    @if ($missing->isNotEmpty())
        <flux:text class="text-sm text-amber-700" data-test="job-missing-receipts">{{ trans_choice(':count cost is missing its receipt.|:count costs are missing their receipts.', $missing->count()) }}</flux:text>
    @endif

    @if ($this->allCosts->isEmpty())
        <flux:text class="text-sm" data-test="job-costs-none">{{ __('No costs recorded yet. Subcontractor payouts come from the work packages.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200" data-test="job-costs">
            @foreach ($this->allCosts as $cost)
                <li wire:key="job-cost-{{ $cost->id }}" class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="job-cost">
                    <div class="flex min-w-56 flex-1 flex-col text-sm">
                        <span class="{{ $cost->isVoided() ? 'text-zinc-500 line-through' : 'text-ink' }}">
                            <span class="font-semibold">{{ $cost->amount() }}</span> &middot; {{ __($cost->kindLabel()) }} &middot; {{ $cost->vendor }} &middot; {{ $cost->spent_on->format('M j, Y') }}@if ($cost->purchase_order !== null) &middot; {{ __('PO :number', ['number' => $cost->purchase_order]) }}@endif
                        </span>
                        @if ($cost->reason !== null)
                            <span class="text-zinc-600">&ldquo;{{ $cost->reason }}&rdquo;</span>
                        @endif
                        <span class="text-zinc-500">{{ __('Recorded by :name', ['name' => $cost->recorded_by_name ?? __('Unknown')]) }}</span>
                        @if ($cost->isVoided())
                            <span class="text-zinc-600">{{ __('Voided by :name: ":reason"', ['name' => $cost->voided_by_name ?? __('Unknown'), 'reason' => $cost->voided_reason]) }}</span>
                        @elseif ($cost->receipts->isEmpty())
                            <span class="text-amber-700" data-test="job-cost-no-receipt">{{ __('Missing its receipt') }}</span>
                        @endif
                        @foreach ($cost->receipts as $receipt)
                            <a wire:key="cost-receipt-{{ $receipt->id }}" href="{{ route('attachments.open', $receipt) }}" target="_blank" rel="noopener" class="font-medium text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink" data-test="job-cost-receipt">{{ __('Receipt: :name', ['name' => $receipt->original_name]) }}</a>
                        @endforeach
                    </div>

                    @if ($mayRecord && ! $cost->isVoided())
                        <div class="flex shrink-0 flex-wrap gap-1">
                            @if ($cost->receipts->isEmpty())
                                <flux:button size="sm" variant="ghost" icon="paper-clip" wire:click="openReceipt({{ $cost->id }})" data-test="attach-cost-receipt">{{ __('Receipt') }}</flux:button>
                            @endif
                            @if ($mayChangeCosts)
                                <flux:button size="sm" variant="ghost" wire:click="openVoid({{ $cost->id }})" data-test="void-job-cost">{{ __('Void') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Submitting job costing (step 19b): once the customer has signed off. --}}
    @if ($mayRecord && $state === 'open')
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-3">
            <flux:text class="text-sm">
                {{ $this->project->production_state === ProjectProductionState::SignedOff ? __('When every cost is in, submit job costing. Changes after that are corrections, with why.') : __('Job costing is submitted once the customer has signed off.') }}
            </flux:text>
            @if ($this->project->production_state === ProjectProductionState::SignedOff)
                <flux:button size="sm" variant="primary" wire:click="openSubmit" data-test="submit-job-costing">{{ __('Submit job costing') }}</flux:button>
            @endif
        </div>
    @endif

    @if ($mayRecord)
        <x-confirmation-modal
            name="submit-job-costing"
            :title="__('Submit job costing?')"
            :description="__('The provisional gross margin is kept as it is now: :figure. A cost added or voided after this is a correction, with why; materials or rentals without a purchase order count as accuracy strikes.', ['figure' => $costing->marginWords()])"
            action="submit"
            :confirm-label="__('Submit')"
            confirm-variant="primary"
            :error="['jobCosting']"
        />

        <flux:modal name="job-cost" class="w-full max-w-lg">
            <form wire:submit="saveCost" class="flex flex-col gap-5">
                <flux:error name="cost" />

                <div>
                    <flux:heading size="lg">{{ __('Add a cost') }}</flux:heading>
                    <flux:subheading>{{ __('A direct cost of this job. Subcontractor payouts are set on the work packages.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="costKind" :label="__('Kind')" data-test="cost-kind">
                        <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                        @foreach (ProjectCost::KINDS as $key => $label)
                            <flux:select.option :value="$key" wire:key="cost-kind-{{ $key }}">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="costAmount" inputmode="decimal" icon="currency-dollar" :label="__('Amount')" data-test="cost-amount" />
                    <flux:input wire:model="costVendor" maxlength="{{ ProjectCost::VENDOR_LENGTH }}" :label="__('Paid to')" data-test="cost-vendor" />
                    <flux:input wire:model="costSpentOn" type="date" :label="__('Day')" data-test="cost-spent-on" />
                    <flux:input wire:model="costPurchaseOrder" maxlength="{{ ProjectCost::PURCHASE_ORDER_LENGTH }}" :label="__('Purchase order')" :description="__('Materials and rentals need one.')" data-test="cost-purchase-order" />
                </div>

                <flux:textarea wire:model="costReason" rows="2" :label="$state === 'open' ? __('Note (optional)') : __('Why it is added now (required: job costing was submitted)')" data-test="cost-reason" />

                <div class="flex flex-col gap-2">
                    <flux:input type="file" wire:model="costReceipt" accept=".pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/jpeg,image/png,image/heic" :label="__('The receipt (attach it now or later)')" data-test="cost-receipt-file" />
                    <flux:text size="sm" wire:loading wire:target="costReceipt">{{ __('Uploading...') }}</flux:text>
                    <flux:error name="attachment" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="costReceipt" data-test="save-job-cost">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="job-cost-receipt" class="w-full max-w-md">
            <form wire:submit="saveReceipt" class="flex flex-col gap-5">
                <flux:error name="cost" />

                <div>
                    <flux:heading size="lg">{{ __('Attach the receipt') }}</flux:heading>
                </div>

                <div class="flex flex-col gap-2">
                    <flux:input type="file" wire:model="costReceipt" accept=".pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/jpeg,image/png,image/heic" :label="__('The receipt')" data-test="cost-receipt-only-file" />
                    <flux:text size="sm" wire:loading wire:target="costReceipt">{{ __('Uploading...') }}</flux:text>
                    <flux:error name="attachment" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="costReceipt" data-test="save-cost-receipt">{{ __('Attach') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="void-job-cost"
            :title="__('Void this cost?')"
            :description="__('It stops counting and stays on record, crossed out, with why.')"
            action="voidCost"
            :confirm-label="__('Void')"
            :error="['cost', 'costVoidReason']"
        >
            <flux:textarea wire:model="costVoidReason" rows="2" :label="__('Why (required)')" data-test="cost-void-reason" />
        </x-confirmation-modal>
    @endif
</div>
