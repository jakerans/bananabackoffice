<?php

use App\Actions\Projects\SendWorkOrder;
use App\Enums\ColorApprovalMethod;
use App\Models\ColorSpecificationLine;
use App\Models\Project;
use App\Models\WorkPackage;
use App\Models\WorkPackageWindow;
use App\Support\Money;
use App\Support\Projects\RoughTimeframe;
use App\Support\Projects\WorkOrder;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A Work Package's work order (docs/slice-13-giving-work-plan.md, step 13c;
 * product-architecture.md, 6.6 and 7.2; decision 151, questions 7 to 9): what
 * the subcontractor is to do, when, where, in which colors, and what they are
 * paid. It prints.
 *
 * It shows the payout, so only those who may see payouts open it. The job's
 * address is on it because the work has been given out (7.5). The Production
 * Manager or a manager records that it was sent; Backoffice sends nothing.
 */
new #[Title('Work order')] class extends Component {
    #[Locked]
    public int $projectId;

    #[Locked]
    public int $packageId;

    public string $workOrderMethod = '';

    /** The day it was sent, as "2026-10-14". */
    public string $workOrderSentOn = '';

    public function mount(Project $project, WorkPackage $workPackage): void
    {
        $this->authorize('viewPayouts', $project);

        $this->projectId = $project->id;
        $this->packageId = $workPackage->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with(['location', 'property', 'account', 'handoff', 'colorSpecifications.lines'])->findOrFail($this->projectId);
    }

    #[Computed]
    public function package(): WorkPackage
    {
        return $this->project->workPackages()->with(['currentAward.subcontractor.locations', 'currentAward.subcontractor.complianceRecords', 'workOrdersSent'])->findOrFail($this->packageId);
    }

    #[Computed]
    public function order(): ?WorkOrder
    {
        return WorkOrder::for($this->project, $this->package);
    }

    #[Computed]
    public function mayRecord(): bool
    {
        return Auth::user()->can('managePayouts', $this->project);
    }

    public function openSent(): void
    {
        $this->authorize('managePayouts', $this->project);

        $this->workOrderMethod = '';
        $this->workOrderSentOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('work-order-sent')->show();
    }

    public function saveSent(SendWorkOrder $sendWorkOrder): void
    {
        $this->authorize('managePayouts', $this->project);
        $this->resetValidation();

        $sendWorkOrder->handle($this->project, $this->packageId, $this->workOrderMethod, $this->workOrderSentOn, Auth::user());

        unset($this->package, $this->order);

        Flux::modal('work-order-sent')->close();
        Flux::toast(variant: 'success', text: __('Recorded as sent.'));
    }
}; ?>

