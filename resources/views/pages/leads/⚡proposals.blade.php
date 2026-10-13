<?php

use App\Actions\Proposals\AddProposal;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\EditProposal;
use App\Actions\Proposals\MarkLeadLost;
use App\Actions\Proposals\MarkProposalAddedInError;
use App\Actions\Proposals\PresentProposalAgain;
use App\Actions\Proposals\RecordNoDemoReason;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Proposals\UndoLeadLost;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\Objection;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Support\Projects\RoughTimeframe;
use App\Support\Proposals\ProposalValidity;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Proposals card on a Lead (docs/slice-7-appointment-results-plan.md,
 * steps 7b and 7c): what was pitched and for how much, at which of the Lead's
 * run appointments, and what became of it. It is shown once the Lead has an
 * appointment that was run, or a proposal.
 *
 * Anyone who may see the Lead sees it. The salesperson the Lead is with, or a
 * manager, adds proposals, changes one that is still pending (the day it is
 * valid until among the rest, step 9a of slice 9), gives each its
 * result, says why nothing was presented at a run appointment, and marks the
 * Lead lost. A manager alone corrects a result, marks a proposal "added in
 * error" and undoes a lost Lead.
 */
new class extends Component {
    /** What a result dialog starts with. */
    private const array NO_RESULT = ['soldAmount' => '', 'lossReasonId' => '', 'lostTo' => '', 'objectionId' => '', 'note' => ''];

    #[Locked]
    public int $leadId;

    public string $proposalName = '';

    public string $proposalPrice = '';

    /** @var list<int|string> The run appointments the new proposal was presented at. */
    public array $proposalAppointmentIds = [];

    /** Which of the customer's properties the proposal is for. */
    public ?int $proposalPropertyId = null;

    /** The day the proposal being changed is valid until, as "2026-10-20". */
    public string $proposalValidUntil = '';

    /** The proposal the open dialog is about. */
    #[Locked]
    public ?int $proposalId = null;

    public ?int $againAppointmentId = null;

    /** Which result the result dialog was opened for: sold, lost or pending. */
    #[Locked]
    public string $proposalResult = '';

    /** @var array<string, mixed> What goes with a result, or with marking the Lead lost. */
    public array $result = self::NO_RESULT;

    public string $addedInErrorNote = '';

    public string $correctionNote = '';

    public string $undoLostNote = '';

    /** The run appointment whose "why nothing was presented" the open dialog is about. */
    #[Locked]
    public ?int $noDemoAppointmentId = null;

    public string $noDemoReason = '';

    /** The Lead's lifecycle revision when a dialog was opened, so a save refuses a Lead that has changed since. */
    #[Locked]
    public int $knownLifecycleVersion = 0;

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->leadId = $lead->id;
    }

    /**
     * A field that was refused and has since been changed no longer shows
     * its refusal, so an answer just given is not left looking wrong.
     */
    public function updated(string $property): void
    {
        str_starts_with($property, 'result.') ? $this->resetValidation() : $this->resetValidation($property);
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with('location')->findOrFail($this->leadId);
    }

    /**
     * The Lead's appointments that were run, earliest first, each with how
     * many proposals that count were presented at it.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function runAppointments(): Collection
    {
        return $this->lead->appointments()->wereRun()->withCount(['proposals' => fn ($proposals) => $proposals->counted()])->get();
    }

    /**
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposals(): Collection
    {
        return $this->lead->proposals()->with(['appointments', 'property', 'project.location', 'project.handoff'])->get();
    }

    /**
     * The customer's properties a proposal can be for (decision 132).
     *
     * @return Collection<int, Property>
     */
    #[Computed]
    public function properties(): Collection
    {
        return $this->lead->account?->properties()->notArchived()->orderBy('id')->get() ?? new Collection;
    }

    /**
     * Whether there is anything to show: the card appears once the Lead has
     * an appointment that was run, or a proposal.
     */
    #[Computed]
    public function shown(): bool
    {
        return $this->runAppointments->isNotEmpty() || $this->proposals->isNotEmpty();
    }

    /**
     * Whether the signed-in person is a manager where the Lead is.
     */
    #[Computed]
    public function manages(): bool
    {
        return Auth::user()->can('manageAppointment', $this->lead);
    }

    /**
     * The appointment still to come, if one is booked: the return visit.
     */
    #[Computed]
    public function returnVisit(): ?Appointment
    {
        return $this->lead->appointments()->upcomingAt(now())->first();
    }

    /**
     * Whether the Lead can be marked lost now: with Sales, an appointment run,
     * and none still booked.
     */
    #[Computed]
    public function canBeLost(): bool
    {
        return $this->lead->isWithSales() && $this->runAppointments->isNotEmpty() && $this->lead->standingAppointment() === null;
    }

    /**
     * Whether this person can mark the Lead lost from the Contact Center's
     * Rehash queue: the customer told the call center no (decision 137,
     * answer 6).
     */
    #[Computed]
    public function canBeLostFromRehash(): bool
    {
        return $this->lead->isInRehash() && Auth::user()->can('markLostFromRehash', $this->lead);
    }

    /**
     * @return Collection<int, SalesLossReason>
     */
    #[Computed]
    public function lossReasons(): Collection
    {
        return SalesLossReason::query()->active()->ordered()->get();
    }

    /**
     * @return Collection<int, Objection>
     */
    #[Computed]
    public function objections(): Collection
    {
        return Objection::query()->active()->ordered()->get();
    }

    /**
     * The run appointments a proposal has not been recorded at yet.
     *
     * @return Collection<int, Appointment>
     */
    public function otherAppointmentsFor(Proposal $proposal): Collection
    {
        return $this->runAppointments->whereNotIn('id', $proposal->appointments->modelKeys())->values();
    }

    public function openAdd(): void
    {
        $this->authorize('manageProposals', $this->lead);

        $this->reset('proposalName', 'proposalPrice', 'proposalAppointmentIds');
        $this->resetValidation();

        // With one run appointment, it is the one. With several, the latest is the likely one.
        $latest = $this->runAppointments->last();
        $this->proposalAppointmentIds = $latest === null ? [] : [(string) $latest->id];
        $this->proposalPropertyId = $this->lead->property_id;

        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('add-proposal')->show();
    }

    /**
     * Add the proposal. The action checks everything again on the Lead as it
     * is at that moment, and saves all of it or none.
     */
    public function add(AddProposal $addProposal): void
    {
        $this->authorize('manageProposals', $this->lead);
        $this->resetValidation();

        $proposal = $addProposal->handle($this->lead, $this->proposalName, $this->proposalPrice, $this->proposalAppointmentIds, Auth::user(), $this->knownLifecycleVersion, $this->proposalPropertyId);

        $this->saved('add-proposal', __('Proposal added: :name, :price.', ['name' => $proposal->name, 'price' => $proposal->price()]));
    }

    public function openAgain(int $proposalId): void
    {
        $this->authorize('manageProposals', $this->lead);

        $proposal = $this->open($proposalId);
        $others = $this->otherAppointmentsFor($proposal);

        if ($proposal->isAddedInError() || $others->isEmpty()) {
            abort(409, __('This proposal is already recorded at every appointment that was run. Reload the page.'));
        }

        $this->againAppointmentId = $others->last()->id;

        Flux::modal('present-proposal-again')->show();
    }

    /**
     * Record that the proposal was presented at another run appointment.
     */
    public function presentAgain(PresentProposalAgain $presentProposalAgain): void
    {
        $this->authorize('manageProposals', $this->lead);
        $this->resetValidation();

        $presentProposalAgain->handle($this->lead, $this->chosen(), $this->againAppointmentId, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('present-proposal-again', __('Recorded at that appointment too.'));
    }

    /**
     * Open the dialog that gives a proposal its result.
     */
    public function openResult(int $proposalId, string $result): void
    {
        $this->authorize('manageProposals', $this->lead);

        $proposal = $this->open($proposalId);

        if (! $proposal->isOpen() || ProposalResult::tryFrom($result) === null) {
            abort(409, __('This proposal already has a result. Reload the page.'));
        }

        $this->proposalResult = $result;
        $this->result = self::NO_RESULT;

        Flux::modal('proposal-result')->show();
    }

    /**
     * Say what became of the proposal: sold, lost or still pending.
     */
    public function saveResult(RecordProposalResult $recordProposalResult): void
    {
        $this->authorize('manageProposals', $this->lead);
        $this->resetValidation();

        $proposal = $this->chosen();

        $recordProposalResult->handle($this->lead, $proposal, $this->proposalResult, $this->result, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('proposal-result', match ($this->proposalResult) {
            ProposalResult::Sold->value => __('Sold: :name. The lead now shows Sold.', ['name' => $proposal->name]),
            ProposalResult::Lost->value => __('Marked as lost: :name.', ['name' => $proposal->name]),
            default => __('Still pending: :name. The objection is recorded.', ['name' => $proposal->name]),
        });
    }

    public function openEdit(int $proposalId): void
    {
        $this->authorize('manageProposals', $this->lead);

        $proposal = $this->open($proposalId);

        if (! $proposal->isOpen()) {
            abort(409, __('Only a proposal that is still pending can be changed. Reload the page.'));
        }

        $this->proposalName = $proposal->name;
        $this->proposalPrice = ltrim($proposal->price(), '$');
        $this->proposalPropertyId = $proposal->property_id;
        $this->proposalValidUntil = (string) $proposal->valid_until?->toDateString();

        Flux::modal('edit-proposal')->show();
    }

    /**
     * Change the name, price, property or "valid until" day of a proposal that
     * is still pending.
     */
    public function saveEdit(EditProposal $editProposal): void
    {
        $this->authorize('manageProposals', $this->lead);
        $this->resetValidation();

        $editProposal->handle($this->lead, $this->chosen(), $this->proposalName, $this->proposalPrice, Auth::user(), $this->knownLifecycleVersion, $this->proposalPropertyId, $this->proposalValidUntil);

        $this->saved('edit-proposal', __('Proposal changed.'));
    }

    public function openAddedInError(int $proposalId): void
    {
        $this->authorize('manageAppointment', $this->lead);

        if (! $this->open($proposalId)->isOpen()) {
            abort(409, __('Only a proposal that is still pending can be marked. Reload the page.'));
        }

        $this->addedInErrorNote = '';

        Flux::modal('proposal-added-in-error')->show();
    }

    /**
     * Mark the proposal "added in error", with the note.
     */
    public function markAddedInError(MarkProposalAddedInError $markProposalAddedInError): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $markProposalAddedInError->handle($this->lead, $this->chosen(), $this->addedInErrorNote, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('proposal-added-in-error', __('Marked as added in error. It no longer counts.'));
    }

    public function openCorrect(int $proposalId): void
    {
        $this->authorize('manageAppointment', $this->lead);

        $proposal = $this->open($proposalId);

        if ($proposal->isAddedInError() || $proposal->result === ProposalResult::Pending) {
            abort(409, __('This proposal has no result to correct. Reload the page.'));
        }

        $this->correctionNote = '';

        Flux::modal('correct-proposal-result')->show();
    }

    /**
     * Take back a result that was given wrongly: the proposal is pending again.
     */
    public function correct(CorrectProposalResult $correctProposalResult): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $correctProposalResult->handle($this->lead, $this->chosen(), $this->correctionNote, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('correct-proposal-result', __('Corrected. The proposal is pending again.'));
    }

    public function openLost(): void
    {
        if (! $this->canBeLostFromRehash) {
            $this->authorize('manageProposals', $this->lead);

            if (! $this->canBeLost) {
                abort(409, __('This lead cannot be marked lost now. Reload the page.'));
            }
        }

        $this->result = self::NO_RESULT;
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('lead-lost')->show();
    }

    /**
     * Mark the Lead lost, with the reason and the objection.
     */
    public function markLost(MarkLeadLost $markLeadLost): void
    {
        $this->resetValidation();

        // From the Rehash queue it is the call center's to say; anywhere else, the salesperson's or a manager's.
        if ($this->lead->isInRehash()) {
            $this->authorize('markLostFromRehash', $this->lead);

            $markLeadLost->fromRehash($this->lead, $this->result, Auth::user(), $this->knownLifecycleVersion);

            $this->saved('lead-lost', __('Lead marked lost. It has left the rehash queue.'));

            return;
        }

        $this->authorize('manageProposals', $this->lead);

        $markLeadLost->handle($this->lead, $this->result, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('lead-lost', __('Lead marked lost.'));
    }

    public function openUndoLost(): void
    {
        $this->authorize('manageAppointment', $this->lead);

        if ($this->lead->status !== LeadStatus::Lost) {
            abort(409, __('This lead is not marked lost. Reload the page.'));
        }

        $this->undoLostNote = '';
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('undo-lead-lost')->show();
    }

    /**
     * Take back the lost mark: the Lead is with its salesperson again.
     */
    public function undoLost(UndoLeadLost $undoLeadLost): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $undoLeadLost->handle($this->lead, $this->undoLostNote, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('undo-lead-lost', __('The lead is no longer marked lost.'));
    }

    /**
     * Open the dialog that says why nothing was presented at a run
     * appointment (decision 133). It starts with what was said before.
     */
    public function openNoDemoReason(int $appointmentId): void
    {
        $this->authorize('manageProposals', $this->lead);

        $appointment = $this->runAppointments->where('proposals_count', 0)->firstWhere('id', $appointmentId)
            ?? abort(409, __('This appointment now has a proposal. Reload the page.'));

        $this->noDemoAppointmentId = $appointment->id;
        $this->noDemoReason = (string) $appointment->no_demo_reason;
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('no-demo-reason')->show();
    }

    /**
     * Save why nothing was presented. The appointment counts the same.
     */
    public function saveNoDemoReason(RecordNoDemoReason $recordNoDemoReason): void
    {
        $this->authorize('manageProposals', $this->lead);
        $this->resetValidation();

        $appointment = $this->runAppointments->firstWhere('id', $this->noDemoAppointmentId)
            ?? abort(409, __('This appointment is no longer here. Reload the page.'));

        $recordNoDemoReason->handle($this->lead, $appointment, $this->noDemoReason, Auth::user(), $this->knownLifecycleVersion);

        $this->saved('no-demo-reason', __('Saved. It still counts as run with nothing presented.'));
    }

    /**
     * The Lead page changed the Lead (moved it, gave it to someone): read it
     * again here.
     */
    #[On('lead-changed')]
    public function leadChanged(): void
    {
        $this->changed();
    }

    /**
     * The Appointment card changed an appointment, for one by marking it
     * run: read them again here.
     */
    #[On('lead-appointment-changed')]
    public function appointmentChanged(): void
    {
        $this->changed();
    }

    /**
     * Remember which proposal a dialog is about and what the Lead looked like
     * when it opened.
     */
    private function open(int $proposalId): Proposal
    {
        $proposal = $this->proposals->firstWhere('id', $proposalId) ?? abort(404);

        $this->proposalId = $proposal->id;
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        return $proposal;
    }

    private function chosen(): Proposal
    {
        return $this->proposals->firstWhere('id', $this->proposalId) ?? abort(409, __('This proposal is no longer here. Reload the page.'));
    }

    /**
     * Read everything again, tell the rest of the Lead page, close the dialog
     * and say what was done.
     */
    private function saved(string $modal, string $message): void
    {
        $this->changed();

        // A result can change the Lead's status, so the Lead page and the Appointment card read it again too.
        $this->dispatch('lead-appointment-changed');

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }

    private function changed(): void
    {
        unset($this->lead, $this->runAppointments, $this->proposals, $this->properties, $this->shown, $this->manages, $this->returnVisit, $this->canBeLost, $this->canBeLostFromRehash);
    }
}; ?>

{{-- Nothing is shown until an appointment has been run, but the card stays on the page so it appears the moment one is. --}}
<div @class(['hidden' => ! $this->shown, 'flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5' => $this->shown]) data-test="lead-proposals">
    @if ($this->shown)
        @php
            $lead = $this->lead;
            $when = fn (Appointment $appointment): string => $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j, Y \a\t g:i A T');
            $chosen = $this->proposals->firstWhere('id', $this->proposalId);
        @endphp

        <flux:heading size="lg" level="2">{{ __('Proposals') }}</flux:heading>

        @if ($lead->status === LeadStatus::Lost)
            <flux:callout icon="x-circle" data-test="lead-lost">
                <flux:callout.heading>{{ __('Lead marked lost: :reason', ['reason' => $lead->lost_reason_name ?? __('no reason recorded')]) }}</flux:callout.heading>
                <flux:callout.text>
                    @if ($lead->lost_to !== null)
                        <span class="block">{{ __('Went with :name', ['name' => $lead->lost_to]) }}</span>
                    @endif
                    <span class="block">{{ __('Main objection: :objection', ['objection' => $lead->lost_objection_name ?? __('none recorded')]) }}</span>
                    @if ($lead->lost_note !== null)
                        <span class="mt-1 block whitespace-pre-line">&ldquo;{{ $lead->lost_note }}&rdquo;</span>
                    @endif
                    <span class="mt-1 block">
                        {{ __('Recorded by') }} {{ $lead->lost_by_name ?? __('Unknown') }}@if ($lead->lost_at !== null) &middot; <x-local-time :at="$lead->lost_at" />@endif
                    </span>
                </flux:callout.text>
            </flux:callout>

            @if ($this->manages)
                <div>
                    <flux:button size="sm" wire:click="openUndoLost" data-test="undo-lead-lost">{{ __('Undo: the lead is not lost') }}</flux:button>
                </div>
            @endif
        @endif

        @foreach ($this->runAppointments->where('proposals_count', 0) as $appointment)
            <div wire:key="no-proposal-{{ $appointment->id }}" class="flex flex-col gap-1" data-test="no-proposal-yet">
                <flux:text>
                    {{ __('The appointment on :when was run and has no proposal yet. Until one is added it counts as run with nothing presented.', ['when' => $when($appointment)]) }}
                </flux:text>

                {{-- Why nothing was presented, for coaching (decision 133). It counts the same whatever is written. --}}
                @if ($appointment->no_demo_reason !== null)
                    <span class="text-sm text-zinc-700" data-test="no-demo-reason">
                        {{ __('Why nothing was presented:') }} <span class="whitespace-pre-line">&ldquo;{{ $appointment->no_demo_reason }}&rdquo;</span>
                        &middot; {{ $appointment->no_demo_reason_by_name ?? __('Unknown') }}
                    </span>
                @endif

                @can('manageProposals', $lead)
                    <div>
                        <flux:button size="sm" :variant="$appointment->no_demo_reason === null ? 'filled' : 'ghost'" wire:click="openNoDemoReason({{ $appointment->id }})" data-test="say-no-demo-reason">
                            {{ $appointment->no_demo_reason === null ? __('Say why nothing was presented') : __('Change the reason') }}
                        </flux:button>
                    </div>
                @endcan
            </div>
        @endforeach

        {{-- When the Lead leaves its salesperson: the day after the last pending proposal expires (slice 9, step 9a). --}}
        @php($ownedUntil = $lead->status === LeadStatus::AppointmentRun ? ProposalValidity::ownershipUntil($lead) : null)
        @if ($ownedUntil !== null)
            @php($somethingPending = $this->proposals->contains(fn (Proposal $proposal): bool => $proposal->isOpen()))
            <flux:text data-test="pending-ownership">
                {{ match (true) {
                    $somethingPending && $ownedUntil->lessThan(ProposalValidity::today($lead->location)) => __('Everything pending on this lead has expired. It is due to go to the Contact Center for rehash, unless a return visit is booked or it is sold or lost.'),
                    $somethingPending => __('After :day this lead goes to the Contact Center for rehash, unless a return visit is booked or it is sold or lost.', ['day' => $ownedUntil->format('D, M j')]),
                    $ownedUntil->lessThan(ProposalValidity::today($lead->location)) => __('Nothing is pending on this lead. It is due to go to the Contact Center for rehash, unless a proposal is added, a return visit is booked or it is marked lost.'),
                    default => __('Nothing is pending on this lead. After :day it goes to the Contact Center for rehash, unless a proposal is added, a return visit is booked or it is marked lost.', ['day' => $ownedUntil->format('D, M j')]),
                } }}
            </flux:text>
        @endif

        {{-- In the Rehash queue the call center can record that the customer said no (decision 137, answer 6). --}}
        @if ($this->canBeLostFromRehash)
            <div class="flex flex-col gap-2" data-test="rehash-lost">
                <flux:text>{{ __('This lead is with the Contact Center for rehash. Book a return visit from the appointment card, or record that the customer said no.') }}</flux:text>

                <div>
                    <flux:button variant="ghost" wire:click="openLost" data-test="mark-rehash-lead-lost">{{ __('The customer said no: mark lead lost') }}</flux:button>
                </div>
            </div>
        @endif

        @if ($this->proposals->isNotEmpty())
            <ul class="flex flex-col gap-4">
                @foreach ($this->proposals as $proposal)
                    <li wire:key="proposal-{{ $proposal->id }}" class="flex flex-col gap-1 border-t border-zinc-200 pt-4 first:border-t-0 first:pt-0" data-test="proposal">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                            <span @class(['font-semibold', 'text-ink' => ! $proposal->isAddedInError(), 'text-zinc-500 line-through' => $proposal->isAddedInError()]) data-test="proposal-name">{{ $proposal->name }}</span>
                            <span @class(['tabular-nums', 'text-ink' => ! $proposal->isAddedInError(), 'text-zinc-500 line-through' => $proposal->isAddedInError()]) data-test="proposal-price">{{ $proposal->price() }}</span>
                        </div>

                        <div data-test="proposal-result">
                            @if ($proposal->isAddedInError())
                                <flux:badge size="sm" color="zinc">{{ __('Added in error') }}</flux:badge>
                            @elseif ($proposal->result === ProposalResult::Sold)
                                <flux:badge size="sm" color="emerald">{{ __('Sold for :amount', ['amount' => $proposal->soldFor()]) }}</flux:badge>
                            @elseif ($proposal->result === ProposalResult::Lost)
                                <flux:badge size="sm" color="rose">{{ __('Lost: :reason', ['reason' => $proposal->loss_reason_name ?? __('no reason recorded')]) }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                            @endif
                        </div>

                        {{-- A sold proposal has a Project of its own (decision 130). --}}
                        @if ($proposal->project !== null && ! $proposal->project->isCancelled())
                            <span class="text-sm text-zinc-700" data-test="proposal-project">
                                {{ __('Project:') }}
                                @can('view', $proposal->project)
                                    <flux:link :href="route('projects.show', $proposal->project)" wire:navigate>{{ $proposal->project->title }}</flux:link>
                                @else
                                    {{ $proposal->project->title }}
                                @endcan
                            </span>

                            {{-- When the work is roughly expected, so Sales can answer the customer (slice 10, step 10a). --}}
                            @if (RoughTimeframe::of($proposal->project) !== null)
                                <span class="text-sm text-zinc-700" data-test="proposal-timeframe">{{ __('Rough timeframe: :when. Not a booked date.', ['when' => RoughTimeframe::of($proposal->project)]) }}</span>
                            @endif

                            {{-- Production cannot take the Project until the salesperson's handoff is complete (decision 132). --}}
                            @if ($proposal->project->handoff === null)
                                <span class="text-sm" data-test="proposal-handoff-needed">
                                    @can('completeHandoff', $proposal->project)
                                        <flux:link :href="route('projects.handoff', $proposal->project)" wire:navigate>{{ __('Complete the handoff for Production') }}</flux:link>
                                    @else
                                        <span class="text-zinc-700">{{ __('Waiting for the salesperson\'s handoff.') }}</span>
                                    @endcan
                                </span>
                            @endif
                        @endif

                        @if ($this->properties->count() > 1 && $proposal->property !== null)
                            <span class="text-sm text-zinc-700" data-test="proposal-property">{{ __('For:') }} {{ $proposal->property->summary() }}</span>
                        @endif

                        @if ($proposal->isOpen() && $proposal->valid_until !== null)
                            @php($daysLeft = ProposalValidity::daysLeft($proposal, $lead->location))
                            <span @class(['text-sm', 'font-semibold text-ink' => $daysLeft <= ContactCenterSettings::PENDING_WARNING_DAYS, 'text-zinc-700' => $daysLeft > ContactCenterSettings::PENDING_WARNING_DAYS]) data-test="proposal-validity">{{ ProposalValidity::label(ProposalValidity::of($proposal), $lead->location) }}</span>
                        @endif

                        <span class="text-sm text-zinc-700" data-test="proposal-appointments">
                            {{ __('Presented at:') }} {{ $proposal->appointments->map($when)->implode('; ') }}
                        </span>

                        <span class="text-sm text-zinc-500">
                            {{ __('Added by') }} {{ $proposal->created_by_name }}@if ($proposal->created_at !== null) &middot; <x-local-time :at="$proposal->created_at" />@endif
                        </span>

                        @if ($proposal->isAddedInError())
                            <span class="text-sm text-zinc-700" data-test="proposal-added-in-error">
                                {{ __('Marked by') }} {{ $proposal->added_in_error_by_name ?? __('Unknown') }}: &ldquo;{{ $proposal->added_in_error_note }}&rdquo; {{ __('It counts for nothing.') }}
                            </span>
                        @elseif ($proposal->result_at !== null)
                            <span class="text-sm text-zinc-700" data-test="proposal-result-details">
                                @if ($proposal->result === ProposalResult::Lost && $proposal->lost_to !== null)
                                    {{ __('Went with :name.', ['name' => $proposal->lost_to]) }}
                                @endif
                                @if ($proposal->objection_name !== null)
                                    {{ __('Main objection: :objection.', ['objection' => $proposal->objection_name]) }}
                                @endif
                                @if ($proposal->result_note !== null)
                                    &ldquo;{{ $proposal->result_note }}&rdquo;
                                @endif
                                {{ $proposal->result === ProposalResult::Pending && $proposal->objection_name === null ? __('Put back to pending by') : __('Recorded by') }} {{ $proposal->result_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$proposal->result_at" />
                            </span>
                        @endif

                        <div class="mt-1 flex flex-wrap gap-2" data-test="proposal-actions">
                            @can('manageProposals', $lead)
                                {{-- A result is given while the Lead is with Sales. In the Rehash queue it is booked again or marked lost. --}}
                                @if ($proposal->isOpen() && ($lead->isWithSales() || $lead->status === LeadStatus::Sold))
                                    <flux:button size="sm" variant="primary" wire:click="openResult({{ $proposal->id }}, 'sold')" data-test="proposal-sold">{{ __('Sold') }}</flux:button>
                                    <flux:button size="sm" wire:click="openResult({{ $proposal->id }}, 'lost')" data-test="proposal-lost">{{ __('Lost') }}</flux:button>
                                    <flux:button size="sm" wire:click="openResult({{ $proposal->id }}, 'pending')" data-test="proposal-pending">{{ __('Still pending') }}</flux:button>
                                @endif

                                @if ($proposal->isOpen() && $lead->status !== LeadStatus::Lost)
                                    <flux:button size="sm" variant="ghost" wire:click="openEdit({{ $proposal->id }})" data-test="edit-proposal">{{ __('Change') }}</flux:button>
                                @endif

                                @if (! $proposal->isAddedInError() && $this->otherAppointmentsFor($proposal)->isNotEmpty())
                                    <flux:button size="sm" variant="ghost" wire:click="openAgain({{ $proposal->id }})" data-test="present-proposal-again">{{ __('Presented at another appointment') }}</flux:button>
                                @endif
                            @endcan

                            @if ($this->manages)
                                @if ($proposal->isOpen())
                                    <flux:button size="sm" variant="ghost" wire:click="openAddedInError({{ $proposal->id }})" data-test="proposal-added-in-error-button">{{ __('Added in error') }}</flux:button>
                                @elseif (! $proposal->isAddedInError() && $lead->status !== LeadStatus::Lost)
                                    <flux:button size="sm" variant="ghost" wire:click="openCorrect({{ $proposal->id }})" data-test="correct-proposal-result">{{ __('Correct result') }}</flux:button>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- The plan's "system check whether rehash booked": Backoffice says it, nobody types it. --}}
        @if ($lead->isWithSales() && $this->proposals->contains(fn (Proposal $proposal): bool => $proposal->isOpen()))
            <flux:text data-test="return-visit-check">
                {{ $this->returnVisit === null
                    ? __('No return visit is booked for what is still pending.')
                    : __('A return visit is booked for :when.', ['when' => $when($this->returnVisit)]) }}
            </flux:text>
        @endif

        @can('manageProposals', $lead)
            <div class="flex flex-wrap gap-2">
                @if ($this->runAppointments->isNotEmpty() && $lead->status !== LeadStatus::Lost)
                    <flux:button :variant="$this->proposals->isEmpty() ? 'primary' : 'filled'" wire:click="openAdd" data-test="add-proposal">{{ __('Add proposal') }}</flux:button>
                @endif

                @if ($this->canBeLost)
                    <flux:button variant="ghost" wire:click="openLost" data-test="mark-lead-lost">{{ __('Mark lead lost') }}</flux:button>
                @endif
            </div>

            <flux:modal name="add-proposal" class="w-full max-w-lg">
                <form wire:submit="add" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Add a proposal') }}</flux:heading>
                        <flux:subheading>{{ __('What was pitched and for how much. An appointment that was run counts as presented once it has a proposal.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="proposalName" maxlength="{{ Proposal::NAME_LENGTH }}" :label="__('What was pitched')" :placeholder="__('Whole exterior')" data-test="proposal-name-field" />

                    <flux:input wire:model="proposalPrice" inputmode="decimal" :label="__('Price, in dollars')" placeholder="8,450" data-test="proposal-price-field" />

                    {{-- One proposal is for one of the customer's properties (decision 132). Asked only when there is a choice. --}}
                    @if ($this->properties->count() > 1)
                        <flux:select wire:model="proposalPropertyId" :label="__('Property')" data-test="proposal-property-field">
                            @foreach ($this->properties as $property)
                                <flux:select.option :value="$property->id" wire:key="add-proposal-property-{{ $property->id }}">{{ $property->summary() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <fieldset class="flex flex-col gap-2" data-test="proposal-appointment-choices">
                        <legend class="text-sm font-medium text-ink">
                            {{ __('Presented at') }}
                            <span class="font-normal text-zinc-500">&middot; {{ __('choose every appointment it was presented at') }}</span>
                        </legend>

                        @foreach ($this->runAppointments as $appointment)
                            <flux:checkbox wire:model="proposalAppointmentIds" :value="(string) $appointment->id" :label="$when($appointment).' · '.$appointment->salesperson_name" wire:key="proposal-appointment-{{ $appointment->id }}" />
                        @endforeach

                        <flux:error name="proposalAppointmentIds" />
                    </fieldset>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-proposal">{{ __('Add proposal') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="no-demo-reason" class="w-full max-w-lg">
                <form wire:submit="saveNoDemoReason" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Why was nothing presented?') }}</flux:heading>
                        <flux:subheading>{{ __('In your own words, for coaching. The appointment still counts as run with nothing presented until a proposal is added.') }}</flux:subheading>
                    </div>

                    <flux:textarea wire:model="noDemoReason" rows="3" maxlength="{{ RecordNoDemoReason::LENGTH }}" :label="__('What happened (required)')" data-test="no-demo-reason-field" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-no-demo-reason">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="present-proposal-again" class="w-full max-w-lg">
                <form wire:submit="presentAgain" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Presented at another appointment') }}</flux:heading>
                        <flux:subheading>
                            {{ $chosen === null ? '' : __(':name, :price, was presented again on a return visit.', ['name' => $chosen->name, 'price' => $chosen->price()]) }}
                        </flux:subheading>
                    </div>

                    <flux:select wire:model="againAppointmentId" :label="__('Appointment')" data-test="again-appointment">
                        @foreach ($chosen === null ? [] : $this->otherAppointmentsFor($chosen) as $appointment)
                            <flux:select.option :value="$appointment->id" wire:key="again-appointment-{{ $appointment->id }}">{{ $when($appointment) }} &middot; {{ $appointment->salesperson_name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-presented-again">{{ __('Record it') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="proposal-result" class="w-full max-w-lg">
                <form wire:submit="saveResult" class="flex flex-col gap-5">
                    <flux:error name="stale" />
                    <flux:error name="proposalResult" />

                    <div>
                        <flux:heading size="lg">
                            {{ match ($proposalResult) {
                                ProposalResult::Sold->value => __('Mark this proposal sold?'),
                                ProposalResult::Lost->value => __('Mark this proposal lost?'),
                                default => __('Still pending: what is holding it up?'),
                            } }}
                        </flux:heading>
                        <flux:subheading>
                            {{ $chosen === null ? '' : $chosen->name.', '.$chosen->price().'. ' }}{{ match ($proposalResult) {
                                ProposalResult::Sold->value => __('The lead will show Sold. Its other proposals stay as they are.'),
                                ProposalResult::Lost->value => __('Only this proposal is lost. To say the customer will not buy at all, mark the lead lost.'),
                                default => __('It stays pending, with the reason written down.'),
                            } }}
                        </flux:subheading>
                    </div>

                    <x-proposal.result-fields model="result" :result="$proposalResult" :values="$result" :reasons="$this->lossReasons" :objections="$this->objections" :price="$chosen === null ? null : ltrim($chosen->price(), '$')" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-proposal-result">
                            {{ match ($proposalResult) {
                                ProposalResult::Sold->value => __('Mark sold'),
                                ProposalResult::Lost->value => __('Mark lost'),
                                default => __('Save'),
                            } }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="edit-proposal" class="w-full max-w-lg">
                <form wire:submit="saveEdit" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Change this proposal') }}</flux:heading>
                        <flux:subheading>{{ __('For a wrong name or price, or to move the day it is valid until. What it was before is kept in the record.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="proposalName" maxlength="{{ Proposal::NAME_LENGTH }}" :label="__('What was pitched')" data-test="edit-proposal-name" />

                    <flux:input wire:model="proposalPrice" inputmode="decimal" :label="__('Price, in dollars')" data-test="edit-proposal-price" />

                    {{-- One proposal is for one of the customer's properties (decision 132). Asked only when there is a choice. --}}
                    @if ($this->properties->count() > 1)
                        <flux:select wire:model="proposalPropertyId" :label="__('Property')" data-test="edit-proposal-property">
                            @foreach ($this->properties as $property)
                                <flux:select.option :value="$property->id" wire:key="edit-proposal-property-{{ $property->id }}">{{ $property->summary() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    {{-- The last day the proposal is valid. The Lead stays with its salesperson until the last pending one expires. --}}
                    <flux:input
                        wire:model="proposalValidUntil"
                        type="date"
                        :label="__('Valid until')"
                        :description="$this->manages ? __('The lead stays with its salesperson until its last pending proposal expires.') : __('You can move it up to :days days ahead. A manager can set a later day.', ['days' => ProposalValidity::SALESPERSON_MAX_DAYS])"
                        class="max-w-52"
                        data-test="edit-proposal-valid-until"
                    />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-proposal-edit">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

        @endcan

        {{-- For whoever may mark the Lead lost: with Sales, or from the Rehash queue. Kept on the page by what the person may do, not by where the Lead is, so it can close properly. --}}
        @if (auth()->user()->can('manageProposals', $lead) || auth()->user()->can('markLostFromRehash', $lead))
            <flux:modal name="lead-lost" class="w-full max-w-lg">
                <form wire:submit="markLost" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Mark this lead lost?') }}</flux:heading>
                        <flux:subheading>{{ __('The customer will not buy. Every proposal still pending becomes lost with this reason, and the lead is finished. A manager can undo it.') }}</flux:subheading>
                    </div>

                    <x-proposal.result-fields model="result" result="lost" :values="$result" :reasons="$this->lossReasons" :objections="$this->objections" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="danger" data-test="save-lead-lost">{{ __('Mark lead lost') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        {{-- A manager's corrections. Kept on the page after each is used, so its dialog can close properly. --}}
        @if ($this->manages)
            <x-confirmation-modal
                name="proposal-added-in-error"
                :title="__('Mark this proposal added in error?')"
                :description="($chosen === null ? '' : $chosen->name.', '.$chosen->price().'. ').__('It stays on the record, shown as added in error, and counts for nothing from now on. This cannot be undone.')"
                action="markAddedInError"
                :confirm-label="__('Added in error')"
                :error="['stale']"
            >
                <flux:textarea wire:model="addedInErrorNote" rows="2" :label="__('Why it should not be here (required)')" data-test="added-in-error-note" />
            </x-confirmation-modal>

            <x-confirmation-modal
                name="correct-proposal-result"
                :title="__('Correct this result?')"
                :description="($chosen === null ? '' : $chosen->name.'. ').__('The proposal goes back to pending, to be answered again. If it was the only sale, the lead goes back to Appointment run.')"
                action="correct"
                :confirm-label="__('Back to pending')"
                confirm-variant="primary"
                :error="['stale']"
            >
                <flux:textarea wire:model="correctionNote" rows="2" :label="__('Why it is being corrected (required)')" data-test="result-correction-note" />
            </x-confirmation-modal>

            <x-confirmation-modal
                name="undo-lead-lost"
                :title="__('This lead is not lost?')"
                :description="__('It goes back to its salesperson as Appointment run, and the proposals that were lost with it are pending again.')"
                action="undoLost"
                :confirm-label="__('Undo')"
                confirm-variant="primary"
                :error="['stale']"
            >
                <flux:textarea wire:model="undoLostNote" rows="2" :label="__('Why this is being undone (required)')" data-test="undo-lost-note" />
            </x-confirmation-modal>
        @endif
    @endif
</div>
