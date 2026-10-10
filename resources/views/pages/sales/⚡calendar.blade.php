<?php

use App\Enums\Permission;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sales > Calendar (docs/slice-6-appointments-plan.md, step 6a): a plain list
 * of the appointments still to come. It is not a calendar and shows no free
 * time.
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
</section>
