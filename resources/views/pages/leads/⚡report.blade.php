<?php

use App\Actions\Proposals\SubmitAfterActionReport;
use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Objection;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The after-action report (product-architecture.md, 5.6;
 * docs/slice-7-appointment-results-plan.md, step 7c): one short form for an
 * appointment whose time has come, made to be filled in on a phone straight
 * after it. It asks only what the answers so far make necessary.
 *
 * It does what the buttons on the Lead page do, through the same rules, and
 * is saved whole or not at all. For the salesperson whose appointment it is,
 * or a manager.
 */
new #[Title('After-action report')] class extends Component {
    /** What a proposal in the report starts with. */
    private const array BLANK = ['name' => '', 'price' => '', 'propertyId' => null, 'result' => '', 'soldAmount' => '', 'lossReasonId' => '', 'lostTo' => '', 'objectionId' => '', 'note' => ''];

    #[Locked]
    public int $leadId;

    #[Locked]
    public int $appointmentId;

    /** "run" or "no_show", once answered. */
    public string $outcome = '';

    /** "yes" or "no", once answered: whether anything was presented. */
    public string $presented = '';

    /** @var list<array<string, mixed>> What was presented for the first time at this appointment. */
    public array $proposals = [];

    /** @var array<int, array<string, mixed>> The Lead's proposals from earlier visits that are still pending, by ID. */
    public array $existing = [];

    public string $note = '';

    /** The Lead's lifecycle revision when the report was opened, so it refuses a Lead that has changed since. */
    #[Locked]
    public int $knownLifecycleVersion = 0;

    public function mount(Lead $lead, Appointment $appointment): void
    {
        abort_unless($appointment->lead_id === $lead->id, 404);

        $this->authorize('closeOutAppointment', [$lead, $appointment]);

        $this->leadId = $lead->id;
        $this->appointmentId = $appointment->id;
        $this->knownLifecycleVersion = (int) $lead->lifecycle_version;

        // Already answered, or not yet started: there is nothing to report here.
        if (! $this->waiting) {
            $this->redirectRoute('leads.show', $lead, navigate: true);

            return;
        }

        $this->proposals = [$this->blank()];

        foreach ($this->earlierProposals as $proposal) {
            $this->existing[$proposal->id] = ['presented' => false, ...self::BLANK];
        }
    }

    /**
     * Any answer that changes clears the refusals, so one that was just put
     * right is not left looking wrong.
     */
    public function updated(): void
    {
        $this->resetValidation();
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with(['location', 'account', 'property'])->findOrFail($this->leadId);
    }

    #[Computed]
    public function appointment(): Appointment
    {
        return Appointment::query()->where('lead_id', $this->leadId)->findOrFail($this->appointmentId);
    }

    /**
     * Whether the appointment is still waiting for its answer.
     */
    #[Computed]
    public function waiting(): bool
    {
        return $this->lead->status === LeadStatus::AppointmentBooked && $this->appointment->awaitsCloseOutAt(now());
    }

    /**
     * The Lead's proposals from earlier visits that are still pending.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function earlierProposals(): Collection
    {
        return $this->lead->proposals()->counted()->where('result', ProposalResult::Pending)->get();
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
     * The customer's properties a proposal can be for (decision 132).
     *
     * @return Collection<int, Property>
     */
    #[Computed]
    public function properties(): Collection
    {
        return $this->lead->account?->properties()->notArchived()->orderBy('id')->get() ?? new Collection;
    }

    public function addProposal(): void
    {
        $this->proposals[] = $this->blank();
        $this->resetValidation();
    }

    /**
     * A new proposal to fill in, on the Lead's property until another is chosen.
     *
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [...self::BLANK, 'propertyId' => $this->lead->property_id];
    }

    public function removeProposal(int $index): void
    {
        unset($this->proposals[$index]);

        $this->proposals = array_values($this->proposals);
        $this->resetValidation();
    }

    /**
     * Save the report. The action checks everything again on the Lead as it
     * is at that moment, and saves all of it or none.
     */
    public function submit(SubmitAfterActionReport $submitAfterActionReport): void
    {
        $this->authorize('closeOutAppointment', [$this->lead, $this->appointment]);
        $this->resetValidation();

        // A new proposal left completely empty was not meant: it is dropped, here and on the page, so every refusal lands on the right one.
        $filled = array_values(array_filter(
            $this->proposals,
            fn (array $row): bool => trim((string) ($row['name'] ?? '')) !== '' || trim((string) ($row['price'] ?? '')) !== '' || ($row['result'] ?? '') !== '',
        ));

        $this->proposals = $filled === [] ? [$this->blank()] : $filled;

        // With one from an earlier visit presented again, the empty new proposal is not missing anything.
        $again = array_filter($this->existing, fn (array $row): bool => (bool) ($row['presented'] ?? false));
        $new = $filled === [] && $again !== [] ? [] : $this->proposals;

        $submitAfterActionReport->handle($this->lead, $this->appointment, [
            'outcome' => $this->outcome,
            'note' => $this->note,
            'presented' => $this->presented,
            'proposals' => $new,
            'existing' => $this->existing,
        ], Auth::user(), $this->knownLifecycleVersion);

        $lead = $this->lead->fresh();

        Flux::toast(variant: 'success', text: match (true) {
            $this->outcome !== AppointmentState::Run->value => __('Report saved: no-show. The lead stays with its salesperson to be booked again.'),
            $lead->status === LeadStatus::Sold => __('Report saved. The lead now shows Sold.'),
            $this->presented === 'no' => __('Report saved: run, nothing presented.'),
            default => __('Report saved.'),
        });

        $this->redirectRoute('leads.show', $lead, navigate: true);
    }
}; ?>

