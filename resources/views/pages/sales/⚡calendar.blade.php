<?php

use App\Actions\Appointments\ReleaseCancelledLead;
use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Support\Proposals\ProposalValidity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sales > Calendar (docs/slice-6-appointments-plan.md, steps 6a and 6b): a
 * plain list of the appointments still to come, and under it the Leads whose
 * appointment was cancelled and that still wait to be booked again, and the
 * pending bids with how long each has left (slice 9, step 9a). It is not a
 * calendar and shows no free time.
 *
 * A manager sees every appointment at the Locations where they manage
 * appointments. Anyone else sees only their own, and only at a Location where
 * they may still see Leads. Location stays the boundary.
 */
new #[Title('Calendar')] class extends Component {
    /** The most appointments listed. More than this is said, not hidden. */
    public const int LIMIT = 200;

    public function mount(): void
    {
        $this->authorize(Permission::SalesAccess);
    }

    /**
     * @return Builder<Appointment>
     */
    private function upcoming(): Builder
    {
        $user = Auth::user();
        $managed = $user->locationsWhere(Permission::ManageAppointments)->modelKeys();
        $viewable = $user->locationsWhere(Permission::ViewLeads)->modelKeys();

        return Appointment::query()
            ->upcomingAt(now())
            ->where(function (Builder $visible) use ($user, $managed, $viewable): void {
                $visible
                    ->whereIn('location_id', $managed)
                    ->orWhere(fn (Builder $own) => $own->where('salesperson_id', $user->id)->whereIn('location_id', $viewable));
            });
    }

    #[Computed]
    public function total(): int
    {
        return $this->upcoming()->count();
    }

    /**
     * The appointments, soonest first, grouped by their day in their own
     * Location's time.
     *
     * @return Collection<string, Collection<int, Appointment>>
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->upcoming()
            ->with(['account', 'property', 'location'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->groupBy(fn (Appointment $appointment): string => $appointment->starts_at->copy()->timezone($appointment->timezone)->format('l, F j, Y'));
    }

    /**
     * The cancelled appointments of Leads that are still with their
     * salesperson and not yet booked again (rulebook, rule 8), longest-waiting
     * first. A manager sees every one at the Locations they manage; anyone
     * else sees the Leads that are theirs.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function toRebook(): Collection
    {
        $user = Auth::user();
        $managed = $user->locationsWhere(Permission::ManageAppointments)->modelKeys();
        $viewable = $user->locationsWhere(Permission::ViewLeads)->modelKeys();

        return Appointment::query()
            ->whereIn('state', [AppointmentState::Cancelled, AppointmentState::NoShow])
            // Only the Lead's latest appointment: an earlier cancellation that was already booked again is history.
            ->whereIn('id', Appointment::query()->selectRaw('max(id)')->groupBy('lead_id'))
            ->whereHas('lead', function (Builder $lead) use ($user, $managed, $viewable): void {
                $lead->where('status', LeadStatus::AppointmentCancelled)->where(function (Builder $visible) use ($user, $managed, $viewable): void {
                    $visible
                        ->whereIn('location_id', $managed)
                        ->orWhere(fn (Builder $own) => $own->where('assigned_user_id', $user->id)->whereIn('location_id', $viewable));
                });
            })
            ->with(['account', 'property', 'location', 'lead'])
            ->orderBy('state_changed_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Appointments whose time has come and that nobody has yet marked run or
     * no-show, oldest first (decision 125). An appointment stays operationally
     * incomplete until it has that answer (product-architecture.md, 5.6). Only
     * those whose Lead is still booked with Sales: the rest can no longer be
     * answered.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function toCloseOut(): Collection
    {
        $user = Auth::user();
        $managed = $user->locationsWhere(Permission::ManageAppointments)->modelKeys();
        $viewable = $user->locationsWhere(Permission::ViewLeads)->modelKeys();

        return Appointment::query()
            ->awaitingCloseOutAt(now())
            ->whereHas('lead', fn (Builder $lead) => $lead->where('status', LeadStatus::AppointmentBooked))
            ->where(function (Builder $visible) use ($user, $managed, $viewable): void {
                $visible
                    ->whereIn('location_id', $managed)
                    ->orWhere(fn (Builder $own) => $own->where('salesperson_id', $user->id)->whereIn('location_id', $viewable));
            })
            ->with(['account', 'property', 'location'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * How many days a cancelled Lead stays with its salesperson before it is
     * released to the Contact Center (decisions 116 and 119).
     */
    #[Computed]
    public function releaseDays(): int
    {
        return ContactCenterSettings::current()->cancelled_release_days;
    }

    /**
     * Leads the timer released to the Contact Center that can still be taken
     * back by the salesperson they were released from (rulebook, rule 12),
     * most recently released first. A salesperson sees their own; a manager
     * sees every one at the Locations they manage.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function released(): Collection
    {
        $user = Auth::user();
        $managed = $user->locationsWhere(Permission::ManageAppointments)->modelKeys();
        $viewable = $user->locationsWhere(Permission::ViewLeads)->modelKeys();

        return Lead::query()
            ->where('status', LeadStatus::Qualified)
            ->whereNotNull('released_from_user_id')
            ->where(function (Builder $visible) use ($user, $managed, $viewable): void {
                $visible
                    ->whereIn('location_id', $managed)
                    ->orWhere(fn (Builder $own) => $own->where('released_from_user_id', $user->id)->whereIn('location_id', $viewable));
            })
            ->with(['account', 'property', 'location', 'releasedFrom'])
            ->orderByDesc('released_from_sales_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Leads still with their salesperson after an appointment that was run,
     * with a proposal pending, soonest to expire first
     * (docs/slice-9-pending-rehash-plan.md, step 9a). A Lead leaves its
     * salesperson when the last of its pending proposals has expired, so each
     * is placed by that day. A manager sees every one at the Locations they
     * manage; anyone else sees the Leads that are theirs.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function pendingBids(): Collection
    {
        $user = Auth::user();
        $managed = $user->locationsWhere(Permission::ManageAppointments)->modelKeys();
        $viewable = $user->locationsWhere(Permission::ViewLeads)->modelKeys();
        $pending = fn ($proposals) => $proposals->counted()->where('result', ProposalResult::Pending);

        return Lead::query()
            ->where('status', LeadStatus::AppointmentRun)
            ->whereHas('proposals', $pending)
            ->where(function (Builder $visible) use ($user, $managed, $viewable): void {
                $visible
                    ->whereIn('location_id', $managed)
                    ->orWhere(fn (Builder $own) => $own->where('assigned_user_id', $user->id)->whereIn('location_id', $viewable));
            })
            ->with(['account', 'property', 'location', 'assignee', 'proposals' => $pending])
            ->withMax(['proposals as owned_until' => $pending], 'valid_until')
            ->orderBy('owned_until')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Whether the person sees other people's appointments, so each row should
     * say whose it is.
     */
    #[Computed]
    public function seesOthers(): bool
    {
        return Auth::user()->locationsWhere(Permission::ManageAppointments)->isNotEmpty();
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Calendar') }}</flux:heading>
        <flux:subheading>
            {{ $this->seesOthers
                ? __('Upcoming appointments at the locations you manage, soonest first. Each time is in its own location\'s time.')
                : __('Your upcoming appointments, soonest first. Each time is in its own location\'s time.') }}
        </flux:subheading>
    </div>

    @if ($this->toCloseOut->isNotEmpty())
        <div class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-5" data-test="calendar-to-close-out">
            <div>
                <flux:heading size="lg" level="2">{{ __('To close out') }}</flux:heading>
                <flux:subheading>{{ __('Appointments whose time has come. Open each one and say whether it was run or was a no-show.') }}</flux:subheading>
            </div>

            <ul class="divide-y divide-amber-200">
                @foreach ($this->toCloseOut as $waiting)
                    @php
                        $startsAt = $waiting->starts_at->copy()->timezone($waiting->timezone);
                    @endphp

                    <li wire:key="calendar-close-out-{{ $waiting->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="calendar-close-out">
                        <div class="min-w-0">
                            <div class="font-medium tabular-nums text-ink">{{ $startsAt->format('D, M j, Y') }} &middot; {{ $startsAt->format('g:i A T') }}</div>
                            <a href="{{ route('leads.show', $waiting->lead_id) }}" wire:navigate class="font-semibold text-wall-teal underline">
                                {{ $waiting->account->display_name }}
                            </a>
                            <div class="text-sm text-zinc-700">{{ $waiting->property->summary() }}</div>
                        </div>

                        <div class="text-right text-sm text-zinc-700">
                            <div>{{ $waiting->location->name }}</div>
                            @if ($this->seesOthers)
                                <div data-test="calendar-close-out-salesperson">{{ $waiting->salesperson_name }}</div>
                            @endif
                            <a href="{{ route('leads.report', [$waiting->lead_id, $waiting->id]) }}" wire:navigate class="font-semibold text-wall-teal underline" data-test="calendar-report-link">{{ __('After-action report') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->days->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="calendar-empty">
            <flux:text>{{ __('No upcoming appointments.') }}</flux:text>
        </div>
    @else
        @if ($this->total > self::LIMIT)
            <flux:text data-test="calendar-limited">{{ __('Showing the next :shown of :total appointments.', ['shown' => self::LIMIT, 'total' => $this->total]) }}</flux:text>
        @endif

        @foreach ($this->days as $day => $appointments)
            <div wire:key="calendar-day-{{ $loop->index }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="calendar-day">
                <flux:heading size="lg" level="2">{{ $day }}</flux:heading>

                <ul class="divide-y divide-zinc-200">
                    @foreach ($appointments as $appointment)
                        @php
                            $startsAt = $appointment->starts_at->copy()->timezone($appointment->timezone);
                            $endsAt = $appointment->ends_at->copy()->timezone($appointment->timezone);
                        @endphp

                        <li wire:key="calendar-appointment-{{ $appointment->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="calendar-appointment">
                            <div class="min-w-0">
                                <div class="font-medium tabular-nums text-ink">
                                    {{ $startsAt->format('g:i A') }} {{ __('to') }} {{ $endsAt->format('g:i A T') }}
                                </div>
                                <a href="{{ route('leads.show', $appointment->lead_id) }}" wire:navigate class="font-semibold text-wall-teal underline">
                                    {{ $appointment->account->display_name }}
                                </a>
                                <div class="text-sm text-zinc-700">{{ $appointment->property->summary() }}</div>
                            </div>

                            <div class="text-right text-sm text-zinc-700">
                                <div>{{ $appointment->location->name }}</div>
                                @if ($this->seesOthers)
                                    <div data-test="calendar-salesperson">{{ $appointment->salesperson_name }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif

    @if ($this->toRebook->isNotEmpty())
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="calendar-to-rebook">
            <div>
                <flux:heading size="lg" level="2">{{ __('To rebook') }}</flux:heading>
                <flux:subheading>{{ __('Leads whose appointment was cancelled and that are waiting for a new one, longest-waiting first.') }}</flux:subheading>
            </div>

            <ul class="divide-y divide-zinc-200">
                @foreach ($this->toRebook as $cancelled)
                    <li wire:key="calendar-rebook-{{ $cancelled->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="calendar-rebook">
                        <div class="min-w-0">
                            <a href="{{ route('leads.show', $cancelled->lead_id) }}" wire:navigate class="font-semibold text-wall-teal underline">
                                {{ $cancelled->account->display_name }}
                            </a>
                            <div class="text-sm text-zinc-700">{{ $cancelled->property->summary() }}</div>
                            <div class="text-sm text-zinc-700" data-test="calendar-rebook-cancelled">
                                {{ $cancelled->state === AppointmentState::NoShow ? __('No-show') : __('Cancelled') }}@if ($cancelled->state_changed_at !== null) <x-local-time :at="$cancelled->state_changed_at" />@endif
                                @if ($cancelled->cancellation_reason_name !== null)
                                    &middot; {{ $cancelled->cancellation_reason_name }}
                                @endif
                            </div>
                            @php($daysLeft = ReleaseCancelledLead::daysLeft($cancelled->lead, $this->releaseDays, now()))
                            <div @class(['text-sm', 'font-semibold text-ink' => $daysLeft <= ContactCenterSettings::RELEASE_WARNING_DAYS, 'text-zinc-700' => $daysLeft > ContactCenterSettings::RELEASE_WARNING_DAYS]) data-test="calendar-rebook-days-left">
                                {{ $daysLeft === 0
                                    ? __('Goes back to the Contact Center today.')
                                    : trans_choice('{1} Goes back to the Contact Center in 1 day unless it is booked again.|[2,*] Goes back to the Contact Center in :count days unless it is booked again.', $daysLeft, ['count' => $daysLeft]) }}
                            </div>
                        </div>

                        <div class="text-right text-sm text-zinc-700">
                            <div>{{ $cancelled->location->name }}</div>
                            @if ($this->seesOthers)
                                <div data-test="calendar-rebook-salesperson">{{ $cancelled->salesperson_name }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->pendingBids->isNotEmpty())
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="calendar-pending">
            <div>
                <flux:heading size="lg" level="2">{{ __('Pending bids') }}</flux:heading>
                <flux:subheading>{{ __('Leads with a proposal still pending, soonest to expire first. When the last one expires the lead goes to the Contact Center for rehash, unless a return visit is booked or it is sold or lost.') }}</flux:subheading>
            </div>

            <ul class="divide-y divide-zinc-200">
                @foreach ($this->pendingBids as $pendingLead)
                    {{-- Placed by the bid's own last day: the latest of what is pending. --}}
                    @php($latestPending = $pendingLead->proposals->sortBy('valid_until')->last())
                    @php($ownedUntil = $latestPending === null ? null : ProposalValidity::of($latestPending))
                    @php($daysLeft = $ownedUntil === null ? null : (int) ProposalValidity::today($pendingLead->location)->diffInDays($ownedUntil))
                    <li wire:key="calendar-pending-{{ $pendingLead->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="calendar-pending-lead">
                        <div class="min-w-0">
                            <a href="{{ route('leads.show', $pendingLead) }}" wire:navigate class="font-semibold text-wall-teal underline">
                                {{ $pendingLead->account?->display_name ?? $pendingLead->displayName() ?? __('Lead') }}
                            </a>
                            @if ($pendingLead->property !== null)
                                <div class="text-sm text-zinc-700">{{ $pendingLead->property->summary() }}</div>
                            @endif
                            @foreach ($pendingLead->proposals as $pendingProposal)
                                <div class="text-sm text-zinc-700" wire:key="calendar-pending-proposal-{{ $pendingProposal->id }}" data-test="calendar-pending-proposal">
                                    {{ $pendingProposal->name }}, {{ $pendingProposal->price() }}@if ($pendingProposal->objection_name !== null) &middot; {{ __('Main objection: :objection', ['objection' => $pendingProposal->objection_name]) }}@endif
                                </div>
                            @endforeach
                            @if ($ownedUntil !== null)
                                <div @class(['text-sm', 'font-semibold text-ink' => $daysLeft <= ContactCenterSettings::PENDING_WARNING_DAYS, 'text-zinc-700' => $daysLeft > ContactCenterSettings::PENDING_WARNING_DAYS]) data-test="calendar-pending-days-left">
                                    {{ ProposalValidity::label($ownedUntil, $pendingLead->location) }}
                                </div>
                            @endif
                        </div>

                        <div class="text-right text-sm text-zinc-700">
                            <div>{{ $pendingLead->location?->name }}</div>
                            @if ($this->seesOthers)
                                <div data-test="calendar-pending-salesperson">{{ $pendingLead->assignee?->name }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->released->isNotEmpty())
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="calendar-released">
            <div>
                <flux:heading size="lg" level="2">{{ $this->seesOthers ? __('Released to the Contact Center') : __('Released from you') }}</flux:heading>
                <flux:subheading>{{ __('Leads that were not booked again, sold or lost in time. The call center is working them. The salesperson a lead was released from can take it back from the lead if the customer comes back to them.') }}</flux:subheading>
            </div>

            <ul class="divide-y divide-zinc-200">
                @foreach ($this->released as $lead)
                    <li wire:key="calendar-released-{{ $lead->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="calendar-released-lead">
                        <div class="min-w-0">
                            <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-semibold text-wall-teal underline">
                                {{ $lead->account?->display_name ?? $lead->displayName() ?? __('No name given') }}
                            </a>
                            @if ($lead->property !== null)
                                <div class="text-sm text-zinc-700">{{ $lead->property->summary() }}</div>
                            @endif
                            <div class="text-sm text-zinc-700">
                                {{ __('Released') }}@if ($lead->released_from_sales_at !== null) <x-local-time :at="$lead->released_from_sales_at" />@endif
                            </div>
                        </div>

                        <div class="text-right text-sm text-zinc-700">
                            <div>{{ $lead->location?->name }}</div>
                            @if ($this->seesOthers)
                                <div data-test="calendar-released-from">{{ $lead->releasedFrom?->name }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
