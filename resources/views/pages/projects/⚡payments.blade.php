<?php

use App\Actions\Projects\RecordPayments;
use App\Models\Project;
use App\Models\ProjectInvoice;
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
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A Project's payments (docs/slice-17-customer-payments-plan.md, step 17a;
 * decision 162): the deposit and final invoices as QuickBooks sent them, the
 * payments against each, the balance, and the payment state.
 *
 * Everyone who may see the Project reads it. Its Production Manager, a
 * manager there or Finance records invoices and payments; Finance approves
 * cash and check, and reverses a payment.
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    public string $invoiceKind = '';

    public string $invoiceNumber = '';

    public string $invoiceAmount = '';

    public string $invoiceSentOn = '';

    #[Locked]
    public ?int $voidingInvoiceId = null;

    public string $invoiceReason = '';

    #[Locked]
    public ?int $paymentInvoiceId = null;

    public string $paymentAmount = '';

    public string $paymentMethod = '';

    public string $paymentReceivedOn = '';

    public string $paymentReference = '';

    /** @var TemporaryUploadedFile|null The receipt, for cash and check. */
    public $paymentReceipt = null;

    #[Locked]
    public ?int $paymentId = null;

    public string $paymentReason = '';

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

    #[Computed]
    public function standing(): Payments
    {
        return Payments::for($this->project);
    }

    /**
     * Every invoice, voided ones too, with every payment, the earliest first.
     *
     * @return Collection<int, ProjectInvoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return $this->project->invoices()->with(['payments.receipts'])->get();
    }

    #[Computed]
    public function mayRecord(): bool
    {
        return Gate::allows('recordPayments', $this->project);
    }

    #[Computed]
    public function mayApprove(): bool
    {
        return Gate::allows('approvePayments', $this->project);
    }

    public function openInvoice(string $kind): void
    {
        $this->authorize('recordPayments', $this->project);

        $standing = $this->standing;
        $sold = $this->project->sold_cents;
        // A suggestion only, before tax (decision 162): the person records the total QuickBooks shows.
        $suggested = $kind === ProjectInvoice::KIND_DEPOSIT
            ? intdiv($sold, 2)
            : max(0, $sold - ($standing->deposit()?->amount_cents ?? 0));

        $this->invoiceKind = $kind;
        $this->invoiceNumber = '';
        $this->invoiceAmount = $suggested > 0 ? number_format($suggested / 100, 2, '.', '') : '';
        $this->invoiceSentOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('project-invoice')->show();
    }

    public function saveInvoice(RecordPayments $recordPayments): void
    {
        $recordPayments->recordInvoice($this->project, $this->invoiceKind, $this->invoiceNumber, $this->invoiceAmount, $this->invoiceSentOn, Auth::user());

        $this->done('project-invoice', __('Invoice recorded.'));
    }

    public function openVoid(int $invoiceId): void
    {
        $this->authorize('recordPayments', $this->project);

        $this->voidingInvoiceId = $this->standing->invoices->firstWhere('id', $invoiceId)?->id ?? abort(409, __('This invoice was changed since. Reload the page.'));
        $this->invoiceReason = '';
        $this->resetValidation();

        Flux::modal('void-invoice')->show();
    }

    public function voidInvoice(RecordPayments $recordPayments): void
    {
        $recordPayments->voidInvoice($this->project, $this->voidingInvoiceId ?? 0, $this->invoiceReason, Auth::user());

        $this->voidingInvoiceId = null;
        $this->done('void-invoice', __('Invoice voided.'));
    }

    public function openPayment(int $invoiceId): void
    {
        $this->authorize('recordPayments', $this->project);

        $invoice = $this->standing->invoices->firstWhere('id', $invoiceId) ?? abort(409, __('This invoice was changed since. Reload the page.'));
        $owed = $invoice->amount_cents - $this->standing->paidOnCents($invoice, withAwaiting: true);

        $this->paymentInvoiceId = $invoice->id;
        $this->paymentAmount = $owed > 0 ? number_format($owed / 100, 2, '.', '') : '';
        $this->paymentMethod = '';
        $this->paymentReceivedOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->paymentReference = '';
        $this->paymentReceipt = null;
        $this->resetValidation();

        Flux::modal('project-payment')->show();
    }

    public function savePayment(RecordPayments $recordPayments): void
    {
        $receipt = in_array($this->paymentMethod, ProjectPayment::NEEDS_APPROVAL, true) && $this->paymentReceipt instanceof TemporaryUploadedFile ? $this->paymentReceipt : null;

        $recordPayments->recordPayment($this->project, $this->paymentInvoiceId ?? 0, $this->paymentAmount, $this->paymentMethod, $this->paymentReceivedOn, $this->paymentReference, $receipt, Auth::user());

        $this->paymentInvoiceId = null;
        $this->paymentReceipt = null;
        $this->done('project-payment', $receipt === null ? __('Payment recorded.') : __('Payment recorded. It counts once Finance approves it.'));
    }

    public function openApprove(int $paymentId): void
    {
        $this->authorize('approvePayments', $this->project);

        $this->paymentId = $this->paymentOf($paymentId)->id;
        $this->resetValidation();

        Flux::modal('approve-payment')->show();
    }

    public function approvePayment(RecordPayments $recordPayments): void
    {
        $recordPayments->approvePayment($this->project, $this->paymentId ?? 0, Auth::user());

        $this->paymentId = null;
        $this->done('approve-payment', __('Payment approved.'));
    }

    public function openReverse(int $paymentId): void
    {
        $this->authorize('approvePayments', $this->project);

        $this->paymentId = $this->paymentOf($paymentId)->id;
        $this->paymentReason = '';
        $this->resetValidation();

        Flux::modal('reverse-payment')->show();
    }

    public function reversePayment(RecordPayments $recordPayments): void
    {
        $recordPayments->reversePayment($this->project, $this->paymentId ?? 0, $this->paymentReason, Auth::user());

        $this->paymentId = null;
        $this->done('reverse-payment', __('Payment reversed.'));
    }

    private function paymentOf(int $paymentId): ProjectPayment
    {
        return $this->standing->payments->firstWhere('id', $paymentId) ?? abort(409, __('This payment was changed since. Reload the page.'));
    }

    /**
     * Read everything again, tell the Project page, close the dialog and say
     * what was done.
     */
    private function done(string $modal, string $message): void
    {
        unset($this->project, $this->standing, $this->invoices);

        $this->dispatch('project-payments-changed');
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-payments">
    @php
        $standing = $this->standing;
        $state = $standing->state();
        $mayRecord = $this->mayRecord;
        $mayApprove = $this->mayApprove;
        $awaiting = $standing->awaitingApprovalCents();
        $paying = $paymentInvoiceId === null ? null : $standing->invoices->firstWhere('id', $paymentInvoiceId);
        $chosenPayment = $paymentId === null ? null : $standing->payments->firstWhere('id', $paymentId);
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Payments') }}</flux:heading>
            <flux:subheading>{{ __('The deposit and final invoices as QuickBooks sent them, and what the customer paid against each.') }}</flux:subheading>
        </div>

        <flux:badge :color="match ($state) { Payments::PAID_IN_FULL => 'emerald', Payments::DEPOSIT_PAID => 'sky', default => 'amber' }" data-test="payment-state">{{ __(Payments::STATES[$state]) }}</flux:badge>
    </div>

    <dl class="grid grid-cols-3 gap-3" data-test="payment-totals">
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Invoiced') }}</dt>
            <dd class="font-nav text-lg font-bold tabular-nums text-ink">{{ Money::format($standing->invoicedCents()) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Paid') }}</dt>
            <dd class="font-nav text-lg font-bold tabular-nums text-ink">{{ Money::format($standing->paidCents()) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-500">{{ __('Balance') }}</dt>
            <dd class="font-nav text-lg font-bold tabular-nums text-ink" data-test="payment-balance">{{ $standing->balance() }}</dd>
        </div>
    </dl>

    @if ($awaiting > 0)
        <flux:text class="text-sm text-amber-700" data-test="payment-awaiting">{{ __(':amount in cash or check waits for Finance\'s approval.', ['amount' => Money::format($awaiting)]) }}</flux:text>
    @endif

    @if ($this->invoices->isEmpty())
        <flux:text class="text-sm" data-test="invoices-none">{{ __('No invoice recorded yet.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200" data-test="invoices">
            @foreach ($this->invoices as $invoice)
                @php
                    $owed = $invoice->amount_cents - $standing->paidOnCents($invoice, withAwaiting: true);
                @endphp

                <li wire:key="invoice-{{ $invoice->id }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0" data-test="invoice" data-kind="{{ $invoice->kind }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-64 flex-1 flex-col">
                            <span class="font-semibold {{ $invoice->isVoided() ? 'text-zinc-500 line-through' : 'text-ink' }}">
                                {{ __(':invoice #:number', ['invoice' => __($invoice->label()), 'number' => $invoice->number]) }} &middot; {{ $invoice->amount() }}
                            </span>
                            <span class="text-sm text-zinc-500">
                                {{ __('Sent :day', ['day' => $invoice->sent_on->format('M j, Y')]) }} &middot; {{ __('recorded by :name', ['name' => $invoice->recorded_by_name ?? __('Unknown')]) }}
                            </span>
                            @if ($invoice->isVoided())
                                <span class="text-sm text-zinc-600" data-test="invoice-voided">{{ __('Voided by :name: ":reason"', ['name' => $invoice->voided_by_name ?? __('Unknown'), 'reason' => $invoice->voided_reason]) }}</span>
                            @endif
                        </div>

                        @if (! $invoice->isVoided() && $mayRecord)
                            <div class="flex shrink-0 flex-wrap gap-1">
                                @if ($owed > 0)
                                    <flux:button size="sm" variant="primary" wire:click="openPayment({{ $invoice->id }})" data-test="record-payment">{{ __('Record a payment') }}</flux:button>
                                @endif
                                @if ($invoice->payments->every(fn (ProjectPayment $payment): bool => $payment->isReversed()))
                                    <flux:button size="sm" variant="ghost" wire:click="openVoid({{ $invoice->id }})" data-test="void-invoice">{{ __('Void') }}</flux:button>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($invoice->payments->isNotEmpty())
                        <ul class="flex flex-col gap-2 rounded-lg bg-zinc-50 p-3" data-test="payments">
                            @foreach ($invoice->payments as $payment)
                                <li wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-start justify-between gap-2" data-test="payment">
                                    <div class="flex min-w-56 flex-1 flex-col text-sm">
                                        <span class="{{ $payment->isReversed() ? 'text-zinc-500 line-through' : 'text-ink' }}">
                                            {{ $payment->amount() }} &middot; {{ __($payment->methodLabel()) }} &middot; {{ $payment->received_on->format('M j, Y') }}@if ($payment->reference !== null) &middot; {{ $payment->reference }}@endif
                                        </span>
                                        <span class="text-zinc-500">{{ __('Recorded by :name', ['name' => $payment->recorded_by_name ?? __('Unknown')]) }}</span>
                                        @if ($payment->isReversed())
                                            <span class="text-zinc-600" data-test="payment-reversed">{{ __('Reversed by :name: ":reason"', ['name' => $payment->reversed_by_name ?? __('Unknown'), 'reason' => $payment->reversed_reason]) }}</span>
                                        @elseif ($payment->isAwaitingApproval())
                                            <span class="text-amber-700" data-test="payment-awaiting-approval">{{ __('Waits for Finance\'s approval; it does not count until then.') }}</span>
                                        @elseif ($payment->approved_at !== null)
                                            <span class="text-zinc-500">{{ __('Approved by :name', ['name' => $payment->approved_by_name ?? __('Unknown')]) }}</span>
                                        @endif
                                        @foreach ($payment->receipts as $receipt)
                                            <a wire:key="receipt-{{ $receipt->id }}" href="{{ route('attachments.open', $receipt) }}" target="_blank" rel="noopener" class="font-medium text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink" data-test="payment-receipt">{{ __('Receipt: :name', ['name' => $receipt->original_name]) }}</a>
                                        @endforeach
                                    </div>

                                    @if ($mayApprove && ! $payment->isReversed())
                                        <div class="flex shrink-0 flex-wrap gap-1">
                                            @if ($payment->isAwaitingApproval())
                                                <flux:button size="sm" variant="primary" wire:click="openApprove({{ $payment->id }})" data-test="approve-payment">{{ __('Approve') }}</flux:button>
                                            @endif
                                            <flux:button size="sm" variant="ghost" wire:click="openReverse({{ $payment->id }})" data-test="reverse-payment">{{ __('Reverse') }}</flux:button>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($mayRecord && ($standing->deposit() === null || $standing->final() === null))
        <div class="flex flex-wrap gap-2">
            @if ($standing->deposit() === null)
                <flux:button size="sm" icon="plus" wire:click="openInvoice('deposit')" data-test="record-deposit-invoice">{{ __('Record the deposit invoice') }}</flux:button>
            @endif
            @if ($standing->final() === null)
                <flux:button size="sm" icon="plus" wire:click="openInvoice('final')" data-test="record-final-invoice">{{ __('Record the final invoice') }}</flux:button>
            @endif
        </div>
    @endif

    @if ($mayRecord)
        <flux:modal name="project-invoice" class="w-full max-w-lg">
            <form wire:submit="saveInvoice" class="flex flex-col gap-5">
                <flux:error name="invoiceKind" />

                <div>
                    <flux:heading size="lg">{{ __(ProjectInvoice::KINDS[$invoiceKind] ?? 'Invoice') }}</flux:heading>
                    <flux:subheading>{{ __('Record it as QuickBooks sent it: its number and its total with tax. The amount suggested is before tax.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="invoiceNumber" maxlength="{{ ProjectInvoice::NUMBER_LENGTH }}" :label="__('Invoice number')" data-test="invoice-number" />
                    <flux:input wire:model="invoiceAmount" inputmode="decimal" :label="__('Total, with tax')" icon="currency-dollar" data-test="invoice-amount" />
                    <flux:input wire:model="invoiceSentOn" type="date" :label="__('Day sent')" data-test="invoice-sent-on" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-invoice">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="void-invoice"
            :title="__('Void this invoice?')"
            :description="__('It stays on record, crossed out, with why. Record it again if it was wrong.')"
            action="voidInvoice"
            :confirm-label="__('Void')"
            :error="['invoice', 'invoiceReason']"
        >
            <flux:textarea wire:model="invoiceReason" rows="2" :label="__('Why (required)')" data-test="invoice-reason" />
        </x-confirmation-modal>

        <flux:modal name="project-payment" class="w-full max-w-lg">
            <form wire:submit="savePayment" class="flex flex-col gap-5">
                <flux:error name="paymentInvoice" />

                <div>
                    <flux:heading size="lg">{{ __('Record a payment') }}</flux:heading>
                    <flux:subheading>{{ $paying === null ? '' : __('Toward :invoice #:number.', ['invoice' => mb_strtolower(__($paying->label())), 'number' => $paying->number]) }}</flux:subheading>
                </div>

                <flux:radio.group wire:model.live="paymentMethod" :label="__('How they paid')" data-test="payment-method">
                    @foreach (ProjectPayment::METHODS as $key => $label)
                        <flux:radio :value="$key" :label="__($label)" wire:key="payment-method-{{ $key }}" />
                    @endforeach
                </flux:radio.group>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="paymentAmount" inputmode="decimal" :label="__('Amount')" icon="currency-dollar" data-test="payment-amount" />
                    <flux:input wire:model="paymentReceivedOn" type="date" :label="__('Day received')" data-test="payment-received-on" />
                </div>

                <flux:input wire:model="paymentReference" maxlength="{{ ProjectPayment::REFERENCE_LENGTH }}" :label="in_array($paymentMethod, ProjectPayment::NEEDS_APPROVAL, true) ? __('Check number or note (optional)') : __('Reference from the processor or QuickBooks')" data-test="payment-reference" />

                @if (in_array($paymentMethod, ProjectPayment::NEEDS_APPROVAL, true))
                    <div class="flex flex-col gap-2">
                        <flux:input type="file" wire:model="paymentReceipt" accept=".pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/jpeg,image/png,image/heic" :label="__('Photo or scan of the receipt')" :description="__('Cash and check count once Finance approves them, after seeing this.')" data-test="payment-receipt-file" />
                        <flux:text size="sm" wire:loading wire:target="paymentReceipt">{{ __('Uploading...') }}</flux:text>
                        <flux:error name="paymentReceipt" />
                        <flux:error name="attachment" />
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="paymentReceipt" data-test="save-payment">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($mayApprove)
        <x-confirmation-modal
            name="approve-payment"
            :title="__('Approve this payment?')"
            :description="$chosenPayment === null ? '' : __(':amount in :method, received :day. Check the receipt first; it counts from now on.', ['amount' => $chosenPayment->amount(), 'method' => mb_strtolower(__($chosenPayment->methodLabel())), 'day' => $chosenPayment->received_on->format('M j, Y')])"
            action="approvePayment"
            :confirm-label="__('Approve')"
            confirm-variant="primary"
            :error="['payment']"
        />

        <x-confirmation-modal
            name="reverse-payment"
            :title="__('Reverse this payment?')"
            :description="__('It no longer counts toward what was paid. It stays on record, crossed out, with why.')"
            action="reversePayment"
            :confirm-label="__('Reverse')"
            :error="['payment', 'paymentReason']"
        >
            <flux:textarea wire:model="paymentReason" rows="2" :label="__('Why (required)')" :placeholder="__('Recorded twice, the card was declined, the check bounced...')" data-test="payment-reason" />
        </x-confirmation-modal>
    @endif
</div>