<section class="mx-auto flex w-full max-w-2xl flex-col gap-6" data-test="after-action-report">
    @php
        $lead = $this->lead;
        $appointment = $this->appointment;
        $startsAt = $appointment->starts_at->copy()->timezone($appointment->timezone);
        $run = $outcome === AppointmentState::Run->value;
        $results = [ProposalResult::Sold->value => __('Sold'), ProposalResult::Pending->value => __('Still pending'), ProposalResult::Lost->value => __('Lost')];
    @endphp

    <div>
        <flux:link :href="route('leads.show', $lead)" wire:navigate class="text-sm">&larr; {{ $lead->displayName() ?? __('Lead') }}</flux:link>
    </div>

    <div>
        <flux:heading size="xl" level="1">{{ __('After-action report') }}</flux:heading>
        <flux:subheading>{{ __('A minute or two, straight after the appointment. Nothing is saved until the end.') }}</flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="report-appointment">
        <div class="font-semibold text-ink">{{ $lead->account?->display_name ?? $lead->displayName() }}</div>
        <div class="text-ink">{{ $startsAt->format('D, M j, Y') }} &middot; {{ $startsAt->format('g:i A T') }} &middot; {{ $appointment->salesperson_name }}</div>
        @if ($lead->property !== null)
            <div class="text-sm text-zinc-700">{{ $lead->property->summary() }}</div>
        @endif
    </div>

    <form wire:submit="submit" class="flex flex-col gap-6">
        <flux:error name="stale" />

        <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5" data-test="report-outcome">
            <flux:radio.group wire:model.live="outcome" :label="__('1. What happened?')">
                <flux:radio value="{{ AppointmentState::Run->value }}" :label="__('The appointment was run')" data-test="report-run" />
                <flux:radio value="{{ AppointmentState::NoShow->value }}" :label="__('No-show: the customer was not there')" data-test="report-no-show" />
            </flux:radio.group>
            <flux:error name="closeOutOutcome" />
        </div>

        @if ($run)
            <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5" data-test="report-presented">
                <flux:radio.group wire:model.live="presented" :label="__('2. Was anything presented?')">
                    <flux:radio value="yes" :label="__('Yes')" data-test="report-presented-yes" />
                    <flux:radio value="no" :label="__('No, nothing was presented')" data-test="report-presented-no" />
                </flux:radio.group>
                <flux:error name="presented" />

                @if ($presented === 'no')
                    <flux:text class="text-sm">{{ __('The appointment will count as run with nothing presented. A proposal can still be added later from the lead.') }}</flux:text>
                @endif
            </div>
        @endif

        @if ($run && $presented === 'yes')
            @if ($this->earlierProposals->isNotEmpty())
                <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="report-existing">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('From an earlier visit') }}</flux:heading>
                        <flux:subheading>{{ __('Tick each one that was presented again today.') }}</flux:subheading>
                    </div>

                    @foreach ($this->earlierProposals as $proposal)
                        <div wire:key="report-existing-{{ $proposal->id }}" class="flex flex-col gap-4 border-t border-zinc-200 pt-4 first:border-t-0 first:pt-0" data-test="report-existing-proposal">
                            <flux:checkbox wire:model.live="existing.{{ $proposal->id }}.presented" :label="$proposal->name.', '.$proposal->price()" />
                            <flux:error name="existing.{{ $proposal->id }}.presented" />

                            @if ($existing[$proposal->id]['presented'] ?? false)
                                <div class="flex flex-col gap-1">
                                    <flux:radio.group wire:model.live="existing.{{ $proposal->id }}.result" :label="__('What became of it?')">
                                        @foreach ($results as $value => $label)
                                            <flux:radio :value="$value" :label="$label" wire:key="report-existing-{{ $proposal->id }}-{{ $value }}" />
                                        @endforeach
                                    </flux:radio.group>
                                    <flux:error name="existing.{{ $proposal->id }}.proposalResult" />
                                </div>

                                <x-proposal.result-fields
                                    model="existing.{{ $proposal->id }}"
                                    error-prefix="existing.{{ $proposal->id }}."
                                    :result="$existing[$proposal->id]['result'] ?? ''"
                                    :values="$existing[$proposal->id]"
                                    :reasons="$this->lossReasons"
                                    :objections="$this->objections"
                                    :price="ltrim($proposal->price(), '$')"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="report-proposals">
                <div>
                    <flux:heading size="lg" level="2">{{ $this->earlierProposals->isEmpty() ? __('3. What was presented?') : __('New today') }}</flux:heading>
                    <flux:subheading>{{ __('One for each thing pitched, with its price and what became of it.') }}</flux:subheading>
                </div>

                @foreach ($proposals as $index => $row)
                    <div wire:key="report-proposal-{{ $index }}" class="flex flex-col gap-4 border-t border-zinc-200 pt-4 first:border-t-0 first:pt-0" data-test="report-proposal">
                        <div class="flex flex-col gap-1">
                            <flux:input wire:model="proposals.{{ $index }}.name" maxlength="{{ Proposal::NAME_LENGTH }}" :label="__('What was pitched')" :placeholder="__('Whole exterior')" data-test="report-proposal-name" />
                            <flux:error name="proposals.{{ $index }}.proposalName" />
                        </div>

                        <div class="flex flex-col gap-1">
                            <flux:input wire:model="proposals.{{ $index }}.price" inputmode="decimal" :label="__('Price, in dollars')" placeholder="8,450" data-test="report-proposal-price" />
                            <flux:error name="proposals.{{ $index }}.proposalPrice" />
                        </div>

                        {{-- One proposal is for one of the customer's properties (decision 132). Asked only when there is a choice. --}}
                        @if ($this->properties->count() > 1)
                            <div class="flex flex-col gap-1">
                                <flux:select wire:model="proposals.{{ $index }}.propertyId" :label="__('Property')" data-test="report-proposal-property">
                                    @foreach ($this->properties as $property)
                                        <flux:select.option :value="$property->id" wire:key="report-proposal-{{ $index }}-property-{{ $property->id }}">{{ $property->summary() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="proposals.{{ $index }}.proposalPropertyId" />
                            </div>
                        @endif

                        <div class="flex flex-col gap-1">
                            <flux:radio.group wire:model.live="proposals.{{ $index }}.result" :label="__('What became of it?')">
                                @foreach ($results as $value => $label)
                                    <flux:radio :value="$value" :label="$label" wire:key="report-proposal-{{ $index }}-{{ $value }}" data-test="report-proposal-{{ $value }}" />
                                @endforeach
                            </flux:radio.group>
                            <flux:error name="proposals.{{ $index }}.proposalResult" />
                        </div>

                        <x-proposal.result-fields
                            model="proposals.{{ $index }}"
                            error-prefix="proposals.{{ $index }}."
                            :result="$row['result'] ?? ''"
                            :values="$row"
                            :reasons="$this->lossReasons"
                            :objections="$this->objections"
                        />

                        @if (count($proposals) > 1)
                            <div>
                                <flux:button type="button" size="sm" variant="ghost" wire:click="removeProposal({{ $index }})" data-test="report-remove-proposal">{{ __('Remove this one') }}</flux:button>
                            </div>
                        @endif
                    </div>
                @endforeach

                <div>
                    <flux:button type="button" size="sm" icon="plus" wire:click="addProposal" data-test="report-add-proposal">{{ __('Another proposal') }}</flux:button>
                </div>
            </div>
        @endif

        @if ($outcome !== '')
            <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5" data-test="report-notes">
                <flux:textarea wire:model="note" rows="3" :label="__('Short notes (optional)')" data-test="report-note" />

                @if ($run && $presented === 'yes')
                    <flux:text class="text-sm">{{ __('If a return visit is needed, book it from the lead once this is saved.') }}</flux:text>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <flux:button :href="route('leads.show', $lead)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="report-submit">{{ __('Save report') }}</flux:button>
            </div>
        @endif
    </form>
</section>