<section class="mx-auto flex w-full max-w-3xl flex-col gap-6 print:max-w-none" data-test="work-order-page">
    @php
        $project = $this->project;
        $package = $this->package;
        $order = $this->order;
        $handoff = $project->handoff;
        $describe = fn (ColorSpecificationLine $line): string => implode(' · ', array_filter([$line->manufacturer, $line->color(), __($line->sheen->label()), $line->product]));
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <flux:link :href="route('projects.show', $project)" wire:navigate class="text-sm">&larr; {{ $project->title }}</flux:link>

        @if ($order !== null)
            <div class="flex flex-wrap gap-2">
                <flux:button icon="printer" onclick="window.print()" data-test="print-work-order">{{ __('Print') }}</flux:button>
                @if ($this->mayRecord)
                    <flux:button variant="primary" wire:click="openSent" :disabled="! $order->canBeSent()" data-test="record-work-order-sent">{{ __('Work order sent') }}</flux:button>
                @endif
            </div>
        @endif
    </div>

    @if ($order === null)
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="work-order-none">
            <flux:text>{{ __('This work is not given to a subcontractor, so it has no work order.') }}</flux:text>
        </div>
    @else
        {{-- Whether it can go out, and whether it has (on screen only). --}}
        <div class="print:hidden">
            @unless ($order->canBeSent())
                <flux:callout icon="exclamation-triangle" color="amber" data-test="work-order-missing">
                    <flux:callout.text>{{ __('It cannot be sent yet. It still needs :things.', ['things' => implode(', ', array_map(fn (string $thing): string => __($thing), $order->missing()))]) }}</flux:callout.text>
                </flux:callout>
            @endunless

            @if ($order->lastSent !== null)
                <flux:text class="mt-2 text-sm" data-test="work-order-last-sent">
                    {{ __('Sent :how on :day by :name.', ['how' => mb_strtolower(__($order->lastSent->method->label())), 'day' => $order->lastSent->sent_on->format('M j, Y'), 'name' => $order->lastSent->sent_by_name ?? __('Unknown')]) }}
                    @if ($order->changedSinceSent())
                        <span class="font-medium text-amber-700" data-test="work-order-changed">{{ __('It has changed since it was sent: send it again.') }}</span>
                    @endif
                </flux:text>
            @else
                <flux:text class="mt-2 text-sm" data-test="work-order-not-sent">{{ __('Not sent yet.') }}</flux:text>
            @endif
        </div>

        <article class="flex flex-col gap-6 rounded-xl border border-zinc-200 bg-white p-6 print:border-0 print:p-0" data-test="work-order">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 pb-4">
                <div>
                    <div class="text-sm font-medium uppercase tracking-wide text-zinc-500">{{ __('Banana Painting · Work order') }}</div>
                    <h1 class="font-nav text-2xl font-bold text-ink" data-test="work-order-title">{{ $package->name }}</h1>
                    <div class="text-sm text-zinc-700">{{ $project->title }}</div>
                </div>
                <div class="text-end text-sm text-zinc-700">
                    <div class="font-semibold text-ink">{{ $order->award->subcontractor->business_name }}</div>
                    <div>{{ $order->award->subcontractor->contact_name }}</div>
                    @if ($order->award->subcontractor->phoneForDisplay() !== null)
                        <div>{{ $order->award->subcontractor->phoneForDisplay() }}</div>
                    @endif
                </div>
            </header>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div data-test="work-order-where">
                    <dt class="text-sm text-zinc-500">{{ __('Where') }}</dt>
                    <dd class="text-ink">{{ $project->property->address }}</dd>
                    @if ($handoff !== null)
                        <dd class="mt-1 whitespace-pre-line text-sm text-zinc-700">{{ __('Access: :access', ['access' => $handoff->access]) }}</dd>
                    @endif
                </div>
                <div data-test="work-order-when">
                    <dt class="text-sm text-zinc-500">{{ __('When') }}</dt>
                    <dd class="text-ink">{{ WorkPackageWindow::describe($order->firstDay, $order->lastDay) }}</dd>
                    <dd class="text-sm text-zinc-700">
                        {{ trans_choice(':count working day|:count working days', $package->working_days) }} &middot; {{ trans_choice('smallest crew: :count person|smallest crew: :count people', $package->minimum_crew) }}
                    </dd>
                </div>
            </dl>

            <div data-test="work-order-scope">
                <h2 class="text-sm font-medium text-zinc-500">{{ __('The work') }}</h2>
                <p class="whitespace-pre-line text-ink">{{ $package->scope }}</p>
                @if ($handoff !== null && trim($handoff->exclusions) !== '' && mb_strtolower(trim($handoff->exclusions)) !== 'none')
                    <p class="mt-2 whitespace-pre-line text-sm text-zinc-700">{{ __('Not included: :exclusions', ['exclusions' => $handoff->exclusions]) }}</p>
                @endif
            </div>

            <div data-test="work-order-colors">
                <h2 class="text-sm font-medium text-zinc-500">{{ __('Colors') }}</h2>
                @if ($order->colors === null)
                    <p class="text-amber-700">{{ __('The colors are not approved by the customer yet.') }}</p>
                @else
                    <p class="text-sm text-zinc-500">{{ __('Version :version, approved by the customer on :day.', ['version' => $order->colors->version, 'day' => $order->colors->approved_on?->format('M j, Y')]) }}</p>
                    <ul class="mt-1 flex flex-col gap-1">
                        @foreach ($order->colors->lines as $line)
                            <li wire:key="work-order-color-{{ $line->id }}" class="text-ink"><span class="font-medium">{{ $line->area }} &middot; {{ $line->surface }}:</span> {{ $describe($line) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="border-t border-zinc-200 pt-4" data-test="work-order-pay">
                <h2 class="text-sm font-medium text-zinc-500">{{ __('Pay') }}</h2>
                <dl class="mt-1 flex flex-col gap-1 tabular-nums">
                    <div class="flex justify-between gap-3"><dt>{{ __('Payout') }}</dt><dd class="font-semibold text-ink">{{ Money::format($order->payoutCents) }}</dd></div>
                    @if ($order->hasDeduction())
                        <div class="flex justify-between gap-3 text-zinc-700" data-test="work-order-deduction">
                            <dt>{{ __('Insurance deduction: Banana\'s coverage, :rate', ['rate' => $order->rate->percent()]) }}</dt>
                            <dd>-{{ Money::format($order->deductionCents) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 border-t border-zinc-200 pt-1"><dt>{{ __('Paid after the deduction') }}</dt><dd class="font-semibold text-ink">{{ Money::format($order->payoutAfterDeduction()) }}</dd></div>
                    @endif
                </dl>
            </div>
        </article>

        @if ($this->mayRecord)
            <flux:modal name="work-order-sent" class="w-full max-w-md">
                <form wire:submit="saveSent" class="flex flex-col gap-5">
                    <flux:error name="workOrder" />

                    <div>
                        <flux:heading size="lg">{{ __('Work order sent') }}</flux:heading>
                        <flux:subheading>{{ __('Record how and when it went to :name. Backoffice sends nothing.', ['name' => $order->award->subcontractor->business_name]) }}</flux:subheading>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="workOrderMethod" :label="__('How')" data-test="work-order-method">
                            <flux:select.option value="">{{ __('Choose how it was sent') }}</flux:select.option>
                            @foreach (ColorApprovalMethod::cases() as $method)
                                <flux:select.option :value="$method->value" wire:key="work-order-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="workOrderSentOn" type="date" :label="__('Day sent')" data-test="work-order-sent-on" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-work-order-sent">{{ __('Record') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    @endif
</section>
