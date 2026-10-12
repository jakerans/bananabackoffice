<?php

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Appointments\CorrectAppointmentCloseOut;
use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Actions\Appointments\MoveAppointment;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Appointments\TakeLeadBackFromContactCenter;
use App\Enums\AppointmentState;
use App\Enums\CancellationParty;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AppointmentCancellationReason;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\User;
use App\Support\Queue\CallingHours;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Appointment card on a Lead (docs/slice-6-appointments-plan.md, steps 6a
 * and 6b): book an appointment and see the one that stands; give it a new
 * time or another salesperson; record that it was cancelled and book the Lead
 * again; send the Lead back to Contact Center; and let a manager undo one that
 * was booked in error. The Lead page is told when anything changes.
 */
new class extends Component {
    #[Locked]
    public int $leadId;

    public ?int $salespersonId = null;

    public string $appointmentDate = '';

    public string $appointmentTime = '';

    public int|string $appointmentMinutes = Appointment::DEFAULT_MINUTES;

    public string $bookedInErrorNote = '';

    public ?int $newSalespersonId = null;

    public string $moveNote = '';

    public string $cancelledBy = '';

    public ?int $cancellationReasonId = null;

    public string $cancellationNote = '';

    /** "yes" or "no" once answered, for a reason that asks whether the Lead is disqualified. */
    public string $cancellationDisqualifies = '';

    public ?int $disqualificationReasonId = null;

    /** One of the StopCountingLeadAsIssued answers, once chosen. */
    public string $issuedCount = '';

    public string $sendBackNote = '';

    public string $takeBackNote = '';

    /** Which answer the close-out dialog was opened for: run, or no-show. */
    #[Locked]
    public string $closeOutOutcome = '';

    public string $closeOutNote = '';

    public string $correctionNote = '';

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
        return Lead::query()->with(['location', 'account', 'property', 'assignee'])->findOrFail($this->leadId);
    }

    /**
     * The appointment that stands, or failing that the most recent one, so a
     * booking that was cancelled or undone still shows what happened.
     */
    #[Computed]
    public function appointment(): ?Appointment
    {
        return $this->lead->standingAppointment() ?? $this->lead->appointments()->first();
    }

    /**
     * The Lead's other appointments, newest first: the record of what was
     * cancelled, undone or has passed before the one shown.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function earlierAppointments(): Collection
    {
        return $this->lead->appointments()->when($this->appointment !== null, fn ($appointments) => $appointments->whereKeyNot($this->appointment->id))->get();
    }

    /**
     * The appointment whose time has come and that is still waiting to be
     * marked run or no-show: the oldest one, if there are several. Only while
     * the Lead is still booked with Sales.
     */
    #[Computed]
    public function toCloseOut(): ?Appointment
    {
        if ($this->lead->status !== LeadStatus::AppointmentBooked) {
            return null;
        }

        return $this->lead->appointments()->awaitingCloseOutAt(now())->reorder('starts_at')->orderBy('id')->first();
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
     * Whether the signed-in person is a manager for this Lead's appointments
     * (decision 88).
     */
    #[Computed]
    public function manages(): bool
    {
        return Auth::user()->can('manageAppointment', $this->lead);
    }

    /**
     * The Sales Consultants at the Lead's Location who can work it.
     *
     * @return Collection<int, User>
     */
    private function salesConsultants(): Collection
    {
        $lead = $this->lead;
        $location = $lead->location;

        if ($location === null) {
            return new Collection;
        }

        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::SalesConsultant, $location) && $user->canBeAssigned($lead))
            ->values();
    }

    /**
     * The people an appointment may be booked with. A Sales Consultant booking
     * their own Lead may choose only themselves (decision 89). While the Lead
     * is still with Sales, only a manager may choose anyone but the
     * salesperson it is with (decision 109).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function salespeople(): Collection
    {
        $lead = $this->lead;
        $actor = Auth::user();

        if ($lead->location === null) {
            return new Collection;
        }

        $people = $this->salesConsultants();

        if (! $actor->hasPermissionAt(Permission::BookAppointments, $lead->location)) {
            $people = $people->where('id', $actor->id)->values();
        }

        if ($lead->isWithSales() && ! $this->manages) {
            $people = $people->where('id', $lead->assigned_user_id)->values();
        }

        return $people;
    }

    /**
     * The people an upcoming appointment may be moved to: every Sales
     * Consultant there but the one who has it now.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function otherSalespeople(): Collection
    {
        $lead = $this->lead;
        $appointment = $this->appointment;

        return $this->salesConsultants()
            ->reject(fn (User $user): bool => $appointment !== null && $user->id === $appointment->salesperson_id && $lead->isAssignedTo($user))
            ->values();
    }

    /**
     * @return Collection<int, AppointmentCancellationReason>
     */
    #[Computed]
    public function cancellationReasons(): Collection
    {
        return AppointmentCancellationReason::query()->active()->ordered()->get();
    }

    #[Computed]
    public function chosenCancellationReason(): ?AppointmentCancellationReason
    {
        return $this->cancellationReasons->firstWhere('id', $this->cancellationReasonId);
    }

    /**
     * The reasons a Lead may be disqualified for while its cancellation is
     * recorded. A duplicate needs its original named, which the Lead page's
     * own Disqualify does.
     *
     * @return Collection<int, LeadDisqualificationReason>
     */
    #[Computed]
    public function disqualificationReasons(): Collection
    {
        return LeadDisqualificationReason::query()->active()->ordered()->get()
            ->reject(fn (LeadDisqualificationReason $reason): bool => $reason->isDuplicate() || $reason->isUnableToContact())
            ->values();
    }

    /**
     * The Location's timezone and today's date there, for the dialogs.
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

        // With one person to choose from, they are chosen. A Lead still with Sales starts on its salesperson.
        if ($this->salespeople->count() === 1) {
            $this->salespersonId = $this->salespeople->first()?->id;
        } elseif ($this->lead->isWithSales() && $this->salespeople->contains('id', $this->lead->assigned_user_id)) {
            $this->salespersonId = $this->lead->assigned_user_id;
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
        $this->validateTime(withSalesperson: true);

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

    public function openReschedule(): void
    {
        $appointment = $this->upcoming();

        $this->authorize('rescheduleAppointment', [$this->lead, $appointment]);

        $startsAt = $appointment->starts_at->copy()->timezone(CallingHours::timezoneFor($this->lead->location));

        $this->appointmentDate = $startsAt->toDateString();
        $this->appointmentTime = $startsAt->format('H:i');
        $this->appointmentMinutes = $appointment->duration_minutes;
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('reschedule-appointment')->show();
    }

    /**
     * Give the upcoming appointment a new time.
     */
    public function reschedule(RescheduleAppointment $rescheduleAppointment): void
    {
        $appointment = $this->upcoming();

        $this->authorize('rescheduleAppointment', [$this->lead, $appointment]);
        $this->validateTime(withSalesperson: false);

        $result = $rescheduleAppointment->handle(
            $this->lead,
            $appointment,
            $this->appointmentDate,
            substr($this->appointmentTime, 0, 5),
            (int) $this->appointmentMinutes,
            Auth::user(),
            $this->knownLifecycleVersion,
        );

        $this->changed();

        Flux::modal('reschedule-appointment')->close();
        Flux::toast(variant: 'success', text: __('The appointment has its new time.'));

        if (! $result->emailSent) {
            Flux::toast(variant: 'warning', duration: 0, text: __('The new time is saved and :name has a notice in Backoffice, but their email could not be sent.', ['name' => $appointment->salesperson_name]));
        }
    }

    public function openMove(): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->upcoming();

        $this->reset('newSalespersonId', 'moveNote');
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('move-appointment')->show();
    }

    /**
     * Give the upcoming appointment to another salesperson.
     */
    public function move(MoveAppointment $moveAppointment): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $appointment = $this->upcoming();

        // Only a person this page offered may be given it.
        $salesperson = $this->otherSalespeople->firstWhere('id', $this->newSalespersonId);

        if ($salesperson === null) {
            $this->addError('newSalespersonId', __('Choose one of the people listed.'));

            return;
        }

        $result = $moveAppointment->handle($this->lead, $appointment, $salesperson, $this->moveNote, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('move-appointment')->close();
        Flux::toast(variant: 'success', text: __('The appointment is now with :name.', ['name' => $salesperson->name]));

        if (! $result->emailSent) {
            Flux::toast(variant: 'warning', duration: 0, text: __('The move is saved and :name has a notice in Backoffice, but their email could not be sent.', ['name' => $salesperson->name]));
        }
    }

    public function openCancel(): void
    {
        $this->authorize('cancelAppointment', $this->lead);
        $this->upcoming();

        $this->reset('cancelledBy', 'cancellationReasonId', 'cancellationNote', 'cancellationDisqualifies', 'disqualificationReasonId', 'issuedCount');
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('cancel-appointment')->show();
    }

    /**
     * Record that the upcoming appointment was cancelled, and disqualify the
     * Lead in the same step when the reason asks and the answer is yes.
     */
    public function cancel(CancelAppointment $cancelAppointment): void
    {
        $this->authorize('cancelAppointment', $this->lead);
        $this->resetValidation();

        $appointment = $this->upcoming();
        $reason = $this->chosenCancellationReason;
        $asks = $reason?->asks_if_disqualified ?? false;
        $disqualifies = $asks && $this->cancellationDisqualifies === 'yes';

        $cancelAppointment->handle(
            $this->lead,
            $appointment,
            $this->cancelledBy,
            $reason,
            $this->cancellationNote,
            Auth::user(),
            $this->knownLifecycleVersion,
            disqualifies: $asks ? match ($this->cancellationDisqualifies) {
                'yes' => true,
                'no' => false,
                default => null,
            } : null,
            disqualifyFor: $disqualifies ? $this->disqualificationReasons->firstWhere('id', $this->disqualificationReasonId) : null,
            neverShouldHaveBeenBooked: $disqualifies && $this->manages && $this->issuedCount === StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED,
        );

        $this->changed();

        Flux::modal('cancel-appointment')->close();
        Flux::toast(variant: 'success', text: $disqualifies
            ? __('The appointment is cancelled and the lead is disqualified.')
            : __('The appointment is cancelled. The lead stays with :name to be booked again.', ['name' => $appointment->salesperson_name]));
    }

    public function openSendBack(): void
    {
        $this->authorize('manageAppointment', $this->lead);

        $this->reset('issuedCount', 'sendBackNote');
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('send-lead-back')->show();
    }

    /**
     * Send the Lead back to Contact Center, with why.
     */
    public function sendBack(SendLeadBackToContactCenter $sendLeadBackToContactCenter): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $sendLeadBackToContactCenter->handle($this->lead, $this->issuedCount, $this->sendBackNote, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('send-lead-back')->close();
        Flux::toast(variant: 'success', text: __('The lead is back with the Contact Center and in the call queue.'));
    }

    public function openCloseOut(string $outcome): void
    {
        $appointment = $this->toCloseOut ?? abort(409, __('This appointment is no longer waiting for an answer. Reload the page.'));

        $this->authorize('closeOutAppointment', [$this->lead, $appointment]);

        abort_unless(in_array($outcome, [AppointmentState::Run->value, AppointmentState::NoShow->value], true), 404);

        $this->closeOutOutcome = $outcome;
        $this->closeOutNote = '';
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('close-out-appointment')->show();
    }

    /**
     * Say what happened to the appointment whose time has come: run, or no-show.
     */
    public function closeOut(CloseOutAppointment $closeOutAppointment): void
    {
        $appointment = $this->toCloseOut ?? abort(409, __('This appointment is no longer waiting for an answer. Reload the page.'));

        $this->authorize('closeOutAppointment', [$this->lead, $appointment]);
        $this->resetValidation();

        $closeOutAppointment->handle($this->lead, $appointment, $this->closeOutOutcome, $this->closeOutNote, Auth::user(), $this->knownLifecycleVersion);

        $noShow = $this->closeOutOutcome === AppointmentState::NoShow->value;

        $this->changed();

        Flux::modal('close-out-appointment')->close();
        Flux::toast(variant: 'success', text: $noShow
            ? __('Marked as a no-show. The lead stays with its salesperson to be booked again.')
            : __('Marked as run.'));
    }

    public function openCorrectCloseOut(): void
    {
        $this->authorize('manageAppointment', $this->lead);

        $this->correctionNote = '';
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('correct-close-out')->show();
    }

    /**
     * Change the shown appointment from run to no-show, or back, with why.
     */
    public function correctCloseOut(CorrectAppointmentCloseOut $correctAppointmentCloseOut): void
    {
        $this->authorize('manageAppointment', $this->lead);
        $this->resetValidation();

        $appointment = $this->appointment ?? abort(409);

        $correctAppointmentCloseOut->handle($this->lead, $appointment, $this->correctionNote, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('correct-close-out')->close();
        Flux::toast(variant: 'success', text: __('Corrected.'));
    }

    public function openTakeBack(): void
    {
        $this->authorize('takeBack', $this->lead);

        $this->takeBackNote = '';
        $this->resetValidation();
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;

        Flux::modal('take-lead-back')->show();
    }

    /**
     * The salesperson the Lead was released from takes it back, with a note.
     */
    public function takeBack(TakeLeadBackFromContactCenter $takeLeadBackFromContactCenter): void
    {
        $this->authorize('takeBack', $this->lead);
        $this->resetValidation();

        $takeLeadBackFromContactCenter->handle($this->lead, $this->takeBackNote, Auth::user(), $this->knownLifecycleVersion);

        $this->changed();

        Flux::modal('take-lead-back')->close();
        Flux::toast(variant: 'success', text: __('This lead is yours again. Book its appointment when you have a time.'));
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
     * The Lead's appointment that stands and has not finished. A page that
     * still offers a button for one that has gone is refused.
     */
    private function upcoming(): Appointment
    {
        $appointment = $this->lead->standingAppointment();

        abort_if($appointment === null || ! $appointment->isUpcomingAt(now()), 409, __('This appointment is no longer upcoming. Reload the page.'));

        return $appointment;
    }

    private function validateTime(bool $withSalesperson): void
    {
        $this->validate([
            ...($withSalesperson ? ['salespersonId' => ['required', 'integer']] : []),
            'appointmentDate' => ['required', 'string'],
            'appointmentTime' => ['required', 'string'],
            'appointmentMinutes' => ['required', 'integer'],
        ], [
            'salespersonId.required' => __('Choose the salesperson.'),
            'appointmentDate.required' => __('Choose the day of the appointment.'),
            'appointmentTime.required' => __('Choose the time of the appointment.'),
            'appointmentMinutes.required' => __('Choose how long the appointment is.'),
        ]);
    }

    /**
     * The Lead page changed the Lead (qualified it, moved it, disqualified
     * it, gave it to someone): read it again here.
     */
    #[On('lead-changed')]
    public function leadChanged(): void
    {
        unset($this->lead, $this->appointment, $this->toCloseOut, $this->earlierAppointments, $this->gaps, $this->manages, $this->salespeople, $this->otherSalespeople);
    }

    /**
     * Read the Lead again here, and tell the Lead page to do the same.
     */
    private function changed(): void
    {
        $this->leadChanged();

        $this->dispatch('lead-appointment-changed');
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-appointment">
    <flux:heading size="lg" level="2">{{ __('Appointment') }}</flux:heading>

    @php
        $lead = $this->lead;
        $appointment = $this->appointment;
        $upcoming = $appointment !== null && $appointment->isUpcomingAt(now());
        $passed = $appointment !== null && $appointment->stands() && ! $upcoming;
        $withSales = $lead->isWithSales();
        $when = fn ($from, $to, $timezone) => $from->copy()->timezone($timezone)->format('D, M j, Y').', '.$from->copy()->timezone($timezone)->format('g:i A').' '.__('to').' '.$to->copy()->timezone($timezone)->format('g:i A T');
    @endphp

    <x-lead.sales-return :lead="$lead" />

    @can('takeBack', $lead)
        <div class="flex flex-col gap-2" data-test="take-back">
            <flux:text>{{ __('This lead was released from you because it was not booked again in time. If the customer has come back to you, you can take it back.') }}</flux:text>

            <div>
                <flux:button wire:click="openTakeBack" data-test="take-lead-back">{{ __('Take this lead back') }}</flux:button>
            </div>
        </div>

        <x-confirmation-modal
            name="take-lead-back"
            :title="__('Take this lead back?')"
            :description="__('It becomes yours again, to book. It leaves the call queue and does not count as a new issued lead.')"
            action="takeBack"
            :confirm-label="__('Take it back')"
            confirm-variant="primary"
            :error="['stale']"
        >
            <flux:textarea wire:model="takeBackNote" rows="2" :label="__('Why you are taking it back (required)')" data-test="take-back-note" />
        </x-confirmation-modal>
    @endcan

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
                @if ($appointment->followsAnother())
                    <dd class="text-sm text-zinc-500" data-test="appointment-follows">{{ __('Booked again while the lead was with Sales. It does not count as a new issued lead.') }}</dd>
                @endif
            </div>
        </dl>

        @if ($appointment->changes->isNotEmpty())
            <div class="flex flex-col gap-1" data-test="appointment-changes">
                <flux:heading size="sm" level="3">{{ __('Earlier times') }}</flux:heading>

                <ul class="flex flex-col gap-1 text-sm text-zinc-700">
                    @foreach ($appointment->changes->reverse() as $change)
                        <li wire:key="appointment-change-{{ $change->id }}" data-test="appointment-change">
                            {{ __('Was :when with :name.', ['when' => $when($change->from_starts_at, $change->from_ends_at, $appointment->timezone), 'name' => $change->from_salesperson_name]) }}
                            <span class="text-zinc-500">
                                {{ $change->type->label() }} &middot; {{ $change->changed_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$change->created_at" />@if ($change->note !== null) &middot; &ldquo;{{ $change->note }}&rdquo;@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($appointment->stands())
            @if ($withSales && $lead->assigned_user_id !== $appointment->salesperson_id)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="appointment-needs-salesperson">
                    <flux:callout.text>
                        {{ $upcoming
                            ? __(':name is no longer working this lead, so this appointment needs a salesperson. A manager can move it to someone else.', ['name' => $appointment->salesperson_name])
                            : __(':name is no longer working this lead. A manager can book it with someone else or send it back to the Contact Center.', ['name' => $appointment->salesperson_name]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($passed && $withSales && $this->toCloseOut === null)
                <flux:text data-test="appointment-passed">{{ __('The time of this appointment has passed.') }}</flux:text>
            @endif

            @if ($upcoming)
                <div class="flex flex-wrap gap-2" data-test="appointment-actions">
                    @can('rescheduleAppointment', [$lead, $appointment])
                        <flux:button size="sm" wire:click="openReschedule" data-test="reschedule-appointment">{{ __('Reschedule') }}</flux:button>
                    @endcan

                    @if ($this->manages)
                        <flux:button size="sm" wire:click="openMove" data-test="move-appointment">{{ __('Move to another salesperson') }}</flux:button>
                    @endif

                    @can('cancelAppointment', $lead)
                        <flux:button size="sm" wire:click="openCancel" data-test="cancel-appointment">{{ __('Cancel appointment') }}</flux:button>
                    @endcan

                    @if ($this->manages && $lead->status === \App\Enums\LeadStatus::AppointmentBooked)
                        <flux:button size="sm" variant="ghost" wire:click="openBookedInError" data-test="appointment-booked-in-error">{{ __('Booked in error') }}</flux:button>
                    @endif
                </div>
            @elseif ($this->manages && $lead->status === \App\Enums\LeadStatus::AppointmentBooked)
                <div>
                    <flux:button size="sm" variant="ghost" wire:click="openBookedInError" data-test="appointment-booked-in-error">{{ __('Booked in error') }}</flux:button>
                </div>
            @endif
        @elseif ($appointment->state->isClosedOut())
            <flux:callout :icon="$appointment->state === AppointmentState::Run ? 'check-circle' : 'x-circle'" data-test="appointment-closed-out">
                <flux:callout.heading data-test="appointment-closed-out-as">{{ $appointment->state === AppointmentState::Run ? __('Appointment was run') : __('No-show') }}</flux:callout.heading>
                <flux:callout.text>
                    <span class="block">
                        {{ __('Recorded by') }} {{ $appointment->state_changed_by_name ?? __('Unknown') }}@if ($appointment->state_changed_at !== null) &middot; <x-local-time :at="$appointment->state_changed_at" />@endif
                    </span>
                    @if ($appointment->state_note !== null)
                        <span class="mt-1 block whitespace-pre-line">&ldquo;{{ $appointment->state_note }}&rdquo;</span>
                    @endif
                    @if ($appointment->state === AppointmentState::NoShow && $lead->status === LeadStatus::AppointmentCancelled)
                        <span class="mt-1 block">{{ __('The lead stays with its salesperson to be booked again, as after a cancellation.') }}</span>
                    @endif
                </flux:callout.text>
            </flux:callout>

            @if ($this->manages)
                <div>
                    <flux:button size="sm" variant="ghost" wire:click="openCorrectCloseOut" data-test="correct-close-out">
                        {{ $appointment->state === AppointmentState::Run ? __('Correct: it was a no-show') : __('Correct: it was run') }}
                    </flux:button>
                </div>
            @endif
        @elseif ($appointment->isCancelled())
            <flux:callout icon="x-circle" data-test="appointment-cancelled">
                <flux:callout.heading>{{ __('Cancelled') }}</flux:callout.heading>
                <flux:callout.text>
                    <span class="block" data-test="appointment-cancelled-by">{{ __('Cancelled by: :who', ['who' => mb_strtolower($appointment->cancelled_by_party?->label() ?? __('Not recorded'))]) }}</span>
                    @if ($appointment->cancellation_reason_name !== null)
                        <span class="block" data-test="appointment-cancelled-reason">{{ __('Reason: :reason', ['reason' => $appointment->cancellation_reason_name]) }}</span>
                    @endif
                    <span class="block">
                        {{ __('Recorded by') }} {{ $appointment->state_changed_by_name ?? __('Unknown') }}@if ($appointment->state_changed_at !== null) &middot; <x-local-time :at="$appointment->state_changed_at" />@endif
                    </span>
                    @if ($appointment->state_note !== null)
                        <span class="mt-1 block whitespace-pre-line">&ldquo;{{ $appointment->state_note }}&rdquo;</span>
                    @endif
                </flux:callout.text>
            </flux:callout>
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

    @if ($this->toCloseOut !== null)
        @php
            $waiting = $this->toCloseOut;
        @endphp

        <div class="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4" data-test="close-out">
            <flux:text class="text-ink">
                {{ __('The time of the appointment on :when has come. What happened?', ['when' => $waiting->starts_at->copy()->timezone($waiting->timezone)->format('D, M j \a\t g:i A T')]) }}
            </flux:text>

            @can('closeOutAppointment', [$lead, $waiting])
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" wire:click="openCloseOut('{{ AppointmentState::Run->value }}')" data-test="close-out-run">{{ __('Appointment was run') }}</flux:button>
                    <flux:button size="sm" wire:click="openCloseOut('{{ AppointmentState::NoShow->value }}')" data-test="close-out-no-show">{{ __('No-show') }}</flux:button>
                </div>
            @else
                <flux:text class="text-sm" data-test="close-out-not-yours">{{ __('Its salesperson or a manager says whether it was run or was a no-show.') }}</flux:text>
            @endcan
        </div>
    @endif

    {{-- Kept on the page after the answer is given, so the dialog that was just used can close properly. --}}
    @if ($appointment !== null)
        @can('closeOutAppointment', [$lead, $this->toCloseOut ?? $appointment])
            <x-confirmation-modal
                name="close-out-appointment"
                :title="$closeOutOutcome === AppointmentState::NoShow->value ? __('Mark this appointment a no-show?') : __('Mark this appointment as run?')"
                :description="$closeOutOutcome === AppointmentState::NoShow->value
                    ? __('The customer was not there. The lead stays with its salesperson to be booked again, as after a cancellation, and still counts as issued.')
                    : __('The visit happened. The lead stays with its salesperson. Proposals and their results are recorded separately.')"
                action="closeOut"
                :confirm-label="$closeOutOutcome === AppointmentState::NoShow->value ? __('No-show') : __('Appointment was run')"
                confirm-variant="primary"
                :error="['stale', 'closeOutOutcome']"
            >
                <flux:textarea wire:model="closeOutNote" rows="2" :label="__('Note (optional)')" data-test="close-out-note" />
            </x-confirmation-modal>
        @endcan
    @endif

    {{-- Nothing else is offered while an appointment waits to be closed out (decision 126). --}}
    @if (! $upcoming && $this->toCloseOut === null)
        @if ($this->gaps !== [])
            <ul class="flex flex-col gap-1 text-sm text-zinc-700" data-test="appointment-gaps">
                <li class="text-zinc-500">{{ __('No appointment can be booked yet.') }}</li>
                @foreach ($this->gaps as $gap)
                    <li wire:key="appointment-gap-{{ $loop->index }}">{{ $gap }}</li>
                @endforeach
            </ul>
        @else
            @can('bookAppointment', $lead)
                @if ($withSales && $this->salespeople->isEmpty())
                    <flux:text data-test="appointment-needs-manager">{{ __('This lead is with Sales and has no salesperson who can take it now. A manager chooses the new one.') }}</flux:text>
                @else
                    <div>
                        <flux:button variant="primary" wire:click="openBook" data-test="book-appointment">
                            {{ ($passed && $withSales) || $lead->status === LeadStatus::AppointmentRun ? __('Book another appointment') : ($withSales ? __('Book again') : __('Book appointment')) }}
                        </flux:button>
                    </div>
                @endif
            @else
                <flux:text data-test="appointment-not-yours">{{ __('This lead is ready for an appointment. A manager or the call center books it.') }}</flux:text>
            @endcan
        @endif
    @endif

    @if ($withSales && $this->manages && ! $lead->hasAppointmentToCloseOut())
        <div>
            <flux:button size="sm" variant="ghost" wire:click="openSendBack" data-test="send-lead-back">{{ __('Send back to Contact Center') }}</flux:button>
        </div>
    @endif

    @if ($this->earlierAppointments->isNotEmpty())
        <div class="flex flex-col gap-1" data-test="earlier-appointments">
            <flux:heading size="sm" level="3">{{ __('Earlier appointments') }}</flux:heading>

            <ul class="flex flex-col gap-1 text-sm text-zinc-700">
                @foreach ($this->earlierAppointments as $earlier)
                    <li wire:key="earlier-appointment-{{ $earlier->id }}" data-test="earlier-appointment">
                        {{ $when($earlier->starts_at, $earlier->ends_at, $earlier->timezone) }} &middot; {{ $earlier->salesperson_name }}
                        <span class="text-zinc-500">
                            &middot; {{ $earlier->stands() ? __('Passed') : $earlier->state->label().($earlier->cancellation_reason_name === null ? '' : ': '.$earlier->cancellation_reason_name) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('bookAppointment', $lead)
        <flux:modal name="book-appointment" class="w-full max-w-lg">
            <form wire:submit="book" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <flux:error name="booking" />

                <div>
                    <flux:heading size="lg">{{ $withSales ? __('Book this lead again') : __('Book an appointment') }}</flux:heading>
                    <flux:subheading>
                        {{ $withSales
                            ? __('This lead is already with Sales, so booking it again does not count as a new issued lead. The salesperson is told in Backoffice and by email.')
                            : __('Saving hands this lead to the salesperson. They are told in Backoffice and by email, and the lead leaves the call queue.') }}
                    </flux:subheading>
                </div>

                <flux:select wire:model="salespersonId" :label="__('Salesperson')" data-test="appointment-salesperson-choice">
                    <flux:select.option value="">{{ __('Choose a salesperson') }}</flux:select.option>
                    @foreach ($this->salespeople as $person)
                        <flux:select.option :value="$person->id" wire:key="salesperson-{{ $person->id }}">{{ $person->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->salespeople->isEmpty())
                    <flux:text data-test="appointment-no-salespeople">{{ __('Nobody has Sales Consultant access at :location yet.', ['location' => $lead->location?->name]) }}</flux:text>
                @elseif ($withSales && ! $this->manages)
                    <flux:text class="text-sm" data-test="appointment-same-salesperson">{{ __('While a lead is with Sales, only a manager can book it with a different salesperson.') }}</flux:text>
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

    {{-- The dialogs stay on the page once the appointment is no longer upcoming, so one that has just been used can close properly. --}}
    @if ($appointment !== null)
        @can('rescheduleAppointment', [$lead, $appointment])
            <flux:modal name="reschedule-appointment" class="w-full max-w-lg">
                <form wire:submit="reschedule" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Reschedule this appointment') }}</flux:heading>
                        <flux:subheading>{{ __('The salesperson stays the same and the time it had is kept in its history. If you are not the salesperson, they are told in Backoffice and by email.') }}</flux:subheading>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="appointmentDate" type="date" :min="$this->localTime['today']" :label="__('Day')" data-test="reschedule-date" />
                        <flux:input wire:model="appointmentTime" type="time" step="900" :label="__('Time')" data-test="reschedule-time" />
                    </div>

                    <flux:text class="text-sm">{{ __('In :location time: :timezone.', ['location' => $lead->location?->name, 'timezone' => $this->localTime['name']]) }}</flux:text>

                    <flux:select wire:model="appointmentMinutes" :label="__('Length')" data-test="reschedule-length">
                        @foreach ($this->lengths as $minutes => $label)
                            <flux:select.option :value="$minutes" wire:key="reschedule-length-{{ $minutes }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-reschedule">{{ __('Save new time') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endcan

        @if ($this->manages)
            <flux:modal name="move-appointment" class="w-full max-w-lg">
                <form wire:submit="move" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Move this appointment to another salesperson') }}</flux:heading>
                        <flux:subheading>{{ __('The appointment keeps its time and the lead goes to the new salesperson. Both people stay in its history, and both are told.') }}</flux:subheading>
                    </div>

                    <flux:select wire:model="newSalespersonId" :label="__('New salesperson')" data-test="move-salesperson">
                        <flux:select.option value="">{{ __('Choose a salesperson') }}</flux:select.option>
                        @foreach ($this->otherSalespeople as $person)
                            <flux:select.option :value="$person->id" wire:key="move-salesperson-{{ $person->id }}">{{ $person->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->otherSalespeople->isEmpty())
                        <flux:text data-test="move-nobody">{{ __('Nobody else has Sales Consultant access at :location.', ['location' => $lead->location?->name]) }}</flux:text>
                    @endif

                    <flux:textarea wire:model="moveNote" rows="2" :label="__('Note (optional)')" data-test="move-note" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-move">{{ __('Move appointment') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @can('cancelAppointment', $lead)
            <flux:modal name="cancel-appointment" class="w-full max-w-lg">
                <form wire:submit="cancel" class="flex flex-col gap-5">
                    <flux:error name="stale" />
                    <flux:error name="status" />

                    <div>
                        <flux:heading size="lg">{{ __('Cancel this appointment') }}</flux:heading>
                        <flux:subheading>{{ __('The appointment is kept on record as cancelled. The lead stays with :name to be booked again, and still counts as issued.', ['name' => $appointment->salesperson_name]) }}</flux:subheading>
                    </div>

                    <flux:select wire:model="cancelledBy" :label="__('Who cancelled?')" data-test="cancelled-by">
                        <flux:select.option value="">{{ __('Choose who cancelled') }}</flux:select.option>
                        @foreach (CancellationParty::cases() as $party)
                            <flux:select.option :value="$party->value" wire:key="cancelled-by-{{ $party->value }}">{{ $party->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="cancellationReasonId" :label="__('Reason')" data-test="cancellation-reason">
                        <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
                        @foreach ($this->cancellationReasons as $reason)
                            <flux:select.option :value="$reason->id" wire:key="cancellation-reason-{{ $reason->id }}">{{ $reason->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:textarea
                        wire:model="cancellationNote"
                        rows="3"
                        :label="$this->chosenCancellationReason?->requires_note ? __('What happened (required)') : __('Note (optional)')"
                        data-test="cancellation-note"
                    />

                    @if ($this->chosenCancellationReason?->asks_if_disqualified)
                        <flux:select wire:model.live="cancellationDisqualifies" :label="__('Is this lead disqualified?')" data-test="cancellation-disqualifies">
                            <flux:select.option value="">{{ __('Choose yes or no') }}</flux:select.option>
                            <flux:select.option value="no">{{ __('No. It stays with the salesperson to be booked again') }}</flux:select.option>
                            <flux:select.option value="yes">{{ __('Yes. Disqualify it now') }}</flux:select.option>
                        </flux:select>

                        @if ($cancellationDisqualifies === 'yes')
                            <flux:select wire:model="disqualificationReasonId" :label="__('Why it is disqualified')" data-test="cancellation-disqualification-reason">
                                <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
                                @foreach ($this->disqualificationReasons as $reason)
                                    <flux:select.option :value="$reason->id" wire:key="cancel-disqualification-reason-{{ $reason->id }}">{{ $reason->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            @if ($this->manages)
                                <flux:select wire:model="issuedCount" :label="__('Should this lead ever have been booked?')" data-test="cancellation-issued-count">
                                    <flux:select.option value="">{{ __('It still counts as issued') }}</flux:select.option>
                                    <flux:select.option :value="StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED">{{ __('It should never have been booked. Do not count it as issued') }}</flux:select.option>
                                </flux:select>
                            @endif
                        @endif
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" autofocus>{{ __('Keep the appointment') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="danger" data-test="save-cancellation">{{ __('Cancel appointment') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endcan
    @endif

    @if ($this->manages)
        @if ($appointment !== null)
            <flux:modal name="send-lead-back" class="w-full max-w-lg">
                <form wire:submit="sendBack" class="flex flex-col gap-5">
                    <flux:error name="stale" />

                    <div>
                        <flux:heading size="lg">{{ __('Send this lead back to the Contact Center?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('The lead leaves its salesperson, shows Qualified again and goes back in the call queue, where the call center is told it came back from Sales.') }}
                            @if ($upcoming)
                                {{ __('Its upcoming appointment is cancelled.') }}
                            @endif
                        </flux:subheading>
                    </div>

                    @if ($upcoming)
                        <flux:callout icon="information-circle" data-test="send-back-use-move">
                            <flux:callout.text>{{ __('If someone else should take this appointment, use Move to another salesperson instead. That keeps the appointment.') }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    <flux:select wire:model="issuedCount" :label="__('Should this lead ever have been booked?')" data-test="send-back-issued-count">
                        <flux:select.option value="">{{ __('Choose one') }}</flux:select.option>
                        <flux:select.option :value="StopCountingLeadAsIssued::ANOTHER_REASON">{{ __('Yes. It goes back for another reason and still counts as issued') }}</flux:select.option>
                        <flux:select.option :value="StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED">{{ __('No. It should never have been booked. Do not count it as issued') }}</flux:select.option>
                    </flux:select>

                    <flux:textarea wire:model="sendBackNote" rows="3" :label="__('Why it is going back (required)')" data-test="send-back-note" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="danger" data-test="save-send-back">{{ __('Send back') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @if ($appointment !== null)
            <x-confirmation-modal
                name="correct-close-out"
                :title="__('Correct what happened at this appointment?')"
                :description="$appointment->state === AppointmentState::Run
                    ? __('It is marked as run. This changes it to a no-show.')
                    : __('It is marked as a no-show. This changes it to run.')"
                action="correctCloseOut"
                :confirm-label="__('Correct it')"
                confirm-variant="primary"
                :error="['stale']"
            >
                <flux:textarea wire:model="correctionNote" rows="2" :label="__('Why it is being corrected (required)')" data-test="correction-note" />
            </x-confirmation-modal>
        @endif

        <x-confirmation-modal
            name="appointment-booked-in-error"
            :title="__('Mark this appointment booked in error?')"
            :description="$appointment?->followsAnother()
                ? __('The appointment is kept on record as booked in error, and the lead goes back exactly as it was before this booking.')
                : __('The appointment is kept on record as booked in error. The lead goes back exactly as it was before the booking, and it does not count as issued.')"
            action="markBookedInError"
            :confirm-label="__('Booked in error')"
            :error="['stale']"
        >
            <flux:textarea wire:model="bookedInErrorNote" rows="3" :label="__('Why it was booked in error (required)')" data-test="booked-in-error-note" />
        </x-confirmation-modal>
    @endif
</div>
