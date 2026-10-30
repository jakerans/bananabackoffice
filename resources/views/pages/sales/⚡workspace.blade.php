<?php

use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\Estimate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sales > Workspace > Today (docs/slice-34-sales-workspace-plan.md, step
 * 34a; decision 212): the salesperson's day. Their appointments today, run,
 * going on or still to come, then the next seven days; each with the
 * customer, the address and the time, each estimate on the Lead and where it
 * stands, whether a report is due, and one tap to the estimate, the
 * presentation or the report. Reports still due from earlier days come
 * first.
 *
 * Anyone in Sales sees their own appointments at the Locations where they may
 * see Leads; a manager sees anyone's at the Locations where they manage
 * appointments, and chooses whose day it is, or everyone's. As Sales >
 * Calendar.
 */
new #[Title('Workspace')] class extends Component {
    /** How many days after today are shown (decision 212, question 1). */
    public const int DAYS_AHEAD = 7;

    /** Whose day a manager is looking at: a salesperson's id, or "everyone". Empty for their own. */
    #[Url(except: '')]
    public string $who = '';

    public function mount(): void
    {
        $this->authorize(Permission::SalesAccess);
    }

    #[Computed]
    public function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function managed(): array
    {
        return $this->user->locationsWhere(Permission::ManageAppointments)->modelKeys();
    }

    /**
     * Whose appointments are shown: one salesperson, or null for everyone at the Locations managed.
     */
    #[Computed]
    public function salespersonId(): ?int
    {
        if ($this->managed === []) {
            return $this->user->id;
        }

        return match (true) {
            $this->who === 'everyone' => null,
            ctype_digit($this->who) => (int) $this->who,
            default => $this->user->id,
        };
    }

    /**
     * The appointments this person may see.
     *
     * @return Builder<Appointment>
     */
    private function visible(): Builder
    {
        $managed = $this->managed;
        $viewable = $this->user->locationsWhere(Permission::ViewLeads)->modelKeys();
        $user = $this->user;

        return Appointment::query()
            ->where(function (Builder $visible) use ($user, $managed, $viewable): void {
                $visible
                    ->whereIn('location_id', $managed)
                    ->orWhere(fn (Builder $own) => $own->where('salesperson_id', $user->id)->whereIn('location_id', $viewable));
            })
            ->when($this->salespersonId !== null, fn (Builder $query) => $query->where('salesperson_id', $this->salespersonId));
    }

    /**
     * The salespeople a manager can choose from: those with appointments in these days at the Locations they manage.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function people(): Collection
    {
        if ($this->managed === []) {
            return collect();
        }

        return Appointment::query()
            ->whereIn('location_id', $this->managed)
            ->whereNotNull('salesperson_id')
            ->whereBetween('starts_at', [now()->subDays(2), now()->addDays(self::DAYS_AHEAD + 2)])
            ->orderBy('salesperson_name')
            ->pluck('salesperson_name', 'salesperson_id')
            ->put($this->user->id, $this->user->name)
            ->sort();
    }

    /**
     * Today's appointments and the next seven days', grouped by their day in
     * their own Location's time. Today keeps the ones already run or missed;
     * later days only the ones that still stand.
     *
     * @return Collection<string, Collection<int, Appointment>>
     */
    #[Computed]
    public function days(): Collection
    {
        $appointments = $this->visible()
            ->whereIn('state', [AppointmentState::Booked, AppointmentState::Run, AppointmentState::NoShow])
            ->whereBetween('starts_at', [now()->subDay(), now()->addDays(self::DAYS_AHEAD + 1)])
            ->with(['account', 'property', 'location', 'lead.discovery', 'lead.estimates.options', 'lead.estimates.proposal.agreement', 'lead.estimates.proposal.option'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return $appointments
            ->filter(function (Appointment $appointment): bool {
                $offset = (int) $this->today($appointment)->diffInDays($appointment->starts_at->copy()->timezone($appointment->timezone)->startOfDay());

                return $offset >= 0 && $offset <= self::DAYS_AHEAD && ($offset === 0 || $appointment->stands());
            })
            ->groupBy(fn (Appointment $appointment): string => $appointment->starts_at->copy()->timezone($appointment->timezone)->toDateString())
            ->sortKeys();
    }

    /**
     * Reports still due from before today: appointments nobody has marked run
     * or no-show, and the rest of a report after the customer signed.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function earlierReports(): Collection
    {
        return $this->visible()
            ->where(function (Builder $due): void {
                $due->where(fn (Builder $waiting) => $waiting->awaitingCloseOutAt(now())->whereHas('lead', fn (Builder $lead) => $lead->where('status', LeadStatus::AppointmentBooked)))
                    ->orWhere(fn (Builder $rest) => $rest->where('state', AppointmentState::Run)->where('rest_of_report_due', true));
            })
            ->with(['account', 'property', 'location'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Appointment $appointment): bool => $appointment->starts_at->copy()->timezone($appointment->timezone)->startOfDay()->lt($this->today($appointment)))
            ->values();
    }

    /**
     * The start of today where the appointment is.
     */
    private function today(Appointment $appointment): CarbonInterface
    {
        return now()->timezone($appointment->timezone)->startOfDay();
    }

    /**
     * How the day's heading reads: Today, Tomorrow, or the day and date.
     */
    public function dayLabel(string $date, Appointment $first): string
    {
        $offset = (int) $this->today($first)->diffInDays(Date::parse($date, $first->timezone)->startOfDay());

        return match ($offset) {
            0 => __('Today'),
            1 => __('Tomorrow'),
            default => Date::parse($date)->format('l, F j'),
        };
    }

    /**
     * Where the appointment stands, and the badge's colour.
     *
     * @return array{0: string, 1: string}
     */
    public function standing(Appointment $appointment): array
    {
        $now = now();

        return match (true) {
            $appointment->state === AppointmentState::Run && $appointment->rest_of_report_due => [__('Signed. Finish the report'), 'amber'],
            $appointment->state === AppointmentState::Run => [__('Run'), 'zinc'],
            $appointment->state === AppointmentState::NoShow => [__('No-show'), 'zinc'],
            $appointment->starts_at->gt($now) => [__('Upcoming'), 'sky'],
            $appointment->ends_at->gt($now) => [__('Going on now'), 'green'],
            default => [__('Report due'), 'amber'],
        };
    }

    /**
     * Whether the appointment's report is waiting: the whole of it, or the rest after signing.
     */
    public function reportDue(Appointment $appointment): bool
    {
        return ($appointment->stands() && $appointment->starts_at->lte(now()) && $appointment->lead?->status === LeadStatus::AppointmentBooked)
            || ($appointment->state === AppointmentState::Run && $appointment->rest_of_report_due);
    }

    /**
     * Where an estimate stands, and the badge's colour.
     *
     * @return array{0: string, 1: string}
     */
    public function estimateStage(Estimate $estimate): array
    {
        $proposal = $estimate->proposal;

        return match (true) {
            $proposal?->result === ProposalResult::Sold && $proposal->agreement !== null => [__('Signed: :option, :amount', ['option' => $proposal->option?->name ?? __('an option'), 'amount' => $proposal->soldFor()]), 'emerald'],
            $proposal?->result === ProposalResult::Sold => [__('Sold: :option, :amount', ['option' => $proposal->option?->name ?? __('an option'), 'amount' => $proposal->soldFor()]), 'emerald'],
            $proposal?->result === ProposalResult::Lost => [__('Lost'), 'rose'],
            $proposal !== null => [__('Presented, pending'), 'amber'],
            (int) $estimate->options->max('total_cents') <= 0 => [__('Not priced yet'), 'zinc'],
            default => [__('Built, not presented'), 'sky'],
        };
    }

    /**
     * The estimate's price, or its options' range.
     */
    public function range(Estimate $estimate): string
    {
        $from = (int) $estimate->options->min('total_cents');
        $to = (int) $estimate->options->max('total_cents');

        return $from === $to ? Money::format($to) : __(':from to :to', ['from' => Money::format($from), 'to' => Money::format($to)]);
    }
}; ?>

<section class="mx-auto flex w-full flex-col gap-6" data-test="sales-workspace">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Today') }}</flux:heading>
            <flux:subheading>{{ __('Your appointments today, then the next :days days, with each estimate and what is left to do.', ['days' => $this::DAYS_AHEAD]) }}</flux:subheading>
        </div>

        @if ($this->managed !== [])
            <flux:select wire:model.live="who" :label="__('Whose day')" class="min-w-56" data-test="workspace-who">
                <flux:select.option value="">{{ __('Mine') }}</flux:select.option>
                <flux:select.option value="everyone">{{ __('Everyone at my Locations') }}</flux:select.option>
                @foreach ($this->people as $id => $name)
                    @if ($id !== $this->user->id)
                        <flux:select.option :value="(string) $id">{{ $name }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->earlierReports->isNotEmpty())
        <div class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4" data-test="earlier-reports">
            <flux:heading level="2">{{ __('Reports still due from earlier days') }}</flux:heading>
            <ul class="flex flex-col gap-2">
                @foreach ($this->earlierReports as $due)
                    <li wire:key="earlier-{{ $due->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm text-ink">
                        <span>
                            <span class="font-medium">{{ $due->account?->display_name ?? __('Lead #:id', ['id' => $due->lead_id]) }}</span>
                            &middot; {{ $due->starts_at->copy()->timezone($due->timezone)->format('D, M j \a\t g:i A') }}
                            @if ($this->salespersonId === null) &middot; {{ $due->salesperson_name }} @endif
                        </span>
                        <flux:button size="sm" variant="primary" :href="route('leads.report', [$due->lead_id, $due->id])" wire:navigate>
                            {{ $due->rest_of_report_due ? __('Finish the report') : __('After-action report') }}
                        </flux:button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @forelse ($this->days as $date => $appointments)
        <div class="flex flex-col gap-3" wire:key="day-{{ $date }}" data-test="workspace-day-{{ $date }}">
            <flux:heading size="lg" level="2">{{ $this->dayLabel($date, $appointments->first()) }}</flux:heading>

            @foreach ($appointments as $appointment)
                @php
                    $lead = $appointment->lead;
                    [$standing, $standingColor] = $this->standing($appointment);
                    $local = $appointment->starts_at->copy()->timezone($appointment->timezone);
                @endphp
                <article wire:key="appointment-{{ $appointment->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-4 sm:p-5" data-test="workspace-appointment-{{ $appointment->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-nav text-lg font-bold tabular-nums text-ink">{{ $local->format('g:i A') }}</span>
                                <flux:badge size="sm" :color="$standingColor" data-test="appointment-standing">{{ $standing }}</flux:badge>
                            </div>
                            <flux:link :href="route('leads.show', $appointment->lead_id)" wire:navigate class="mt-1 block text-base font-medium">
                                {{ $appointment->account?->display_name ?? $lead?->displayName() ?? __('Lead #:id', ['id' => $appointment->lead_id]) }}
                            </flux:link>
                            @if ($appointment->property !== null)
                                <flux:text class="text-sm">{{ $appointment->property->summary() }}</flux:text>
                            @endif
                            @if ($this->salespersonId === null)
                                <flux:text class="text-sm">{{ $appointment->salesperson_name }}</flux:text>
                            @endif
                        </div>

                        @if ($this->reportDue($appointment))
                            <flux:button size="sm" variant="primary" :href="route('leads.report', [$appointment->lead_id, $appointment->id])" wire:navigate data-test="workspace-report-{{ $appointment->id }}">
                                {{ $appointment->rest_of_report_due ? __('Finish the report') : __('After-action report') }}
                            </flux:button>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2 border-t border-zinc-200 pt-3">
                        @forelse ($lead?->estimates ?? [] as $estimate)
                            @php([$stage, $stageColor] = $this->estimateStage($estimate))
                            <div wire:key="workspace-estimate-{{ $estimate->id }}" class="flex flex-wrap items-center justify-between gap-2" data-test="workspace-estimate-{{ $estimate->id }}">
                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-ink">{{ __(':type estimate', ['type' => $estimate->estimate_type->label()]) }}</span>
                                    @if ((int) $estimate->options->max('total_cents') > 0)
                                        <span class="text-sm tabular-nums text-zinc-700">{{ $this->range($estimate) }}</span>
                                    @endif
                                    <flux:badge size="sm" :color="$stageColor" data-test="estimate-stage">{{ $stage }}</flux:badge>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" :href="route('leads.estimate', [$appointment->lead_id, $estimate])" wire:navigate>{{ __('Open') }}</flux:button>
                                    @if ((int) $estimate->options->max('total_cents') > 0 && ! $estimate->isSettled())
                                        <flux:button size="sm" variant="primary" :href="route('leads.estimate.present', [$appointment->lead_id, $estimate])" wire:navigate data-test="workspace-present-{{ $estimate->id }}">{{ __('Present') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-wrap items-center justify-between gap-2" data-test="workspace-no-estimate">
                                <flux:text class="text-sm">{{ __('No estimate yet.') }}</flux:text>
                                <flux:button size="sm" :href="route('leads.show', $appointment->lead_id)" wire:navigate>{{ __('Start one on the lead') }}</flux:button>
                            </div>
                        @endforelse

                        {{-- Discovery, written during the appointment (step 34b). --}}
                        <div class="flex flex-wrap items-center justify-between gap-2" data-test="workspace-discovery-{{ $appointment->id }}">
                            <flux:text class="text-sm">{{ __('Discovery: :count of 5 written down', ['count' => count($lead?->discovery?->answered() ?? [])]) }}</flux:text>
                            <flux:button size="sm" variant="ghost" :href="route('leads.show', $appointment->lead_id).'#discovery'" wire:navigate>{{ __('Discovery') }}</flux:button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @empty
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center" data-test="workspace-empty">
            <flux:heading size="lg">{{ __('No appointments today or in the next :days days', ['days' => $this::DAYS_AHEAD]) }}</flux:heading>
            <flux:text class="max-w-md">{{ __('Appointments booked with you show here, with their estimates.') }}</flux:text>
        </div>
    @endforelse
</section>
