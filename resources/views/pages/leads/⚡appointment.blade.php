<?php

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use App\Support\Queue\CallingHours;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Appointment card on a Lead (docs/slice-6-appointments-plan.md, step 6a):
 * book an appointment, see the one that stands, and let a manager undo one
 * that was booked in error. The Lead page is told when anything changes.
 */
new class extends Component {
    #[Locked]
    public int $leadId;

    public ?int $salespersonId = null;

    public string $appointmentDate = '';

    public string $appointmentTime = '';

    public int|string $appointmentMinutes = Appointment::DEFAULT_MINUTES;

    public string $bookedInErrorNote = '';

    /** The Lead's lifecycle revision when a dialog was opened, so a save refuses a Lead that has changed since. */
    #[Locked]
    public int $knownLifecycleVersion = 0;

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->leadId = $lead->id;
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with(['location', 'account', 'property', 'assignee'])->findOrFail($this->leadId);
    }

    /**
     * The appointment that stands, or failing that the most recent one, so a
     * booking that was undone still shows what happened.
     */
    #[Computed]
    public function appointment(): ?Appointment
    {
        return $this->lead->standingAppointment() ?? $this->lead->appointments()->first();
    }

    /**
     * What is missing before this Lead can be booked, in words.
     *
     * @return list<string>
     */
    #[Computed]
    public function gaps(): array
    {
        return array_map(fn (string $gap): string => match ($gap) {
            'qualified' => __('Qualify this lead first.'),
            'location' => __('Send this lead to a location first.'),
            'customer' => __('Link this lead to a customer first.'),
            'property' => __('Link this lead to a property at its customer first.'),
        }, $this->lead->bookingGaps());
    }

    /**
     * The people an appointment may be booked with: Sales Consultants at the
     * Lead's Location who can work it. A Sales Consultant booking their own
     * Lead may choose only themselves (decision 89).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function salespeople(): Collection
    {
        $lead = $this->lead;
        $location = $lead->location;

        if ($location === null) {
            return new Collection;
        }

        $actor = Auth::user();
        $onlyThemselves = ! $actor->hasPermissionAt(Permission::BookAppointments, $location);

        return User::query()
            ->where('is_active', true)
            ->when($onlyThemselves, fn ($users) => $users->whereKey($actor->id))
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::SalesConsultant, $location) && $user->canBeAssigned($lead))
            ->values();
    }

    /**
     * The Location's timezone and today's date there, for the booking dialog.
     *
     * @return array{name: string, today: string}
     */
    #[Computed]
    public function localTime(): array
    {
        $now = now()->timezone(CallingHours::timezoneFor($this->lead->location));

        return ['name' => $now->format('T').' ('.$now->timezoneName.')', 'today' => $now->toDateString()];
    }

    /**
     * The lengths that may be chosen, in minutes, with how each reads.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function lengths(): array
    {
        $lengths = [];

        for ($minutes = Appointment::MINIMUM_MINUTES; $minutes <= Appointment::MAXIMUM_MINUTES; $minutes += Appointment::STEP_MINUTES) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            $lengths[$minutes] = trim(($hours > 0 ? trans_choice('{1} 1 hour|[2,*] :count hours', $hours, ['count' => $hours]) : '').' '.($rest > 0 ? __(':count minutes', ['count' => $rest]) : ''));
        }

        return $lengths;
    }

    public function openBook(): void
    {
        $this->authorize('bookAppointment', $this->lead);

        $this->reset('salespersonId', 'appointmentDate', 'appointmentTime', 'appointmentMinutes');
        $this->resetValidation();

        // With one person to choose from, they are chosen.
        if ($this->salespeople->count() === 1) {
            $this->salespersonId = $this->salespeople->first()?->id;
        }

        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('book-appointment')->show();
    }

    /**
     * Book the appointment. The action checks everything again on the Lead as
     * it is at that moment, and saves all of it or none.
     */
    public function book(BookAppointment $bookAppointment): void
    {
        $this->authorize('bookAppointment', $this->lead);

        $this->validate([
            'salespersonId' => ['required', 'integer'],
            'appointmentDate' => ['required', 'string'],
            'appointmentTime' => ['required', 'string'],
            'appointmentMinutes' => ['required', 'integer'],
        ], [
            'salespersonId.required' => __('Choose the salesperson.'),
            'appointmentDate.required' => __('Choose the day of the appointment.'),
            'appointmentTime.required' => __('Choose the time of the appointment.'),
            'appointmentMinutes.required' => __('Choose how long the appointment is.'),
        ]);

        // Only a person this page offered may be booked.
        $salesperson = $this->salespeople->firstWhere('id', $this->salespersonId);

        if ($salesperson === null) {
            $this->addError('salespersonId', __('Choose one of the people listed.'));

            return;
        }

        $result = $bookAppointment->handle(
            $this->lead,
            $salesperson,
            $this->appointmentDate,
            substr($this->appointmentTime, 0, 5),
            (int) $this->appointmentMinutes,
            Auth::user(),
            $this->knownLifecycleVersion,
        );

        $this->changed();

        Flux::modal('book-appointment')->close();
        Flux::toast(variant: 'success', text: __('Appointment booked with :name.', ['name' => $salesperson->name]));

        if (! $result->emailSent) {
            Flux::toast(variant: 'warning', duration: 0, text: __('The appointment is saved and :name has a notice in Backoffice, but their email could not be sent.', ['name' => $salesperson->name]));
        }
    }

    public function openBookedInError(): void
    {
        $this->authorize('manageAppointment', $this->lead);

        $this->bookedInErrorNote = '';
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('appointment-booked-in-error')->show();
    }

    /**
     * Undo the standing appointment as "booked in error", with the note.
     */
    public function markBookedInError(MarkAppointmentBookedInError $markAppointmentBookedInError): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $appointment = $this->lead->standingAppointment();

        if ($appointment === null) {
            $this->changed();
            Flux::modal('appointment-booked-in-error')->close();

            return;
        }

        $markAppointmentBookedInError->handle($this->lead, $appointment, $this->bookedInErrorNote, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('appointment-booked-in-error')->close();
        Flux::toast(variant: 'success', text: __('The appointment is marked booked in error and the lead is back as it was.'));
    }

    /**
     * Read the Lead again here, and tell the Lead page to do the same.
     */
    private function changed(): void
    {
        unset($this->lead, $this->appointment, $this->gaps, $this->salespeople);

        $this->dispatch('lead-appointment-changed');
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-appointment">
    <flux:heading size="lg" level="2">{{ __('Appointment') }}</flux:heading>

    @php
        $lead = $this->lead;
        $appointment = $this->appointment;
    @endphp

    @if ($appointment !== null)
        @php
            $startsAt = $appointment->starts_at->copy()->timezone($appointment->timezone);
            $endsAt = $appointment->ends_at->copy()->timezone($appointment->timezone);
        @endphp

        <dl class="flex flex-col gap-4">
            <div>
                <dt class="text-sm text-zinc-500">{{ __('When') }}</dt>
                <dd class="text-ink" data-test="appointment-when">
                    {{ $startsAt->format('D, M j, Y') }}<br>
                    {{ $startsAt->format('g:i A') }} {{ __('to') }} {{ $endsAt->format('g:i A T') }}
                </dd>
                <dd class="text-sm text-zinc-500">{{ __('Location time, :location', ['location' => $appointment->location->name]) }}</dd>
            </div>

            <div>
                <dt class="text-sm text-zinc-500">{{ __('Salesperson') }}</dt>
                <dd class="text-ink" data-test="appointment-salesperson">{{ $appointment->salesperson_name }}</dd>
            </div>

            <div>
                <dt class="text-sm text-zinc-500">{{ __('Booked by') }}</dt>
                <dd class="text-ink" data-test="appointment-booked-by">
                    {{ $appointment->booked_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$appointment->created_at" />
                </dd>
            </div>
        </dl>

        @if ($appointment->stands())
            @if ($lead->assigned_user_id !== $appointment->salesperson_id)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="appointment-needs-salesperson">
                    <flux:callout.text>{{ __(':name is no longer working this lead, so this appointment needs a salesperson. A manager can undo it and book it again.', ['name' => $appointment->salesperson_name]) }}</flux:callout.text>
                </flux:callout>
            @endif

            @can('manageAppointment', $lead)
                <div>
                    <flux:button size="sm" wire:click="openBookedInError" data-test="appointment-booked-in-error">{{ __('Booked in error') }}</flux:button>
                </div>
            @endcan
        @else
            <flux:callout icon="information-circle" data-test="appointment-undone">
                <flux:callout.heading>{{ $appointment->state->label() }}</flux:callout.heading>
                <flux:callout.text>
                    {{ $appointment->state_changed_by_name ?? __('Unknown') }}@if ($appointment->state_changed_at !== null) &middot; <x-local-time :at="$appointment->state_changed_at" />@endif
                    @if ($appointment->state_note !== null)
                        <span class="mt-1 block whitespace-pre-line">&ldquo;{{ $appointment->state_note }}&rdquo;</span>
                    @endif
                </flux:callout.text>
            </flux:callout>
        @endif
    @endif

    @if ($appointment === null || ! $appointment->stands())
        @if ($this->gaps !== [])
            <ul class="flex flex-col gap-1 text-sm text-zinc-700" data-test="appointment-gaps">
                <li class="text-zinc-500">{{ __('No appointment can be booked yet.') }}</li>
                @foreach ($this->gaps as $gap)
                    <li wire:key="appointment-gap-{{ $loop->index }}">{{ $gap }}</li>
                @endforeach
            </ul>
        @else
            @can('bookAppointment', $lead)
                <div>
                    <flux:button variant="primary" wire:click="openBook" data-test="book-appointment">{{ __('Book appointment') }}</flux:button>
                </div>
            @else
                <flux:text data-test="appointment-not-yours">{{ __('This lead is ready for an appointment. A manager or the call center books it.') }}</flux:text>
            @endcan
        @endif
    @endif

    @can('bookAppointment', $lead)
        <flux:modal name="book-appointment" class="w-full max-w-lg">
            <form wire:submit="book" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <flux:error name="booking" />

                <div>
                    <flux:heading size="lg">{{ __('Book an appointment') }}</flux:heading>
                    <flux:subheading>{{ __('Saving hands this lead to the salesperson. They are told in Backoffice and by email, and the lead leaves the call queue.') }}</flux:subheading>
                </div>

                <flux:select wire:model="salespersonId" :label="__('Salesperson')" data-test="appointment-salesperson-choice">
                    <flux:select.option value="">{{ __('Choose a salesperson') }}</flux:select.option>
                    @foreach ($this->salespeople as $person)
                        <flux:select.option :value="$person->id" wire:key="salesperson-{{ $person->id }}">{{ $person->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->salespeople->isEmpty())
                    <flux:text data-test="appointment-no-salespeople">{{ __('Nobody has Sales Consultant access at :location yet.', ['location' => $lead->location?->name]) }}</flux:text>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="appointmentDate" type="date" :min="$this->localTime['today']" :label="__('Day')" data-test="appointment-date" />
                    <flux:input wire:model="appointmentTime" type="time" step="900" :label="__('Time')" data-test="appointment-time" />
                </div>

                <flux:text class="text-sm" data-test="appointment-timezone">{{ __('In :location time: :timezone.', ['location' => $lead->location?->name, 'timezone' => $this->localTime['name']]) }}</flux:text>

                <flux:select wire:model="appointmentMinutes" :label="__('Length')" data-test="appointment-length">
                    @foreach ($this->lengths as $minutes => $label)
                        <flux:select.option :value="$minutes" wire:key="length-{{ $minutes }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-appointment">{{ __('Book appointment') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @can('manageAppointment', $lead)
        <x-confirmation-modal
            name="appointment-booked-in-error"
            :title="__('Mark this appointment booked in error?')"
            :description="__('The appointment is kept on record as booked in error. The lead goes back exactly as it was before the booking, and it does not count as issued.')"
            action="markBookedInError"
            :confirm-label="__('Booked in error')"
            :error="['stale']"
        >
            <flux:textarea wire:model="bookedInErrorNote" rows="3" :label="__('Why it was booked in error (required)')" data-test="booked-in-error-note" />
        </x-confirmation-modal>
    @endcan
</div>
