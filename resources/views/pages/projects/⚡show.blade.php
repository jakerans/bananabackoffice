<?php

use App\Actions\Projects\EditProject;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Permission;
use App\Models\ProjectHandoff;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectAmbassadorObligation;
use App\Models\WorkPackageWindow;
use App\Support\Customers\CustomerActivity;
use App\Support\Money;
use App\Support\Projects\Payments;
use App\Support\Projects\Readiness;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A Project's page (docs/slice-8-projects-plan.md, step 8a): the sold job,
 * what was sold and for how much, who it is for and where, and what has
 * happened to it.
 *
 * Anyone who may see Projects at its Location sees it. A Production Manager
 * cannot open Leads or the customer's page, so what Production needs from
 * them is shown here. A manager there can change the title and description;
 * nothing else about a Project is typed.
 */
new #[Title('Project')] class extends Component {
    /** The audit events shown as the Project's Activity. */
    private const array ACTIVITY = [
        AuditAction::ProjectCreated, AuditAction::ProjectBroughtBack, AuditAction::ProjectCancelled, AuditAction::ProjectEdited,
        AuditAction::ProjectHandoffCompleted, AuditAction::ProjectHandoffChanged,
        AuditAction::ProjectProductionManagerAssigned, AuditAction::ProjectProductionManagerChanged, AuditAction::ProjectTaken,
        AuditAction::ProjectWelcomeCallRecorded, AuditAction::ProjectWelcomeCallUndone, AuditAction::ProjectTimeframeSet, AuditAction::ProjectTimeframeChanged,
        AuditAction::ProjectColorsStarted, AuditAction::ProjectColorsNewVersion, AuditAction::ProjectColorsSentForApproval, AuditAction::ProjectColorsApproved,
        AuditAction::ProjectWindowProposed, AuditAction::ProjectWindowWithdrawn, AuditAction::ProjectScheduleConfirmed, AuditAction::ProjectRescheduled,
        AuditAction::ProjectWorkAwarded, AuditAction::ProjectAwardEnded, AuditAction::ProjectAwardAcceptedAgain, AuditAction::ProjectWorkOrderSent,
        AuditAction::ProjectWorkStarted, AuditAction::ProjectWorkFinished, AuditAction::ProjectWorkExtended,
        AuditAction::ProjectInspected, AuditAction::ProjectWalkthroughDone, AuditAction::ProjectSignedOff, AuditAction::ProjectNotSatisfied,
        AuditAction::AttachmentAdded, AuditAction::AttachmentRemoved, AuditAction::AttachmentPurged,
        AuditAction::ProjectInvoiceRecorded, AuditAction::ProjectInvoiceVoided,
        AuditAction::ProjectPaymentRecorded, AuditAction::ProjectPaymentApproved, AuditAction::ProjectPaymentReversed,
        AuditAction::ProjectDepositChanged, AuditAction::ProjectCollectionContactRecorded,
        AuditAction::ProjectChangeOrderDrafted, AuditAction::ProjectChangeOrderSigned, AuditAction::ProjectChangeOrderWithdrawn,
        AuditAction::ProjectWorkRevised,
        AuditAction::ProjectJobCostingSubmitted, AuditAction::ProjectJobCostingApproved, AuditAction::ProjectFinanciallyClosed, AuditAction::ProjectFinancialCloseReopened,
        AuditAction::ProjectAmbassadorPackageRecorded, AuditAction::ProjectAmbassadorObligationHandled,
        AuditAction::WarrantyCaseOpened, AuditAction::WarrantyCaseAssigned, AuditAction::WarrantyCaseClosed,
        AuditAction::WarrantyBudgetApproved, AuditAction::WarrantyVisitScheduled, AuditAction::WarrantyCaseCompleted,
        AuditAction::WarrantyCostRecorded, AuditAction::WarrantyCostVoided,
    ];

    /**
     * A change order's price change, as the activity says it: "+$1,200", "-$300".
     */
    private static function priceChange(int $cents): string
    {
        return $cents === 0 ? __('no price change') : ($cents > 0 ? '+' : '').Money::format($cents);
    }

    #[Locked]
    public int $projectId;

    public string $projectTitle = '';

    public string $projectDescription = '';

    /** The day the welcome call was made, as "2026-10-14". */
    public string $welcomeCallOn = '';

    public string $welcomeCallNote = '';

    public string $welcomeCallUndoNote = '';

    /** The rough timeframe being set, each a day as "2026-11-24". */
    public string $timeframeEarliest = '';

    public string $timeframeLatest = '';

    public string $timeframeNote = '';

    public string $timeframeReason = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with(['location', 'account', 'contact', 'property', 'services', 'proposal', 'lead', 'handoff', 'timeframes'])->findOrFail($this->projectId);
    }

    /**
     * The appointments the proposal was presented at, earliest first.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function appointments(): Collection
    {
        return $this->project->proposal->appointments()->get();
    }

    /**
     * What has happened to the Project, newest first.
     *
     * @return Collection<int, AuditEvent>
     */
    #[Computed]
    public function activity(): Collection
    {
        return AuditEvent::query()
            ->where('subject_type', $this->project->getMorphClass())
            ->where('subject_id', $this->project->id)
            ->whereIn('action', self::ACTIVITY)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function activityLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            AuditAction::ProjectEdited => __('Title or description changed'),
            AuditAction::ProjectHandoffChanged => __('Handoff answers changed'),
            AuditAction::ProjectWindowProposed, AuditAction::ProjectWindowWithdrawn, AuditAction::ProjectScheduleConfirmed, AuditAction::ProjectRescheduled => $this->windowLabel($event),
            AuditAction::ProjectWorkAwarded, AuditAction::ProjectAwardAcceptedAgain, AuditAction::ProjectWorkOrderSent => __(':what: :package to :subcontractor', [
                'what' => CustomerActivity::label($event->action),
                'package' => (string) ($event->values_after['package'] ?? ''),
                'subcontractor' => (string) ($event->values_after['subcontractor'] ?? ''),
            ]),
            AuditAction::ProjectWorkStarted, AuditAction::ProjectWorkFinished, AuditAction::ProjectWorkExtended => __(':what: :package, :day', [
                'what' => CustomerActivity::label($event->action),
                'package' => (string) ($event->values_after['package'] ?? ''),
                'day' => is_string($event->values_after['day'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $event->values_after['day'])->format('M j, Y') : '',
            ]),
            AuditAction::ProjectInvoiceRecorded => __(':invoice recorded: #:number, :amount', ['invoice' => (string) ($event->values_after['invoice'] ?? ''), 'number' => (string) ($event->values_after['number'] ?? ''), 'amount' => Money::format((int) ($event->values_after['amount_cents'] ?? 0))]),
            AuditAction::ProjectInvoiceVoided => __(':invoice voided: #:number, :amount', ['invoice' => (string) ($event->values_before['invoice'] ?? ''), 'number' => (string) ($event->values_before['number'] ?? ''), 'amount' => Money::format((int) ($event->values_before['amount_cents'] ?? 0))]),
            AuditAction::ProjectPaymentRecorded => (($event->values_after['awaiting_approval'] ?? false) === true
                ? __('Payment recorded, waiting for Finance\'s approval: :amount by :method toward the :invoice', ['amount' => Money::format((int) ($event->values_after['amount_cents'] ?? 0)), 'method' => mb_strtolower((string) ($event->values_after['method'] ?? '')), 'invoice' => mb_strtolower((string) ($event->values_after['invoice'] ?? ''))])
                : __('Payment recorded: :amount by :method toward the :invoice', ['amount' => Money::format((int) ($event->values_after['amount_cents'] ?? 0)), 'method' => mb_strtolower((string) ($event->values_after['method'] ?? '')), 'invoice' => mb_strtolower((string) ($event->values_after['invoice'] ?? ''))])),
            AuditAction::ProjectPaymentApproved => __('Payment approved: :amount by :method', ['amount' => Money::format((int) ($event->values_after['amount_cents'] ?? 0)), 'method' => mb_strtolower((string) ($event->values_after['method'] ?? ''))]),
            AuditAction::ProjectCollectionContactRecorded => __('The customer was contacted about the balance'),
            AuditAction::ProjectChangeOrderDrafted => __('Change order :number drafted: :price', ['number' => (string) ($event->values_after['number'] ?? ''), 'price' => self::priceChange((int) ($event->values_after['price_change_cents'] ?? 0))]),
            AuditAction::ProjectChangeOrderSigned => __('Change order :number signed by :person: :price', ['number' => (string) ($event->values_after['number'] ?? ''), 'person' => (string) ($event->values_after['signed_by'] ?? ''), 'price' => self::priceChange((int) ($event->values_after['price_change_cents'] ?? 0))]),
            AuditAction::ProjectJobCostingSubmitted => __('Job costing submitted'),
            AuditAction::ProjectJobCostingApproved => __('Final gross margin approved by Finance'),
            AuditAction::ProjectFinanciallyClosed => __('Financially closed'),
            AuditAction::ProjectFinancialCloseReopened => __('Job costing reopened by Finance'),
            AuditAction::ProjectAmbassadorPackageRecorded => __('Brand Ambassador package: :package', ['package' => __((string) ($event->values_after['package'] ?? ''))]),
            AuditAction::WarrantyCaseOpened => __('Warranty case opened: :what', ['what' => (string) ($event->values_after['description'] ?? '')]),
            AuditAction::WarrantyCaseAssigned => __('Warranty case given to :name', ['name' => (string) ($event->values_after['owner'] ?? '')]),
            AuditAction::WarrantyCaseClosed => __('Warranty case closed without work'),
            AuditAction::WarrantyBudgetApproved => __('Warranty budget approved: :amount', ['amount' => Money::format((int) ($event->values_after['budget_cents'] ?? 0))]),
            AuditAction::WarrantyVisitScheduled => __('Warranty visit scheduled: :day', ['day' => (string) ($event->values_after['visit_on'] ?? '')]),
            AuditAction::WarrantyCaseCompleted => __('Warranty case completed'),
            AuditAction::WarrantyCostRecorded => __('Warranty cost recorded'),
            AuditAction::WarrantyCostVoided => __('Warranty cost voided'),
            AuditAction::ProjectAmbassadorObligationHandled => __('Brand Ambassador: :item, :state', [
                'item' => __((string) ($event->values_after['item'] ?? '')),
                'state' => mb_strtolower(__(ProjectAmbassadorObligation::STATES[(string) ($event->values_after['state'] ?? '')] ?? '')),
            ]),
            AuditAction::ProjectWorkRevised => ($event->values_after['change_order'] ?? null) === null
                ? __(':package revised: :what', ['package' => (string) ($event->values_after['package'] ?? ''), 'what' => ($event->values_after['kind'] ?? '') === 'scope' ? __('the scope') : __('the payout')])
                : __(':package revised: :what, from change order :number', ['package' => (string) ($event->values_after['package'] ?? ''), 'what' => ($event->values_after['kind'] ?? '') === 'scope' ? __('the scope') : __('the payout'), 'number' => (string) $event->values_after['change_order']]),
            AuditAction::ProjectChangeOrderWithdrawn => __('Change order :number withdrawn', ['number' => (string) ($event->values_before['number'] ?? '')]),
            AuditAction::ProjectDepositChanged => ((int) ($event->values_after['required_cents'] ?? 0)) === 0
                ? __('Deposit waived')
                : __('Deposit lowered to :amount', ['amount' => Money::format((int) ($event->values_after['required_cents'] ?? 0))]),
            AuditAction::ProjectPaymentReversed => __('Payment reversed: :amount by :method', ['amount' => Money::format((int) ($event->values_before['amount_cents'] ?? 0)), 'method' => mb_strtolower((string) ($event->values_before['method'] ?? ''))]),
            AuditAction::AttachmentAdded => __('Photo added: :what (:name)', ['what' => (string) ($event->values_after['for'] ?? ''), 'name' => (string) ($event->values_after['name'] ?? '')]),
            AuditAction::AttachmentRemoved => __('Photo removed: :what (:name)', ['what' => (string) ($event->values_before['for'] ?? ''), 'name' => (string) ($event->values_before['name'] ?? '')]),
            AuditAction::AttachmentPurged => __('Photo deleted from storage, 30 days after it was removed: :what (:name)', ['what' => (string) ($event->values_before['for'] ?? ''), 'name' => (string) ($event->values_before['name'] ?? '')]),
            AuditAction::ProjectAwardEnded => __(':what: :package from :subcontractor', [
                'what' => CustomerActivity::label($event->action),
                'package' => (string) ($event->values_before['package'] ?? ''),
                'subcontractor' => (string) ($event->values_before['subcontractor'] ?? ''),
            ]),
            default => CustomerActivity::label($event->action),
        };
    }

    /**
     * Dates offered, withdrawn, confirmed or changed, with which work and
     * which days. A change names the new days.
     */
    private function windowLabel(AuditEvent $event): string
    {
        $window = $event->action === AuditAction::ProjectWindowWithdrawn ? $event->values_before : $event->values_after;
        $first = is_string($window['first_day'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $window['first_day']) : null;
        $last = is_string($window['last_day'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $window['last_day']) : null;

        if (! $first instanceof CarbonImmutable || ! $last instanceof CarbonImmutable) {
            return CustomerActivity::label($event->action);
        }

        return __(':what: :package, :days', [
            'what' => CustomerActivity::label($event->action),
            'package' => is_string($window['package'] ?? null) ? $window['package'] : __('Unknown'),
            'days' => WorkPackageWindow::describe($first, $last),
        ]);
    }

    /**
     * Take the Project as its Production Manager.
     */
    public function take(TakeProject $takeProject): void
    {
        $this->authorize('take', $this->project);
        $this->resetValidation();

        $takeProject->handle($this->project, Auth::user());

        unset($this->project, $this->activity);

        Flux::toast(variant: 'success', text: __('It is yours. This project is now in pre-production.'));
    }

    /**
     * The Colors part changed the Project: read it and its Activity again.
     */
    #[On('project-colors-changed')]
    public function colorsChanged(): void
    {
        unset($this->project, $this->activity);
    }

    /**
     * The Completion part changed the Project (slice 14, step 14b): read it
     * and its Activity again.
     */
    #[On('project-completion-changed')]
    public function completionChanged(): void
    {
        unset($this->project, $this->activity);
    }

    /**
     * The job costing changed (slice 19): the financial close and the
     * activity say so.
     */
    #[On('project-job-costing-changed')]
    public function jobCostingChanged(): void
    {
        unset($this->project, $this->activity);
    }

    /**
     * An invoice or payment was recorded or changed (slice 17): the payment
     * state and the activity say so.
     */
    #[On('project-payments-changed')]
    public function paymentsChanged(): void
    {
        unset($this->project, $this->activity);
    }

    /**
     * A photo of the work was added or removed (slice 16, step 16c): the
     * activity says so.
     */
    #[On('project-photos-changed')]
    public function photosChanged(): void
    {
        unset($this->activity);
    }

    /**
     * The Work and schedule part changed the Project: read its Activity again.
     */
    #[On('project-work-changed')]
    public function workChanged(): void
    {
        unset($this->project, $this->activity);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-production (slice 10, step 10a)
    |--------------------------------------------------------------------------
    */

    public function openWelcomeCall(): void
    {
        $this->authorize('managePreProduction', $this->project);

        // Today, in the Project's own Location, unless it is already ticked.
        $this->welcomeCallOn = $this->project->welcome_call_on?->toDateString() ?? RoughTimeframe::today($this->project->location)->toDateString();
        $this->welcomeCallNote = (string) $this->project->welcome_call_note;
        $this->resetValidation();

        Flux::modal('welcome-call')->show();
    }

    /**
     * Tick "Welcome call made". It is a checklist item (decision 141).
     */
    public function saveWelcomeCall(RecordWelcomeCall $recordWelcomeCall): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $recordWelcomeCall->handle($this->project, $this->welcomeCallOn, $this->welcomeCallNote, Auth::user());

        unset($this->project, $this->activity);
        $this->dispatch('project-readiness-changed');

        Flux::modal('welcome-call')->close();
        Flux::toast(variant: 'success', text: __('Welcome call ticked.'));
    }

    public function openUndoWelcomeCall(): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->welcomeCallUndoNote = '';
        $this->resetValidation();

        Flux::modal('undo-welcome-call')->show();
    }

    public function undoWelcomeCall(RecordWelcomeCall $recordWelcomeCall): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $recordWelcomeCall->undo($this->project, $this->welcomeCallUndoNote, Auth::user());

        unset($this->project, $this->activity);
        $this->dispatch('project-readiness-changed');

        Flux::modal('undo-welcome-call')->close();
        Flux::toast(variant: 'success', text: __('Unticked. This project is waiting for its welcome call again.'));
    }

    public function openTimeframe(): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->timeframeEarliest = (string) $this->project->timeframe_earliest?->toDateString();
        $this->timeframeLatest = (string) $this->project->timeframe_latest?->toDateString();
        $this->timeframeNote = (string) $this->project->timeframe_note;
        $this->timeframeReason = '';
        $this->resetValidation();

        Flux::modal('project-timeframe')->show();
    }

    /**
     * Set or change the rough timeframe. The action keeps what it was.
     */
    public function saveTimeframe(SetProjectTimeframe $setProjectTimeframe): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $setProjectTimeframe->handle($this->project, $this->timeframeEarliest, $this->timeframeLatest, $this->timeframeNote, $this->timeframeReason, Auth::user());

        unset($this->project, $this->activity);
        $this->dispatch('project-readiness-changed');

        Flux::modal('project-timeframe')->close();
        Flux::toast(variant: 'success', text: __('Rough timeframe saved.'));
    }

    public function openEdit(): void
    {
        $this->authorize('update', $this->project);

        $this->projectTitle = $this->project->title;
        $this->projectDescription = (string) $this->project->description;
        $this->resetValidation();

        Flux::modal('edit-project')->show();
    }

    /**
     * Save the title and description. The action checks again and keeps what
     * they were.
     */
    public function saveEdit(EditProject $editProject): void
    {
        $this->authorize('update', $this->project);
        $this->resetValidation();

        $editProject->handle($this->project, $this->projectTitle, $this->projectDescription, Auth::user());

        unset($this->project, $this->activity);

        Flux::modal('edit-project')->close();
        Flux::toast(variant: 'success', text: __('Project saved.'));
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="project-page">
    @php
        $project = $this->project;
        $proposal = $project->proposal;
        $when = fn (Appointment $appointment): string => $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j, Y \a\t g:i A T');
    @endphp

    @if (Auth::user()->hasPermissionAt(Permission::ProductionAccess, $project->location))
        <div>
            <flux:link :href="route('production.workspace', ['location' => $project->location_id])" wire:navigate class="text-sm" data-test="project-workspace-link">&larr; {{ __('Workspace') }}</flux:link>
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1" data-test="project-title">{{ $project->title }}</flux:heading>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Each state is its own, never one status for the whole Project. The word carries the meaning; the color only reinforces it. --}}
                @if ($project->isCancelled())
                    <flux:badge size="sm" color="zinc" data-test="project-sales-state">{{ __('Cancelled: sale corrected') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="emerald" data-test="project-sales-state">{{ __('Sold') }}</flux:badge>
                    <flux:badge size="sm" color="amber" data-test="project-production-state">{{ __('Production: :state', ['state' => $project->production_state->label()]) }}</flux:badge>
                    {{-- Colors are a state of their own, beside the production state (product-architecture.md, 2). --}}
                    @if ($project->production_manager_id !== null)
                        <flux:badge size="sm" :color="$project->color_readiness->color()" data-test="project-color-readiness">{{ __('Colors: :state', ['state' => mb_strtolower(__($project->color_readiness->label()))]) }}</flux:badge>
                    @endif
                    {{-- Financially closed (slice 19, step 19c): the last step of a job. --}}
                    @if (\App\Support\Projects\JobCosting::for($project)->state() === \App\Support\Projects\JobCosting::CLOSED)
                        <flux:badge size="sm" color="emerald" data-test="project-financially-closed">{{ __('Financially closed') }}</flux:badge>
                    @endif
                    {{-- The customer's payments are a state of their own too (slice 17). --}}
                    <flux:badge size="sm" color="zinc" data-test="project-payment-state">{{ __('Payment: :state', ['state' => mb_strtolower(__(Payments::for($project)->stateLabel()))]) }}</flux:badge>
                @endif

                <span class="text-sm text-zinc-600">{{ $project->location->name }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('take', $project)
                <flux:button variant="primary" wire:click="take" data-test="take-project">{{ __('Take this project') }}</flux:button>
            @endcan

            @can('update', $project)
                <flux:button icon="pencil-square" wire:click="openEdit" data-test="edit-project">{{ __('Edit title') }}</flux:button>
            @endcan
        </div>
    </div>

    <flux:error name="productionManagerId" />

    @if ($project->isCancelled())
        <flux:callout icon="x-circle" data-test="project-cancelled">
            <flux:callout.heading>{{ __('This project was cancelled because its sale was corrected.') }}</flux:callout.heading>
            <flux:callout.text>
                <span class="block whitespace-pre-line">&ldquo;{{ $project->cancellation_note }}&rdquo;</span>
                <span class="mt-1 block">
                    {{ $project->cancelled_by_name ?? __('Unknown') }}@if ($project->cancelled_at !== null) &middot; <x-local-time :at="$project->cancelled_at" />@endif
                </span>
                <span class="mt-1 block">{{ __('It is kept on the record. If the proposal is sold again, this project comes back.') }}</span>
            </flux:callout.text>
        </flux:callout>
    @endif

    @unless ($project->isCancelled())
        {{-- Ready to schedule? Worked out from what is recorded; nobody ticks it (slice 10, step 10c). --}}
        @php
            $readiness = Readiness::for($project);
        @endphp

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-readiness">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Ready to schedule?') }}</flux:heading>
                    <flux:subheading>{{ __('Each line is ticked by what is recorded on this job, never by hand.') }}</flux:subheading>
                </div>

                @if ($readiness->isReadyToSchedule())
                    <flux:badge color="emerald" data-test="readiness-state">{{ __('Ready to schedule') }}</flux:badge>
                @else
                    <flux:badge color="amber" data-test="readiness-state">{{ __('Getting ready: :done of :all', ['done' => $readiness->done(), 'all' => count($readiness->items)]) }}</flux:badge>
                @endif
            </div>

            <ul class="flex flex-col gap-2">
                @foreach ($readiness->items as $item)
                    <li wire:key="readiness-{{ $item['key'] }}" class="flex items-start gap-2 {{ $item['done'] ? 'text-ink' : 'text-zinc-600' }}" data-test="readiness-item" data-done="{{ $item['done'] ? 'yes' : 'no' }}">
                        @if ($item['done'])
                            <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0 text-emerald-600" aria-hidden="true" />
                        @else
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center" aria-hidden="true"><span class="size-4 rounded-full border-2 border-zinc-300"></span></span>
                        @endif
                        <span><span class="sr-only">{{ $item['done'] ? __('Done:') : __('Still needed:') }}</span> {{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ul>

            @unless ($readiness->isReadyToSchedule())
                <flux:text data-test="readiness-missing">{{ __('Still needs: :things.', ['things' => implode(', ', $readiness->missing())]) }}</flux:text>
            @endunless

            {{-- The colors are shown beside the four and are not one of them (decision 141, question 8). --}}
            <div class="flex items-start gap-2 border-t border-zinc-200 pt-4 {{ $readiness->colorsApproved() ? 'text-ink' : 'text-zinc-600' }}" data-test="readiness-colors" data-done="{{ $readiness->colorsApproved() ? 'yes' : 'no' }}">
                @if ($readiness->colorsApproved())
                    <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0 text-emerald-600" aria-hidden="true" />
                @else
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center" aria-hidden="true"><span class="size-4 rounded-full border-2 border-zinc-300"></span></span>
                @endif
                <span class="flex flex-col">
                    <span>{{ $readiness->colorsApproved() ? __('Colors approved by the customer') : __('Colors not approved yet: :state', ['state' => mb_strtolower(__($readiness->colors->label()))]) }}</span>
                    <span class="text-sm text-zinc-500">{{ __('Not needed to schedule the job.') }}</span>
                </span>
            </div>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-handoff">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Handoff from Sales') }}</flux:heading>
                    <flux:subheading data-test="project-production-manager">
                        {{ $project->production_manager_name === null
                            ? __('No Production Manager yet.')
                            : __('Production Manager: :name', ['name' => $project->production_manager_name]) }}
                    </flux:subheading>
                </div>

                @can('completeHandoff', $project)
                    <flux:button :variant="$project->handoff === null ? 'primary' : 'ghost'" :href="route('projects.handoff', $project)" wire:navigate data-test="project-handoff-link">
                        {{ $project->handoff === null ? __('Complete the handoff') : __('Change the answers') }}
                    </flux:button>
                @endcan
            </div>

            @if ($project->handoff === null)
                <flux:callout icon="clock" data-test="project-handoff-waiting">
                    <flux:callout.heading>{{ __('Waiting for the salesperson\'s handoff') }}</flux:callout.heading>
                    <flux:callout.text>{{ __(':name has not answered the handoff questions yet. Production cannot take this project until they do.', ['name' => $project->salesperson_name ?? __('The salesperson')]) }}</flux:callout.text>
                </flux:callout>
            @else
                @php
                    $handoff = $project->handoff;
                    $answers = [
                        __('What is included') => $handoff->included_scope,
                        __('What is not included') => $handoff->exclusions,
                        __('Promised beyond the proposal') => $handoff->promises,
                        __('When the customer expects the work') => $handoff->timing,
                        __('Access') => $handoff->access,
                        __('Colors') => __($handoff->colors_status->label()).($handoff->colors_details === null ? '' : ': '.$handoff->colors_details),
                        __('Condition and prep') => $handoff->condition_prep,
                        __('What matters most to the customer') => $handoff->customer_priorities,
                        __('Anything else') => $handoff->other_notes,
                    ];
                @endphp

                <dl class="grid gap-4 lg:grid-cols-2" data-test="project-handoff-answers">
                    @foreach ($answers as $question => $answer)
                        @if ($answer !== null)
                            <div wire:key="handoff-answer-{{ $loop->index }}">
                                <dt class="text-sm text-zinc-500">{{ $question }}</dt>
                                <dd class="whitespace-pre-line text-ink">{{ $answer }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                <flux:text class="text-sm">
                    {{ __('Completed by :name', ['name' => $handoff->completed_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$handoff->completed_at" />@if ($handoff->updated_by_name !== null) &middot; {{ __('last changed by :name', ['name' => $handoff->updated_by_name]) }}@endif
                </flux:text>
            @endif
        </div>

        {{-- Pre-production: what the job still needs once a Production Manager has it (slice 10, step 10a). --}}
        @if ($project->production_manager_id !== null)
            @php
                $timeframe = RoughTimeframe::of($project);
                $callDueAt = RoughTimeframe::welcomeCallDueAt($project);
                $callIsBehind = RoughTimeframe::welcomeCallIsBehind($project);
            @endphp

            <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-pre-production">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Pre-production') }}</flux:heading>
                    <flux:subheading>{{ __('What this job has and still needs before it is scheduled.') }}</flux:subheading>
                </div>

                <div class="flex items-start justify-between gap-3" data-test="project-welcome-call">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="text-sm font-medium text-zinc-500">{{ __('Welcome call') }}</div>

                        @if ($project->welcome_call_on !== null)
                            <div class="text-ink" data-test="welcome-call-made">{{ __('Made on :day', ['day' => $project->welcome_call_on->format('D, M j, Y')]) }}</div>
                            @if ($project->welcome_call_note !== null)
                                <div class="whitespace-pre-line text-sm text-zinc-700">&ldquo;{{ $project->welcome_call_note }}&rdquo;</div>
                            @endif
                            <div class="text-sm text-zinc-500">
                                {{ __('Ticked by') }} {{ $project->welcome_call_by_name ?? __('Unknown') }}@if ($project->welcome_call_recorded_at !== null) &middot; <x-local-time :at="$project->welcome_call_recorded_at" />@endif
                            </div>
                        @else
                            <div class="flex flex-wrap items-center gap-2 text-ink" data-test="welcome-call-waiting">
                                {{ __('Customer not yet called.') }}
                                @if ($callIsBehind)
                                    <flux:badge size="sm" color="rose" data-test="welcome-call-behind">{{ __('Falling behind') }}</flux:badge>
                                @endif
                            </div>
                            @if ($callDueAt !== null)
                                <div class="text-sm text-zinc-500" data-test="welcome-call-due">
                                    @if ($project->deposit_paid_at !== null)
                                        {{-- Once the deposit is paid, the call is due within 24 hours (slice 17, step 17b). --}}
                                        {{ $callIsBehind ? __('It was due within 24 hours of the deposit being paid, by') : __('The deposit is paid: call within 24 hours, by') }} <x-local-time :at="$callDueAt" />
                                    @else
                                        {{ $callIsBehind ? __('It was due within two business days of the job being taken, by') : __('Due within two business days of the job being taken, by') }} <x-local-time :at="$callDueAt" />
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>

                    @can('managePreProduction', $project)
                        @if ($project->welcome_call_on === null)
                            <flux:button variant="primary" class="shrink-0" wire:click="openWelcomeCall" data-test="tick-welcome-call">{{ __('Welcome call made') }}</flux:button>
                        @else
                            <flux:button size="sm" variant="ghost" class="shrink-0" wire:click="openUndoWelcomeCall" data-test="untick-welcome-call">{{ __('Untick') }}</flux:button>
                        @endif
                    @endcan
                </div>

                <div class="flex items-start justify-between gap-3 border-t border-zinc-200 pt-5" data-test="project-timeframe">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="text-sm font-medium text-zinc-500">{{ __('Rough timeframe') }}</div>

                        @if ($timeframe !== null)
                            <div class="font-nav text-xl font-bold text-ink" data-test="timeframe-words">{{ $timeframe }}</div>
                            <div class="text-sm text-zinc-700" data-test="timeframe-days">
                                {{ __('Work expected to start between :from and :to. Not a booked date.', ['from' => $project->timeframe_earliest->format('M j, Y'), 'to' => $project->timeframe_latest->format('M j, Y')]) }}
                            </div>
                            @if ($project->timeframe_note !== null)
                                <div class="whitespace-pre-line text-sm text-zinc-700">&ldquo;{{ $project->timeframe_note }}&rdquo;</div>
                            @endif
                        @else
                            <div class="text-ink" data-test="timeframe-none">{{ __('No rough timeframe yet.') }}</div>
                        @endif

                        {{-- What it was before each change is kept. --}}
                        @if ($project->timeframes->count() > 1)
                            <ul class="mt-1 flex flex-col gap-1 text-sm text-zinc-500" data-test="timeframe-history">
                                @foreach ($project->timeframes as $index => $kept)
                                    <li wire:key="timeframe-{{ $kept->id }}">
                                        {{ $index === 0 ? __('Now') : __('Was') }}: {{ RoughTimeframe::words($kept->earliest, $kept->latest, $project->location) }}
                                        &middot; {{ $kept->set_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$kept->created_at" />@if ($kept->reason !== null) &middot; &ldquo;{{ $kept->reason }}&rdquo;@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @can('managePreProduction', $project)
                        <flux:button :variant="$timeframe === null ? 'primary' : 'ghost'" :size="$timeframe === null ? 'base' : 'sm'" class="shrink-0" wire:click="openTimeframe" data-test="set-timeframe">
                            {{ $timeframe === null ? __('Set rough timeframe') : __('Change') }}
                        </flux:button>
                    @endcan
                </div>
            </div>

            {{-- The Color Specification (slice 10, step 10b). --}}
            <livewire:pages::projects.colors :project="$project" :key="'colors-'.$project->id" />

            {{-- The walkthrough, punch list and signoff (slice 14, step 14b): once all the work has finished. --}}
            @if (in_array($project->production_state, [\App\Enums\ProjectProductionState::WorkComplete, \App\Enums\ProjectProductionState::SignedOff], true))
                <livewire:pages::projects.completion :project="$project" :key="'completion-'.$project->id" />
            @endif

            {{-- Work Packages and the dates offered to the customer (slice 11, step 11a): once the job is ready to schedule, and kept in view after. --}}
            @if ($readiness->isReadyToSchedule() || $project->workPackages()->exists())
                <livewire:pages::projects.work :project="$project" :key="'work-'.$project->id" />
            @endif
        @endif
    @endunless

    {{-- The customer's change orders (slice 18, step 18a), and their invoices and payments (slice 17), kept in view on a cancelled job too. --}}
    <livewire:pages::projects.change-orders :project="$project" :key="'change-orders-'.$project->id" />
    <livewire:pages::projects.payments :project="$project" :key="'payments-'.$project->id" />

    {{-- The Brand Ambassador package (slice 21). --}}
    <livewire:pages::projects.ambassador :project="$project" :key="'ambassador-'.$project->id" />

    {{-- Warranty cases (slice 22). --}}
    <livewire:pages::projects.warranty :project="$project" :key="'warranty-'.$project->id" />

    {{-- Job costing (slice 19): finance-sensitive, only for those who may see payouts. --}}
    @can('viewPayouts', $project)
        <livewire:pages::projects.job-costing :project="$project" :key="'job-costing-'.$project->id" />
    @endcan

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-sale">
            <flux:heading size="lg" level="2">{{ __('What was sold') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Proposal') }}</dt>
                    <dd class="text-ink">{{ $proposal->name }}</dd>
                    <dd class="text-sm text-zinc-600">{{ __('Priced at :price', ['price' => $proposal->price()]) }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ $project->isCancelled() ? __('Was sold for') : __('Sold for') }}</dt>
                    <dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="project-sold-for">{{ $project->soldFor() }}</dd>
                    <dd class="text-sm text-zinc-600"><x-local-time :at="$project->sold_at" /></dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Salesperson') }}</dt>
                    <dd class="text-ink" data-test="project-salesperson">{{ $project->salesperson_name ?? __('Nobody recorded') }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Presented at') }}</dt>
                    @forelse ($this->appointments as $appointment)
                        <dd class="text-ink" wire:key="project-appointment-{{ $appointment->id }}">{{ $when($appointment) }} &middot; {{ $appointment->salesperson_name }}</dd>
                    @empty
                        <dd class="text-ink">&mdash;</dd>
                    @endforelse
                </div>

                @if ($proposal->result_note !== null && ! $project->isCancelled())
                    <div>
                        <dt class="text-sm text-zinc-500">{{ __('Note at the sale') }}</dt>
                        <dd class="whitespace-pre-line text-ink">{{ $proposal->result_note }}</dd>
                    </div>
                @endif
            </dl>

            @can('view', $project->lead)
                <div>
                    <flux:link :href="route('leads.show', $project->lead_id)" wire:navigate data-test="project-lead-link">{{ __('Open the lead it came from') }}</flux:link>
                </div>
            @endcan
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-customer">
            <flux:heading size="lg" level="2">{{ __('Who and where') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt>
                    <dd class="text-ink">
                        @can('view', $project->account)
                            <flux:link :href="route('customers.show', $project->account_id)" wire:navigate>{{ $project->account->display_name }}</flux:link>
                        @else
                            {{ $project->account->display_name }}
                        @endcan
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Contact') }}</dt>
                    @if ($project->contact === null)
                        <dd class="text-ink">&mdash;</dd>
                    @else
                        <dd class="text-ink">{{ $project->contact->fullName() ?? __('No name given') }}</dd>
                        @if ($project->contact->displayPhone() !== null)
                            <dd class="tabular-nums text-ink">{{ $project->contact->displayPhone() }}</dd>
                        @endif
                        @if ($project->contact->email !== null)
                            <dd class="text-ink">{{ $project->contact->email }}</dd>
                        @endif
                    @endif
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Property') }}</dt>
                    <dd class="text-ink">{{ $project->property->summary() }}</dd>
                    <dd class="text-sm text-zinc-600">{{ $project->property->address }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-work">
            <flux:heading size="lg" level="2">{{ __('The work') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Service(s)') }}</dt>
                    @forelse ($project->services as $service)
                        <dd class="text-ink" wire:key="project-service-{{ $service->id }}">
                            {{ $service->name }}@if ($service->pivot->is_primary) <span class="text-sm text-zinc-500">&middot; {{ __('main') }}</span>@endif
                        </dd>
                    @empty
                        <dd class="text-ink">&mdash;</dd>
                    @endforelse
                </div>

                @if ($project->projectSizeRangeLabel() !== null)
                    <div>
                        <dt class="text-sm text-zinc-500">{{ __('Project size at intake') }}</dt>
                        <dd class="text-ink">{{ $project->projectSizeRangeLabel() }}</dd>
                    </div>
                @endif

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Description') }}</dt>
                    <dd class="whitespace-pre-line text-ink" data-test="project-description">{{ $project->description ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-activity">
            <flux:heading size="lg" level="2">{{ __('Activity') }}</flux:heading>

            <ol class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->activity as $event)
                    <li wire:key="project-activity-{{ $event->id }}" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0" data-test="project-activity-event">
                        <span class="font-semibold text-ink">{{ $this->activityLabel($event) }}</span>
                        @if ($event->reason !== null)
                            <span class="text-sm text-zinc-700">&ldquo;{{ $event->reason }}&rdquo;</span>
                        @endif
                        <span class="text-sm text-zinc-500"><x-local-time :at="$event->created_at" /> &middot; {{ __('Recorded by :name', ['name' => $event->madeBy()]) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- Pre-production dialogs. Kept on the page by what the person may do, so each can close properly. --}}
    @can('managePreProduction', $project)
        <flux:modal name="welcome-call" class="w-full max-w-lg">
            <form wire:submit="saveWelcomeCall" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Welcome call made') }}</flux:heading>
                    <flux:subheading>{{ __('Tick this once the customer has had their welcome call. Nothing is sent to the customer.') }}</flux:subheading>
                </div>

                <flux:input wire:model="welcomeCallOn" type="date" :label="__('Day of the call')" class="max-w-52" data-test="welcome-call-on" />

                <flux:textarea wire:model="welcomeCallNote" rows="2" :label="__('Note (optional)')" data-test="welcome-call-note" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-welcome-call">{{ __('Tick it') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="undo-welcome-call"
            :title="__('Untick the welcome call?')"
            :description="__('For one ticked by mistake. This project will wait for its welcome call again.')"
            action="undoWelcomeCall"
            :confirm-label="__('Untick')"
            confirm-variant="primary"
        >
            <flux:textarea wire:model="welcomeCallUndoNote" rows="2" :label="__('Why it is being unticked (required)')" data-test="welcome-call-undo-note" />
        </x-confirmation-modal>

        <flux:modal name="project-timeframe" class="w-full max-w-lg">
            <form wire:submit="saveTimeframe" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $project->timeframe_earliest === null ? __('Set the rough timeframe') : __('Change the rough timeframe') }}</flux:heading>
                    <flux:subheading>{{ __('When the work is roughly expected to start. It is not a booked date and reserves nothing.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="timeframeEarliest" type="date" :label="__('Earliest start')" data-test="timeframe-earliest" />
                    <flux:input wire:model="timeframeLatest" type="date" :label="__('Latest start')" data-test="timeframe-latest" />
                </div>

                <flux:textarea wire:model="timeframeNote" rows="2" :label="__('Note (optional)')" :placeholder="__('After the roof is done')" data-test="timeframe-note" />

                @if ($project->timeframe_earliest !== null)
                    <flux:textarea wire:model="timeframeReason" rows="2" :label="__('Why it is being changed (required)')" data-test="timeframe-reason" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-timeframe">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @can('update', $project)
        <flux:modal name="edit-project" class="w-full max-w-lg">
            <form wire:submit="saveEdit" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit this project') }}</flux:heading>
                    <flux:subheading>{{ __('The title is how the job is named in every list. What was sold and for how much come from the proposal and are not changed here.') }}</flux:subheading>
                </div>

                <flux:input wire:model="projectTitle" maxlength="{{ Project::TITLE_LENGTH }}" :label="__('Title')" data-test="project-title-field" />

                <flux:textarea wire:model="projectDescription" rows="4" :label="__('Description (optional)')" data-test="project-description-field" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-project">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</section>
