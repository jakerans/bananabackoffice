<?php

use App\Actions\Projects\RecordChangeOrders;
use App\Models\Project;
use App\Models\ProjectChangeOrder;
use App\Support\Money;
use App\Support\Projects\AfterPayouts;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A job's customer change orders (docs/slice-18-change-orders-plan.md, step
 * 18a; decision 166; product-architecture.md, 6.7): what changes, the price
 * change before tax, the days it adds, and the customer's signature with the
 * signed copy; and the contract total they make with the price sold.
 *
 * Everyone who may see the job reads it. Its Production Manager or a
 * manager there drafts, signs off and withdraws; a price that comes down is
 * a manager's.
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    public string $changeDescription = '';

    public string $changePrice = '';

    public string $changeDays = '';

    public string $changeReason = '';

    #[Locked]
    public ?int $changeOrderId = null;

    public string $signedBy = '';

    public string $signedMethod = '';

    public string $signedOn = '';

    /** @var TemporaryUploadedFile|null The signed copy. */
    public $signedCopy = null;

    public string $withdrawReason = '';

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

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with('location')->findOrFail($this->projectId);
    }

    /**
     * @return Collection<int, ProjectChangeOrder>
     */
    #[Computed]
    public function changeOrders(): Collection
    {
        return $this->project->changeOrders()->with('signedCopies')->get();
    }

    #[Computed]
    public function mayManage(): bool
    {
        return Gate::allows('manageChangeOrders', $this->project);
    }

    /**
     * Whether this person may see what the job brings in after payouts:
     * finance-sensitive (slice 18, step 18c).
     */
    #[Computed]
    public function seesPayouts(): bool
    {
        return Gate::allows('viewPayouts', $this->project);
    }

    #[Computed]
    public function afterPayouts(): AfterPayouts
    {
        return AfterPayouts::for($this->project);
    }

    public function openDraft(): void
    {
        $this->authorize('manageChangeOrders', $this->project);

        $this->changeDescription = '';
        $this->changePrice = '';
        $this->changeDays = '0';
        $this->changeReason = '';
        $this->resetValidation();

        Flux::modal('draft-change-order')->show();
    }

    public function saveDraft(RecordChangeOrders $recordChangeOrders): void
    {
        $recordChangeOrders->draft($this->project, $this->changeDescription, $this->changePrice, $this->changeDays, $this->changeReason, Auth::user());

        $this->done('draft-change-order', __('Change order drafted. It counts once the customer signs it.'));
    }

    public function openSign(int $changeOrderId): void
    {
        $this->authorize('manageChangeOrders', $this->project);

        $this->changeOrderId = $this->draftOf($changeOrderId)->id;
        $this->signedBy = '';
        $this->signedMethod = '';
        $this->signedOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->signedCopy = null;
        $this->resetValidation();

        Flux::modal('sign-change-order')->show();
    }

    public function saveSigned(RecordChangeOrders $recordChangeOrders): void
    {
        $copy = $this->signedCopy instanceof TemporaryUploadedFile ? $this->signedCopy : null;

        $recordChangeOrders->sign($this->project, $this->changeOrderId ?? 0, $this->signedBy, $this->signedMethod, $this->signedOn, $copy, Auth::user());

        $this->changeOrderId = null;
        $this->signedCopy = null;
        $this->done('sign-change-order', __('Signed. The contract total includes it now.'));
    }

    public function openWithdraw(int $changeOrderId): void
    {
        $this->authorize('manageChangeOrders', $this->project);

        $this->changeOrderId = $this->draftOf($changeOrderId)->id;
        $this->withdrawReason = '';
        $this->resetValidation();

        Flux::modal('withdraw-change-order')->show();
    }

    public function withdraw(RecordChangeOrders $recordChangeOrders): void
    {
        $recordChangeOrders->withdraw($this->project, $this->changeOrderId ?? 0, $this->withdrawReason, Auth::user());

        $this->changeOrderId = null;
        $this->done('withdraw-change-order', __('Change order withdrawn.'));
    }

    private function draftOf(int $changeOrderId): ProjectChangeOrder
    {
        $order = $this->changeOrders->firstWhere('id', $changeOrderId);

        return $order !== null && $order->isDraft() ? $order : abort(409, __('This change order was changed since. Reload the page.'));
    }

    /**
     * Read everything again, tell the job page, close the dialog and say
     * what was done.
     */
    private function done(string $modal, string $message): void
    {
        unset($this->project, $this->changeOrders, $this->afterPayouts);

        $this->dispatch('project-payments-changed');
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-change-orders">
    @php
        $project = $this->project;
        $orders = $this->changeOrders;
        $signedCents = (int) $orders->filter(fn (ProjectChangeOrder $order): bool => $order->isSigned())->sum('price_change_cents');
        $chosen = $changeOrderId === null ? null : $orders->firstWhere('id', $changeOrderId);
        $mayManage = $this->mayManage;
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Change orders') }}</flux:heading>
            <flux:subheading>{{ __('Changes to what the customer agreed, each signed by them. The original agreement is kept.') }}</flux:subheading>
        </div>

        @if ($mayManage)
            <flux:button size="sm" icon="plus" wire:click="openDraft" data-test="new-change-order">{{ __('New change order') }}</flux:button>
        @endif
    </div>

    <dl class="grid grid-cols-3 gap-3" data-test="contract-totals">
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Sold for') }}</dt>
            <dd class="font-nav text-lg font-bold tabular-nums text-ink">{{ $project->soldFor() }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Signed changes') }}</dt>
            <dd class="font-nav text-lg font-bold tabular-nums text-ink">{{ ($signedCents > 0 ? '+' : '').Money::format($signedCents) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Contract total, before tax') }}</dt>
            <dd class="font-nav text-lg font-bold tabular-nums text-ink" data-test="contract-total">{{ Money::format($project->sold_cents + $signedCents) }}</dd>
        </div>
    </dl>

    {{-- What the job brings in after subcontractor payouts (step 18c): never "gross margin" until every cost is counted. --}}
    @if ($this->seesPayouts)
        @php
            $after = $this->afterPayouts;
        @endphp
        <div class="flex flex-col gap-1 rounded-lg bg-zinc-50 p-3 text-sm" data-test="after-payouts">
            <span class="{{ $after->afterCents() < 0 ? 'text-rose-700' : 'text-ink' }}">
                <span class="font-semibold">{{ __('After subcontractor payouts: :figure', ['figure' => $after->words()]) }}</span>
                &middot; {{ __('payouts :amount', ['amount' => Money::format($after->payoutsCents)]) }}
            </span>
            @if ($after->afterCents() < 0)
                <span class="font-medium text-rose-700" data-test="after-payouts-loss">{{ __('The payouts are more than the contract.') }}</span>
            @endif
            @if ($after->packagesWithoutPayout > 0)
                <span class="text-amber-700" data-test="after-payouts-incomplete">{{ trans_choice(':count work package has no payout yet, so this is not complete.|:count work packages have no payout yet, so this is not complete.', $after->packagesWithoutPayout) }}</span>
            @endif
            <span class="text-zinc-500">{{ __('Before materials and every other cost, which come with job costing.') }}</span>
        </div>
    @endif

    @if ($orders->isEmpty())
        <flux:text class="text-sm" data-test="change-orders-none">{{ __('No change orders.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($orders as $order)
                <li wire:key="change-order-{{ $order->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="change-order" data-status="{{ $order->status }}">
                    <div class="flex min-w-64 flex-1 flex-col gap-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold {{ $order->isWithdrawn() ? 'text-zinc-500 line-through' : 'text-ink' }}">{{ __('Change order :number', ['number' => $order->number]) }} &middot; {{ __($order->priceChange()) }}</span>
                            @if ($order->isSigned())
                                <flux:badge size="sm" color="emerald">{{ __('Signed') }}</flux:badge>
                            @elseif ($order->isDraft())
                                <flux:badge size="sm" color="amber">{{ __('Waiting for the customer\'s signature') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Withdrawn') }}</flux:badge>
                            @endif
                        </span>
                        <span class="whitespace-pre-line text-sm text-ink">{{ $order->description }}</span>
                        @if ($order->working_days_added > 0)
                            <span class="text-sm text-zinc-700">{{ trans_choice('Adds :count working day.|Adds :count working days.', $order->working_days_added) }}</span>
                        @endif
                        @if ($order->reason !== null)
                            <span class="text-sm text-zinc-600">{{ __('Why the price comes down: ":reason"', ['reason' => $order->reason]) }}</span>
                        @endif
                        <span class="text-sm text-zinc-500">
                            @if ($order->isSigned())
                                {{ __('Signed by :person, :how, on :day; recorded by :name', ['person' => $order->signed_by_person, 'how' => mb_strtolower(__($order->signed_method->label())), 'day' => $order->signed_on->format('M j, Y'), 'name' => $order->signed_recorded_by_name ?? __('Unknown')]) }}
                            @elseif ($order->isWithdrawn())
                                {{ __('Withdrawn by :name: ":reason"', ['name' => $order->withdrawn_by_name ?? __('Unknown'), 'reason' => $order->withdrawn_reason]) }}
                            @else
                                {{ __('Drafted by :name', ['name' => $order->created_by_name ?? __('Unknown')]) }}
                            @endif
                        </span>
                        @foreach ($order->signedCopies as $copy)
                            <a wire:key="signed-copy-{{ $copy->id }}" href="{{ route('attachments.open', $copy) }}" target="_blank" rel="noopener" class="text-sm font-medium text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink" data-test="signed-copy">{{ __('Signed copy: :name', ['name' => $copy->original_name]) }}</a>
                        @endforeach
                    </div>

                    @if ($mayManage && $order->isDraft())
                        <div class="flex shrink-0 flex-wrap gap-1">
                            <flux:button size="sm" variant="primary" wire:click="openSign({{ $order->id }})" data-test="sign-change-order">{{ __('The customer signed') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="openWithdraw({{ $order->id }})" data-test="withdraw-change-order">{{ __('Withdraw') }}</flux:button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($mayManage)
        <flux:modal name="draft-change-order" class="w-full max-w-lg">
            <form wire:submit="saveDraft" class="flex flex-col gap-5">
                <flux:error name="changeOrder" />

                <div>
                    <flux:heading size="lg">{{ __('New change order') }}</flux:heading>
                    <flux:subheading>{{ __('What changes and by how much, before tax. It counts once the customer signs it.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="changeDescription" rows="3" :label="__('What changes')" :placeholder="__('Add the back bedroom: walls and ceiling, two coats.')" data-test="change-description" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.400ms="changePrice" inputmode="decimal" :label="__('Price change, before tax')" :description="__('Use - for a price that comes down; 0 for none.')" data-test="change-price" />
                    <flux:input wire:model="changeDays" inputmode="numeric" :label="__('Working days it adds')" data-test="change-days" />
                </div>

                @php
                    $typedChange = RecordChangeOrders::typedPrice($changePrice);
                @endphp
                @if ($this->seesPayouts && $typedChange !== null)
                    <flux:text class="text-sm" data-test="change-after-payouts">
                        {{ __('After subcontractor payouts: :now now; :then once this is signed, unless the payouts change too.', ['now' => $this->afterPayouts->words(), 'then' => $this->afterPayouts->withPriceChange($typedChange)->words()]) }}
                    </flux:text>
                @endif

                <flux:textarea wire:model="changeReason" rows="2" :label="__('Why the price comes down (managers only, required then)')" data-test="change-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-change-order">{{ __('Draft') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="sign-change-order" class="w-full max-w-lg">
            <form wire:submit="saveSigned" class="flex flex-col gap-5">
                <flux:error name="changeOrder" />

                <div>
                    <flux:heading size="lg">{{ $chosen === null ? __('The customer signed') : __('Change order :number: signed', ['number' => $chosen->number]) }}</flux:heading>
                    <flux:subheading>{{ __('Who signed, how and when, with the signed copy. From then on it counts in the contract total.') }}</flux:subheading>
                </div>

                @if ($this->seesPayouts && $chosen !== null)
                    <flux:text class="text-sm" data-test="sign-after-payouts">
                        {{ __('After subcontractor payouts: :now now; :then once this is signed.', ['now' => $this->afterPayouts->words(), 'then' => $this->afterPayouts->withPriceChange($chosen->price_change_cents)->words()]) }}
                    </flux:text>
                @endif

                <flux:input wire:model="signedBy" maxlength="{{ ProjectChangeOrder::PERSON_LENGTH }}" :label="__('Who signed')" data-test="signed-by" />

                <flux:radio.group wire:model="signedMethod" :label="__('How')" data-test="signed-method">
                    @foreach (ProjectChangeOrder::SIGNING_METHODS as $method)
                        <flux:radio :value="$method->value" :label="__($method->label())" wire:key="signed-method-{{ $method->value }}" />
                    @endforeach
                </flux:radio.group>

                <flux:input wire:model="signedOn" type="date" :label="__('Day signed')" class="max-w-52" data-test="signed-on" />

                <div class="flex flex-col gap-2">
                    <flux:input type="file" wire:model="signedCopy" accept=".pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/jpeg,image/png,image/heic" :label="__('The signed copy')" :description="__('PDF, or a JPEG, PNG or HEIC photo, up to 20 MB.')" data-test="signed-copy-file" />
                    <flux:text size="sm" wire:loading wire:target="signedCopy">{{ __('Uploading...') }}</flux:text>
                    <flux:error name="attachment" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="signedCopy" data-test="save-signed">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="withdraw-change-order"
            :title="__('Withdraw this change order?')"
            :description="__('The customer will not sign it. It stays on record, crossed out, with why.')"
            action="withdraw"
            :confirm-label="__('Withdraw')"
            :error="['changeOrder', 'withdrawReason']"
        >
            <flux:textarea wire:model="withdrawReason" rows="2" :label="__('Why (required)')" data-test="withdraw-reason" />
        </x-confirmation-modal>
    @endif
</div>
