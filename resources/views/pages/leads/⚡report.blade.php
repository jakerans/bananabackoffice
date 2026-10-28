<?php

use App\Actions\Proposals\RecordNoDemoReason;
use App\Actions\Proposals\SubmitAfterActionReport;
use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\Estimate;
use App\Models\Lead;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Support\Money;
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
 * What was presented is ticked from the Lead's estimates (step 31e;
 * decision 207); nothing is typed in by hand. It does what the buttons on the
 * Lead page do, through the same rules, and is saved whole or not at all. For
 * the salesperson whose appointment it is, or a manager.
 */
new #[Title('After-action report')] class extends Component {
    /** What an estimate in the report starts with. */
    private const array BLANK = ['presented' => false, 'result' => '', 'optionId' => '', 'soldAmount' => '', 'ambassadorTier' => 'none', 'ambassadorDiscount' => '', 'ambassadorItems' => [], 'ambassadorReason' => '', 'lossReasonId' => '', 'lostTo' => '', 'objectionId' => '', 'note' => ''];

    #[Locked]
    public int $leadId;

    #[Locked]
    public int $appointmentId;

    /** "run" or "no_show", once answered. */
    public string $outcome = '';

    /** "yes" or "no", once answered: whether anything was presented. */
    public string $presented = '';

    /** Why nothing was presented, when the answer is "no" (decision 133). */
    public string $noDemoReason = '';

    /** @var array<int, array<string, mixed>> The Lead's estimates not presented before, by ID. */
    public array $estimates = [];

    /** @var array<int, array<string, mixed>> The Lead's estimates from earlier visits that are still pending, by proposal ID. */
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

        foreach ($this->newEstimates as $estimate) {
            $this->estimates[$estimate->id] = self::BLANK;
        }

        foreach ($this->earlierProposals as $proposal) {
            $this->existing[$proposal->id] = self::BLANK;
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
     * The Lead's estimates that have not been presented yet, oldest first.
     *
     * @return Collection<int, Estimate>
     */
    #[Computed]
    public function newEstimates(): Collection
    {
        return $this->lead->estimates()->with('options')->whereDoesntHave('proposal')->oldest('id')->get();
    }

    /**
     * The Lead's estimates from earlier visits that are still pending, and
     * any proposal recorded by hand before step 31e.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function earlierProposals(): Collection
    {
        return $this->lead->proposals()->with('estimate.options')->counted()->where('result', ProposalResult::Pending)->get();
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
     * Save the report. The action checks everything again on the Lead as it
     * is at that moment, and saves all of it or none.
     */
    public function submit(SubmitAfterActionReport $submitAfterActionReport): void
    {
        $this->authorize('closeOutAppointment', [$this->lead, $this->appointment]);
        $this->resetValidation();

        $submitAfterActionReport->handle($this->lead, $this->appointment, [
            'outcome' => $this->outcome,
            'note' => $this->note,
            'presented' => $this->presented,
            'noDemoReason' => $this->noDemoReason,
            'estimates' => $this->estimates,
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
        $range = fn (Estimate $estimate): string => $estimate->options->min('total_cents') === $estimate->options->max('total_cents')
            ? Money::format((int) $estimate->options->max('total_cents'))
            : __(':from to :to', ['from' => Money::format((int) $estimate->options->min('total_cents')), 'to' => Money::format((int) $estimate->options->max('total_cents'))]);
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
                    <flux:textarea wire:model="noDemoReason" rows="3" maxlength="{{ RecordNoDemoReason::LENGTH }}" :label="__('Why was nothing presented? (required)')" :description="__('In your own words, for coaching.')" data-test="report-no-demo-reason" />

                    <flux:text class="text-sm">{{ __('The appointment will count as run with nothing presented, whatever the reason. An estimate can still be marked presented later from the lead.') }}</flux:text>
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
                            <flux:checkbox
                                wire:model.live="existing.{{ $proposal->id }}.presented"
                                :label="$proposal->name.', '.$proposal->price()"
                                :description="$proposal->isFromEstimate() ? null : __('Recorded by hand')"
                            />
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
                                    :options="$proposal->estimate?->options"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="report-estimates">
                <div>
                    <flux:heading size="lg" level="2">{{ $this->earlierProposals->isEmpty() ? __('3. Which estimate was presented?') : __('New today') }}</flux:heading>
                    <flux:subheading>{{ __('Tick each estimate presented, and say what became of it.') }}</flux:subheading>
                </div>

                @forelse ($this->newEstimates as $estimate)
                    <div wire:key="report-estimate-{{ $estimate->id }}" class="flex flex-col gap-4 border-t border-zinc-200 pt-4 first:border-t-0 first:pt-0" data-test="report-estimate">
                        <flux:checkbox
                            wire:model.live="estimates.{{ $estimate->id }}.presented"
                            :label="__(':type estimate, :price', ['type' => $estimate->estimate_type->label(), 'price' => $range($estimate)])"
                            :description="$estimate->options->pluck('name')->implode(' · ')"
                            data-test="report-estimate-{{ $estimate->id }}"
                        />
                        <flux:error name="estimates.{{ $estimate->id }}.estimate" />

                        @if ($estimates[$estimate->id]['presented'] ?? false)
                            <div class="flex flex-col gap-1">
                                <flux:radio.group wire:model.live="estimates.{{ $estimate->id }}.result" :label="__('What became of it?')">
                                    @foreach ($results as $value => $label)
                                        <flux:radio :value="$value" :label="$label" wire:key="report-estimate-{{ $estimate->id }}-{{ $value }}" data-test="report-estimate-{{ $estimate->id }}-{{ $value }}" />
                                    @endforeach
                                </flux:radio.group>
                                <flux:error name="estimates.{{ $estimate->id }}.proposalResult" />
                            </div>

                            <x-proposal.result-fields
                                model="estimates.{{ $estimate->id }}"
                                error-prefix="estimates.{{ $estimate->id }}."
                                :result="$estimates[$estimate->id]['result'] ?? ''"
                                :values="$estimates[$estimate->id]"
                                :reasons="$this->lossReasons"
                                :objections="$this->objections"
                                :options="$estimate->options"
                            />
                        @endif
                    </div>
                @empty
                    <flux:text class="text-sm" data-test="report-no-estimates">
                        {{ $this->earlierProposals->isEmpty()
                            ? __('This lead has no estimate yet. Build it from the Estimates card on the lead, then come back to this report.')
                            : __('Nothing new: every estimate on this lead was presented before.') }}
                    </flux:text>
                @endforelse
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
