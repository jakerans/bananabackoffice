<?php

use App\Actions\Proposals\AddProposal;
use App\Actions\Proposals\PresentProposalAgain;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Proposals card on a Lead (docs/slice-7-appointment-results-plan.md,
 * step 7b): what was pitched and for how much, and at which of the Lead's run
 * appointments. It is shown once the Lead has an appointment that was run, or
 * a proposal. Anyone who may see the Lead sees it; the salesperson the Lead is
 * with, or a manager, adds to it.
 */
new class extends Component {
    #[Locked]
    public int $leadId;

    public string $proposalName = '';

    public string $proposalPrice = '';

    /** @var list<int|string> The run appointments the new proposal was presented at. */
    public array $proposalAppointmentIds = [];

    /** The proposal the "presented again" dialog was opened for. */
    #[Locked]
    public ?int $againProposalId = null;

    public ?int $againAppointmentId = null;

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
        $this->resetValidation($property);
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with('location')->findOrFail($this->leadId);
    }

    /**
     * The Lead's appointments that were run, earliest first.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function runAppointments(): Collection
    {
        return $this->lead->appointments()->wereRun()->withCount('proposals')->get();
    }

    /**
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposals(): Collection
    {
        return $this->lead->proposals()->with('appointments')->get();
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

        $proposal = $addProposal->handle($this->lead, $this->proposalName, $this->proposalPrice, $this->proposalAppointmentIds, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('add-proposal')->close();
        Flux::toast(variant: 'success', text: __('Proposal added: :name, :price.', ['name' => $proposal->name, 'price' => $proposal->price()]));
    }

    public function openAgain(int $proposalId): void
    {
        $this->authorize('manageProposals', $this->lead);

        $proposal = $this->proposals->firstWhere('id', $proposalId) ?? abort(404);
        $others = $this->otherAppointmentsFor($proposal);

        if ($others->isEmpty()) {
            abort(409, __('This proposal is already recorded at every appointment that was run. Reload the page.'));
        }

        $this->againProposalId = $proposal->id;
        $this->againAppointmentId = $others->last()->id;
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('present-proposal-again')->show();
    }

    /**
     * Record that the proposal was presented at another run appointment.
     */
    public function presentAgain(PresentProposalAgain $presentProposalAgain): void
    {
        $this->authorize('manageProposals', $this->lead);
        $this->resetValidation();

        $proposal = $this->proposals->firstWhere('id', $this->againProposalId) ?? abort(409, __('This proposal is no longer here. Reload the page.'));

        $presentProposalAgain->handle($this->lead, $proposal, $this->againAppointmentId, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('present-proposal-again')->close();
        Flux::toast(variant: 'success', text: __('Recorded at that appointment too.'));
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

    private function changed(): void
    {
        unset($this->lead, $this->runAppointments, $this->proposals, $this->shown);
    }
}; ?>

{{-- Nothing is shown until an appointment has been run, but the card stays on the page so it appears the moment one is. --}}
<div @class(['hidden' => ! $this->shown, 'flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5' => $this->shown]) data-test="lead-proposals">
    @if ($this->shown)
        @php
            $lead = $this->lead;
            $when = fn (Appointment $appointment): string => $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j, Y \a\t g:i A T');
            $again = $this->proposals->firstWhere('id', $this->againProposalId);
        @endphp

        <flux:heading size="lg" level="2">{{ __('Proposals') }}</flux:heading>

        @foreach ($this->runAppointments->where('proposals_count', 0) as $appointment)
            <flux:text wire:key="no-proposal-{{ $appointment->id }}" data-test="no-proposal-yet">
                {{ __('The appointment on :when was run and has no proposal yet. Until one is added it counts as run with nothing presented.', ['when' => $when($appointment)]) }}
            </flux:text>
        @endforeach

        @if ($this->proposals->isNotEmpty())
            <ul class="flex flex-col gap-4">
                @foreach ($this->proposals as $proposal)
                    <li wire:key="proposal-{{ $proposal->id }}" class="flex flex-col gap-1 border-t border-zinc-200 pt-4 first:border-t-0 first:pt-0" data-test="proposal">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                            <span class="font-semibold text-ink" data-test="proposal-name">{{ $proposal->name }}</span>
                            <span class="tabular-nums text-ink" data-test="proposal-price">{{ $proposal->price() }}</span>
                        </div>

                        <span class="text-sm text-zinc-700" data-test="proposal-appointments">
                            {{ __('Presented at:') }} {{ $proposal->appointments->map($when)->implode('; ') }}
                        </span>

                        <span class="text-sm text-zinc-500">
                            {{ __('Added by') }} {{ $proposal->created_by_name }}@if ($proposal->created_at !== null) &middot; <x-local-time :at="$proposal->created_at" />@endif
                        </span>

                        @can('manageProposals', $lead)
                            @if ($this->otherAppointmentsFor($proposal)->isNotEmpty())
                                <div>
                                    <flux:button size="sm" variant="ghost" wire:click="openAgain({{ $proposal->id }})" data-test="present-proposal-again">{{ __('Presented at another appointment') }}</flux:button>
                                </div>
                            @endif
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('manageProposals', $lead)
            @if ($this->runAppointments->isNotEmpty())
                <div>
                    <flux:button variant="primary" wire:click="openAdd" data-test="add-proposal">{{ __('Add proposal') }}</flux:button>
                </div>
            @endif

            <flux:modal name="add-proposal" class="w-full max-w-lg">
                <form wire:submit="add" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Add a proposal') }}</flux:heading>
                        <flux:subheading>{{ __('What was pitched and for how much. An appointment that was run counts as presented once it has a proposal.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="proposalName" maxlength="{{ Proposal::NAME_LENGTH }}" :label="__('What was pitched')" :placeholder="__('Whole exterior')" data-test="proposal-name-field" />

                    <flux:input wire:model="proposalPrice" inputmode="decimal" :label="__('Price, in dollars')" placeholder="8,450" data-test="proposal-price-field" />

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

            <flux:modal name="present-proposal-again" class="w-full max-w-lg">
                <form wire:submit="presentAgain" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Presented at another appointment') }}</flux:heading>
                        <flux:subheading>
                            {{ $again === null ? '' : __(':name, :price, was presented again on a return visit.', ['name' => $again->name, 'price' => $again->price()]) }}
                        </flux:subheading>
                    </div>

                    <flux:select wire:model="againAppointmentId" :label="__('Appointment')" data-test="again-appointment">
                        @foreach ($again === null ? [] : $this->otherAppointmentsFor($again) as $appointment)
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
        @endcan
    @endif
</div>
