<?php

use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\CorrectLeadDisqualification;
use App\Actions\Leads\CorrectLeadReceivedTime;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\QualifyLead;
use App\Actions\Leads\RecordManualContactAttempt;
use App\Actions\Leads\ReopenLead;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\ReturnLeadToWorking;
use App\Actions\Leads\RouteLead;
use App\Actions\Leads\StartWorkingLead;
use App\Actions\Leads\UnassignLead;
use App\Actions\Leads\UnlinkLeadCustomer;
use App\Actions\Leads\UpdateLeadDetails;
use App\Actions\Notes\AddNote;
use App\Actions\Queue\ReturnLeadToQueue;
use App\Actions\Notes\RemoveNote;
use App\Livewire\Concerns\RecordsContactAttempts;
use App\Support\Addresses\AddressCapturePage;
use App\Enums\AssignmentEndReason;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\ContactAttempt;
use App\Models\ContactCenterSettings;
use App\Models\LeadCadence;
use App\Models\LeadChannel;
use App\Models\LeadQueueEvent;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Models\Note;
use App\Models\ProjectSize;
use App\Models\Service;
use App\Models\User;
use App\Support\Addresses\StructuredAddress;
use App\Support\Contact\PhoneNumber;
use App\Support\Queue\CallingHours;
use App\Support\Queue\QueueTransaction;
use App\Support\Leads\ServiceSelection;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;

new #[Title('Lead')] class extends AddressCapturePage {
    use RecordsContactAttempts;

    public Lead $lead;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $businessName = '';

    public string $message = '';

    public ?int $channelId = null;

    public ?int $leadSourceId = null;

    public ?int $marketingSourceId = null;

    /** @var list<string> */
    public array $serviceIds = [];

    public ?string $primaryServiceId = null;

    public ?int $projectSizeId = null;

    /** Where the Lead was, and who had it, when a routing or assignment dialog was opened. */
    #[Locked]
    public ?int $knownLocationId = null;

    #[Locked]
    public ?int $knownAssigneeId = null;

    public ?int $destinationId = null;

    public string $routingReason = '';

    public string $returnReason = '';

    /** For a Lead that is with Sales: whether it still counts as issued once it is moved, returned or disqualified (decision 110). */
    public string $issuedCount = '';

    public ?int $assigneeId = null;

    public string $correctedReceivedAt = '';

    public string $receivedTimeReason = '';

    public string $noteBody = '';

    #[Locked]
    public ?int $pendingNoteId = null;

    #[Locked]
    public ?int $pendingUnlinkAccountId = null;

    #[Locked]
    public ?int $pendingUnlinkContactId = null;

    #[Locked]
    public ?int $pendingUnlinkPropertyId = null;

    /** The lifecycle state shown when the dialog opened, retained across requests. */
    #[Locked]
    public string $knownStatus = '';

    #[Locked]
    public int $knownLifecycleVersion = 0;

    /** The revision shown on the page for actions without a dialog. */
    #[Locked]
    public int $pageLifecycleVersion = 0;

    #[Locked]
    public ?int $lifecycleLocationId = null;

    #[Locked]
    public ?int $lifecycleAssigneeId = null;

    public string $followUpOn = '';

    public string $followUpNote = '';

    public ?int $disqualificationReasonId = null;

    public string $disqualificationNote = '';

    /** The Lead this one repeats. Empty means "the original cannot be identified or opened". */
    public ?int $duplicateOfId = null;

    /** Whether the disqualify dialog is correcting an existing disqualification rather than making one. */
    #[Locked]
    public bool $correctingDisqualification = false;

    /** The Lead as the Appointment card last saw it: revision, status, Location and assignee. */
    #[Locked]
    public string $appointmentCardSaw = '';

    public string $reopenReason = '';

    public string $putBackNote = '';

    /**
     * Mount the component.
     */
    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->lead = $lead;
        $this->pageLifecycleVersion = (int) $lead->lifecycle_version;

        $this->startAddressCapture();
    }

    public function dehydrate(): void
    {
        $this->pageLifecycleVersion = (int) $this->lead->lifecycle_version;
    }

    /**
     * The Appointment card is a component of its own and does not see what
     * this page does to the Lead. Whenever the Lead's status, revision,
     * Location or assignee is no longer what the card last saw, the card is
     * told to read the Lead again.
     */
    public function rendering(): void
    {
        $lead = $this->lead;
        $seen = implode('-', [(int) $lead->lifecycle_version, $lead->status->value, $lead->location_id ?? 0, $lead->assigned_user_id ?? 0]);

        if ($this->appointmentCardSaw !== '' && $this->appointmentCardSaw !== $seen) {
            $this->dispatch('lead-changed')->to('pages::leads.appointment');
            $this->dispatch('lead-changed')->to('pages::leads.proposals');
        }

        $this->appointmentCardSaw = $seen;
    }

    /**
     * The Appointment card booked or undid an appointment, which changes the
     * Lead's status, who has it and its place in the call queue. Read it all
     * again.
     */
    #[On('lead-appointment-changed')]
    public function appointmentChanged(): void
    {
        $this->lead->refresh();

        unset($this->cadence, $this->queueHistory, $this->eligibleAssignees, $this->openLeadCounts, $this->qualificationGaps);
    }

    /**
     * Deliberately start working a New Lead. Nothing else moves it to Working.
     *
     * The person it is assigned to may start. Someone who hands out Leads may
     * start an unassigned one, which makes it theirs. Nobody else may.
     */
    public function startWorking(StartWorkingLead $startWorkingLead): void
    {
        $this->authorize('startWorking', $this->lead);

        $startWorkingLead->handle($this->lead, Auth::user(), $this->pageLifecycleVersion);

        Flux::toast(variant: 'success', text: __('You are now working this lead.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle: qualify, nurture, disqualify, reopen
    |--------------------------------------------------------------------------
    */

    /**
     * Open the dialog that shows what a Lead needs before it can be qualified.
     */
    public function openQualify(): void
    {
        $this->authorize('changeStatus', $this->lead);

        $this->startLifecycleDialog('qualify-lead');
    }

    /**
     * Record the existing qualification milestone; no appointment, sale or Project is created.
     */
    public function qualify(QualifyLead $qualifyLead): void
    {
        $this->authorize('changeStatus', $this->lead);

        if ($this->lifecycleHasChangedSinceTheDialogOpened('qualify-lead')) {
            return;
        }

        $qualifyLead->handle($this->lead, Auth::user(), $this->knownLifecycleVersion);

        Flux::modal('qualify-lead')->close();
        Flux::toast(variant: 'success', text: __('This lead is qualified.'));
    }

    /**
     * Open the dialog for moving the Lead to nurture, or for changing its follow-up.
     */
    public function openNurture(): void
    {
        $this->authorize('changeStatus', $this->lead);

        $this->followUpOn = (string) $this->lead->follow_up_on?->toDateString();
        $this->followUpNote = (string) $this->lead->follow_up_note;

        $this->startLifecycleDialog('nurture-lead');
    }

    /**
     * Move the Lead to nurture with a follow-up date, or reschedule a follow-up.
     */
    public function saveNurture(MoveLeadToNurture $moveLeadToNurture): void
    {
        $this->authorize('changeStatus', $this->lead);

        if ($this->lifecycleHasChangedSinceTheDialogOpened('nurture-lead')) {
            return;
        }

        $this->validate([
            'followUpOn' => ['required', 'date_format:Y-m-d'],
            'followUpNote' => ['required', 'string', 'max:2000'],
        ], [
            'followUpOn.required' => __('Choose the date to follow up.'),
            'followUpNote.required' => __('Say what to follow up about.'),
        ], [
            'followUpOn' => 'follow-up date',
            'followUpNote' => 'note',
        ]);

        $moveLeadToNurture->handle($this->lead, $this->followUpOn, $this->followUpNote, Auth::user(), $this->knownLifecycleVersion);

        Flux::modal('nurture-lead')->close();
        Flux::toast(variant: 'success', text: __('Follow-up set for :date.', ['date' => $this->lead->follow_up_on?->format('M j, Y')]));
    }

    public function openReturnToWorking(): void
    {
        $this->authorize('changeStatus', $this->lead);

        $this->startLifecycleDialog('return-to-working');
    }

    /**
     * Bring a Lead in nurture, or a qualified one, back to Working. Whoever has it keeps it.
     */
    public function resumeWorking(ReturnLeadToWorking $returnLeadToWorking): void
    {
        $this->authorize('changeStatus', $this->lead);

        if ($this->lifecycleHasChangedSinceTheDialogOpened('return-to-working')) {
            return;
        }

        $returnLeadToWorking->handle($this->lead, Auth::user(), $this->knownLifecycleVersion);

        Flux::modal('return-to-working')->close();

        Flux::toast(variant: 'success', text: __('This lead is being worked again.'));
    }

    /**
     * Open the dialog for disqualifying the Lead.
     */
    public function openDisqualify(): void
    {
        $this->authorize('changeStatus', $this->lead);

        if ($this->refusedByAnUpcomingAppointment()) {
            return;
        }

        $this->reset('disqualificationReasonId', 'disqualificationNote', 'duplicateOfId', 'issuedCount');

        $this->correctingDisqualification = false;

        $this->startLifecycleDialog('disqualify-lead');
    }

    /**
     * Open the same dialog to correct why a Lead was disqualified, while it still is.
     */
    public function openCorrectDisqualification(): void
    {
        $this->authorize('reopen', $this->lead);

        $this->disqualificationReasonId = $this->lead->disqualification_reason_id;
        $this->disqualificationNote = (string) $this->lead->disqualification_note;
        $this->duplicateOfId = $this->lead->duplicate_of_lead_id;

        $this->correctingDisqualification = true;

        $this->startLifecycleDialog('disqualify-lead');
    }

    public function updatedDisqualificationReasonId(): void
    {
        unset($this->chosenReason);

        $this->resetValidation(['disqualificationReasonId', 'disqualificationNote', 'duplicateOfId']);
    }

    /**
     * Disqualify the Lead, or correct its disqualification, with the reason given.
     */
    public function disqualify(DisqualifyLead $disqualifyLead, CorrectLeadDisqualification $correctLeadDisqualification): void
    {
        $this->authorize($this->correctingDisqualification ? 'reopen' : 'changeStatus', $this->lead);

        if ($this->lifecycleHasChangedSinceTheDialogOpened('disqualify-lead')) {
            return;
        }

        $this->validate([
            'disqualificationReasonId' => ['required', 'integer'],
            'disqualificationNote' => ['nullable', 'string', 'max:2000'],
            'duplicateOfId' => ['nullable', 'integer'],
        ], [
            'disqualificationReasonId.required' => __('Choose why this lead is being disqualified.'),
        ], [
            'disqualificationNote' => 'note',
        ]);

        $reason = $this->disqualificationReasons->firstWhere('id', $this->disqualificationReasonId);

        if ($reason === null) {
            $this->addError('disqualificationReasonId', __('Choose one of the reasons listed.'));

            return;
        }

        // Only a Lead this person can already see may be named as the original.
        $original = $reason->isDuplicate() && $this->duplicateOfId !== null
            ? ($this->possibleOriginals->firstWhere('id', $this->duplicateOfId) ?? abort(404))
            : null;

        if ($this->correctingDisqualification) {
            $correctLeadDisqualification->handle($this->lead, $reason, $this->disqualificationNote, Auth::user(), $original, $this->knownLifecycleVersion);
        } else {
            $disqualifyLead->handle($this->lead, $reason, $this->disqualificationNote, Auth::user(), $original, $this->knownLifecycleVersion, $this->shouldNeverHaveBeenBooked());
        }

        unset($this->possibleOriginals, $this->eligibleAssignees, $this->openLeadCounts);

        Flux::modal('disqualify-lead')->close();
        Flux::toast(variant: 'success', text: $this->correctingDisqualification ? __('Reason corrected.') : __('This lead is disqualified.'));
    }

    /**
     * From the disqualify dialog: set a follow-up instead of giving up on the Lead.
     */
    public function tryAgainLater(): void
    {
        Flux::modal('disqualify-lead')->close();

        $this->openNurture();
    }

    /**
     * Open the dialog for reopening a disqualified Lead.
     */
    public function openReopen(): void
    {
        $this->authorize('reopen', $this->lead);

        $this->reopenReason = '';

        $this->startLifecycleDialog('reopen-lead');
    }

    /**
     * Reopen the Lead. It comes back as Working with nobody assigned.
     */
    public function reopen(ReopenLead $reopenLead): void
    {
        $this->authorize('reopen', $this->lead);

        if ($this->lifecycleHasChangedSinceTheDialogOpened('reopen-lead')) {
            return;
        }

        $this->validate(
            ['reopenReason' => ['required', 'string', 'max:2000']],
            ['reopenReason.required' => __('Say why this lead is being reopened.')],
            ['reopenReason' => 'reason'],
        );

        $reopenLead->handle($this->lead, $this->reopenReason, Auth::user(), $this->knownLifecycleVersion);

        Flux::modal('reopen-lead')->close();
        Flux::toast(variant: 'success', text: __('Reopened. Assign it to someone to work.'));
    }

    /**
     * The reasons on offer, plus the one this Lead already has even if since deactivated.
     *
     * @return Collection<int, LeadDisqualificationReason>
     */
    #[Computed]
    public function disqualificationReasons(): Collection
    {
        return LeadDisqualificationReason::query()
            ->where(fn ($query) => $query
                ->where(fn ($offered) => $offered->where('is_active', true)
                    ->where(fn ($special) => $special->whereNull('special_handling')->orWhere('special_handling', '!=', LeadDisqualificationReason::UNABLE_TO_CONTACT)))
                ->orWhere('id', $this->lead->disqualification_reason_id))
            ->ordered()
            ->get();
    }

    #[Computed]
    public function chosenReason(): ?LeadDisqualificationReason
    {
        return $this->disqualificationReasons->firstWhere('id', $this->disqualificationReasonId);
    }

    /**
     * Other Leads this person can see that this one may repeat.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function possibleOriginals(): Collection
    {
        return DisqualifyLead::possibleOriginals($this->lead, Auth::user());
    }

    /**
     * What the Lead still needs before it can be qualified, in words.
     *
     * @return list<string>
     */
    #[Computed]
    public function qualificationGaps(): array
    {
        return array_map(fn (string $gap): string => QualifyLead::REQUIREMENTS[$gap], $this->lead->qualificationGaps());
    }

    private function startLifecycleDialog(string $dialog): void
    {
        $this->knownStatus = $this->lead->status->value;
        $this->knownLifecycleVersion = (int) $this->lead->lifecycle_version;
        $this->lifecycleLocationId = $this->lead->location_id;
        $this->lifecycleAssigneeId = $this->lead->assigned_user_id;

        unset($this->chosenReason, $this->qualificationGaps);

        $this->resetValidation();

        Flux::modal($dialog)->show();
    }

    /**
     * Refuse stale dialogs, including same-status corrections and reschedules.
     * The action repeats the revision check atomically when claiming the row.
     */
    private function lifecycleHasChangedSinceTheDialogOpened(string $dialog): bool
    {
        if ($this->lead->status->value === $this->knownStatus
            && (int) $this->lead->lifecycle_version === $this->knownLifecycleVersion
            && $this->lead->location_id === $this->lifecycleLocationId
            && $this->lead->assigned_user_id === $this->lifecycleAssigneeId) {
            return false;
        }

        Flux::modal($dialog)->close();

        $this->addError('stale', __('This lead was just changed by someone else, so nothing was saved. Check where it stands now and try again.'));

        return true;
    }

    /**
     * Open the dialog for sending the Lead to a location.
     */
    public function openRouting(): void
    {
        $this->authorize('routeAnywhere', $this->lead);

        if ($this->refusedByAnUpcomingAppointment()) {
            return;
        }

        $this->rememberWhereTheLeadIs();

        $suggested = $this->suggestedLocation;

        $this->destinationId = match (true) {
            $suggested !== null && $this->destinations->contains($suggested) => $suggested->id,
            $this->destinations->count() === 1 => $this->destinations->first()?->id,
            default => null,
        };
        $this->routingReason = '';
        $this->issuedCount = '';

        $this->resetValidation();

        Flux::modal('route-lead')->show();
    }

    /**
     * Send the Lead to the chosen location. Whoever was responsible for it is
     * cleared: a change of location is a handoff.
     */
    public function route(RouteLead $routeLead): void
    {
        $this->authorize('routeAnywhere', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('route-lead')) {
            return;
        }

        $this->validate([
            'destinationId' => ['required', 'integer'],
            'routingReason' => [$this->lead->isUnrouted() ? 'nullable' : 'required', 'string', 'max:1000'],
        ], [
            'destinationId.required' => __('Choose a location.'),
            'routingReason.required' => __('Say why this lead is being moved to another location.'),
        ], [
            'destinationId' => 'location',
            'routingReason' => 'reason',
        ]);

        // Only a destination this person may actually send it to is accepted.
        $destination = $this->destinations->firstWhere('id', $this->destinationId);

        if ($destination === null) {
            $this->addError('destinationId', __('Choose one of the locations listed.'));

            return;
        }

        $this->authorize('route', [$this->lead, $destination]);

        $wasUnrouted = $this->lead->isUnrouted();

        $routeLead->handle($this->lead, $destination, $this->routingReason, Auth::user(), $this->shouldNeverHaveBeenBooked());

        Flux::modal('route-lead')->close();

        $this->afterTheLeadMoved($wasUnrouted
            ? __('Routed to :location.', ['location' => $destination->name])
            : __('Moved to :location.', ['location' => $destination->name]));
    }

    /**
     * Whether the person answered that a Lead that is with Sales should never
     * have been booked, so it stops counting as issued (decision 110). The
     * action checks again that they are a manager.
     */
    private function shouldNeverHaveBeenBooked(): bool
    {
        return $this->lead->isWithSales() && $this->issuedCount === StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED;
    }

    /**
     * A Lead with an upcoming appointment cannot be moved, returned to central
     * or disqualified (decision 95). The person is told why instead of being
     * shown a dialog whose save would be refused; the actions refuse it too.
     */
    private function refusedByAnUpcomingAppointment(): bool
    {
        if ($this->lead->isWithSales() && $this->lead->hasAppointmentToCloseOut()) {
            Flux::toast(variant: 'danger', text: __('Say what happened at this lead\'s last appointment first: run or no-show.'));

            return true;
        }

        if (! $this->lead->hasUpcomingAppointment()) {
            return false;
        }

        Flux::toast(variant: 'danger', text: __('This lead has an upcoming appointment. Cancel or undo its appointment first.'));

        return true;
    }

    /**
     * Open the dialog for sending the Lead back to the central queue.
     */
    public function openReturn(): void
    {
        $this->authorize('returnToCentral', $this->lead);

        if ($this->refusedByAnUpcomingAppointment()) {
            return;
        }

        $this->rememberWhereTheLeadIs();

        $this->returnReason = '';
        $this->issuedCount = '';

        $this->resetValidation();

        Flux::modal('return-lead')->show();
    }

    /**
     * Return the Lead to central, unrouted, with the reason.
     */
    public function returnToCentral(ReturnLeadToCentral $returnLeadToCentral): void
    {
        $this->authorize('returnToCentral', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('return-lead')) {
            return;
        }

        $this->validate(
            ['returnReason' => ['required', 'string', 'max:1000']],
            ['returnReason.required' => __('Say why this lead is being returned to central.')],
            ['returnReason' => 'reason'],
        );

        $returnLeadToCentral->handle($this->lead, $this->returnReason, Auth::user(), $this->shouldNeverHaveBeenBooked());

        Flux::modal('return-lead')->close();

        $this->afterTheLeadMoved(__('Returned to central.'));
    }

    /**
     * Open the dialog for choosing who is responsible for the Lead.
     */
    public function openAssign(): void
    {
        $this->authorize('assign', $this->lead);

        $this->rememberWhereTheLeadIs();

        $this->assigneeId = $this->lead->assigned_user_id;

        $this->resetValidation();

        Flux::modal('assign-lead')->show();
    }

    /**
     * Make the chosen person responsible for the Lead. Only people who can
     * already work it are accepted: assigning never gives anyone access.
     */
    public function assign(AssignLead $assignLead): void
    {
        $this->authorize('assign', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('assign-lead')) {
            return;
        }

        $this->validate(
            ['assigneeId' => ['required', 'integer']],
            ['assigneeId.required' => __('Choose who will work this lead.')],
            ['assigneeId' => 'person'],
        );

        $assignee = $this->eligibleAssignees->firstWhere('id', $this->assigneeId);

        if ($assignee === null) {
            $this->addError('assigneeId', __('Choose one of the people listed.'));

            return;
        }

        $assignLead->handle($this->lead, $assignee, Auth::user());

        Flux::modal('assign-lead')->close();
        Flux::toast(variant: 'success', text: __('Assigned to :name.', ['name' => $assignee->name]));
    }

    /**
     * Leave the Lead with nobody responsible for it.
     */
    public function unassign(UnassignLead $unassignLead): void
    {
        $this->authorize('assign', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('assign-lead')) {
            return;
        }

        $unassignLead->handle($this->lead, AssignmentEndReason::Released, Auth::user());

        Flux::modal('assign-lead')->close();
        Flux::toast(variant: 'success', text: __('This lead is now unassigned.'));
    }

    /**
     * Open the dialog for correcting when the Lead was received.
     */
    public function openReceivedTime(): void
    {
        $this->authorize('update', $this->lead);

        $this->correctedReceivedAt = $this->lead->received_at->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i');
        $this->receivedTimeReason = '';

        $this->resetValidation();

        Flux::modal('received-time')->show();
    }

    /**
     * Correct when the Lead was received. A reason is always needed.
     */
    public function correctReceivedTime(CorrectLeadReceivedTime $correctLeadReceivedTime): void
    {
        $this->authorize('update', $this->lead);

        $validated = $this->validate([
            'correctedReceivedAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'receivedTimeReason' => ['required', 'string', 'max:1000'],
        ], [
            'receivedTimeReason.required' => __('Say why the received time is being corrected.'),
        ], [
            'correctedReceivedAt' => 'received time',
            'receivedTimeReason' => 'reason',
        ]);

        $receivedAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['correctedReceivedAt'], config('app.display_timezone'));

        $correctLeadReceivedTime->handle($this->lead, $receivedAt->utc(), $this->receivedTimeReason);

        Flux::modal('received-time')->close();
        Flux::toast(variant: 'success', text: __('Received time corrected.'));
    }

    /**
     * Take the Lead off the customer it was linked to, when the link was wrong.
     * The customer and the inquiry are both left as they are.
     */
    public function unlinkCustomer(UnlinkLeadCustomer $unlinkLeadCustomer): void
    {
        $this->authorize('resolveCustomer', $this->lead);

        $unlinkLeadCustomer->handle($this->lead);

        Flux::toast(variant: 'success', text: __('This lead is no longer linked to a customer.'));
    }

    public function openUnlinkCustomer(): void
    {
        $this->lead->refresh();
        $this->authorize('resolveCustomer', $this->lead);

        abort_unless($this->lead->isLinkedToCustomer(), 404);

        $this->pendingUnlinkAccountId = $this->lead->account_id;
        $this->pendingUnlinkContactId = $this->lead->contact_id;
        $this->pendingUnlinkPropertyId = $this->lead->property_id;
        $this->resetValidation('customerChoice');

        Flux::modal('unlink-customer-confirmation')->show();
    }

    public function confirmUnlinkCustomer(UnlinkLeadCustomer $unlinkLeadCustomer): void
    {
        $this->lead->refresh();
        $this->authorize('resolveCustomer', $this->lead);

        if (
            $this->lead->account_id !== $this->pendingUnlinkAccountId
            || $this->lead->contact_id !== $this->pendingUnlinkContactId
            || $this->lead->property_id !== $this->pendingUnlinkPropertyId
        ) {
            throw ValidationException::withMessages([
                'customerChoice' => __('This lead\'s customer link was just changed by someone else. Review the current customer before removing it.'),
            ]);
        }

        $unlinkLeadCustomer->handle(
            $this->lead,
            $this->pendingUnlinkAccountId,
            $this->pendingUnlinkContactId,
            $this->pendingUnlinkPropertyId,
        );

        Flux::toast(variant: 'success', text: __('This lead is no longer linked to a customer.'));

        $this->reset('pendingUnlinkAccountId', 'pendingUnlinkContactId', 'pendingUnlinkPropertyId');
        Flux::modal('unlink-customer-confirmation')->close();
    }

    /**
     * Add a note to the Lead. It changes nothing else about the Lead.
     */
    public function addNote(AddNote $addNote): void
    {
        $this->authorize('create', [Note::class, $this->lead]);

        $this->validate(
            ['noteBody' => ['required', 'string', 'max:'.AddNote::MAX_LENGTH]],
            ['noteBody.required' => __('Write the note before adding it.')],
            ['noteBody' => 'note'],
        );

        $addNote->handle($this->lead, Auth::user(), $this->noteBody);

        $this->noteBody = '';

        unset($this->notes);

        Flux::toast(variant: 'success', text: __('Note added.'));
    }

    /**
     * Remove a note from view. Its author may, and so may someone who manages notes here.
     */
    public function openRemoveNote(int $noteId): void
    {
        $note = $this->lead->notes()->findOrFail($noteId)->setRelation('notable', $this->lead);

        $this->authorize('remove', $note);

        $this->pendingNoteId = $note->id;

        Flux::modal('remove-note-confirmation')->show();
    }

    public function confirmRemoveNote(RemoveNote $removeNote): void
    {
        $this->removeNote($this->pendingNoteId ?? abort(404), $removeNote);

        $this->reset('pendingNoteId');
        Flux::modal('remove-note-confirmation')->close();
    }

    public function removeNote(int $noteId, RemoveNote $removeNote): void
    {
        // Only a note that belongs to this Lead can be reached from this page.
        $note = $this->lead->notes()->findOrFail($noteId)->setRelation('notable', $this->lead);

        $this->authorize('remove', $note);

        $removeNote->handle($note, Auth::user());

        unset($this->notes);

        Flux::toast(variant: 'success', text: __('Note removed.'));
    }

    /**
     * The Lead's notes that are still in view, newest first.
     *
     * @return Collection<int, Note>
     */
    #[Computed]
    public function notes(): Collection
    {
        return $this->lead->notes()
            ->visible()
            ->with('author')
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->each(fn (Note $note) => $note->setRelation('notable', $this->lead));
    }

    /**
     * Open the manual attempt form with factual defaults, not inferred success.
     */
    public function openContactAttempt(): void
    {
        $this->lead->refresh();
        $this->authorize('update', $this->lead);

        $this->startContactAttempt($this->lead);

        Flux::modal('record-contact-attempt')->show();
    }

    /**
     * Append a manual evidence row. For a Lead in the call queue it also moves
     * the cadence, inside the same action (decision 67).
     */
    public function recordContactAttempt(RecordManualContactAttempt $recordManualContactAttempt): void
    {
        $this->lead->refresh();
        $this->authorize('update', $this->lead);

        $this->saveContactAttempt($this->lead, $recordManualContactAttempt);

        unset($this->contactAttempts, $this->cadence, $this->queueHistory);

        Flux::modal('record-contact-attempt')->close();
        Flux::toast(variant: 'success', text: __('Contact attempt recorded.'));
    }

    /**
     * The Lead's place in the call queue, for people who hand out Leads where
     * it is. Everyone else gets nothing, so the marker is not shown to them.
     */
    #[Computed]
    public function cadence(): ?LeadCadence
    {
        if (! Gate::allows('manageQueue', $this->lead)) {
            return null;
        }

        return LeadCadence::query()->with('holder')->where('lead_id', $this->lead->id)->first();
    }

    /**
     * Total attempts in the cadence as it is set now.
     */
    #[Computed]
    public function cadenceAttemptTotal(): int
    {
        return ContactCenterSettings::current()->totalAttempts();
    }

    /**
     * The Lead's queue history, newest first, for people who hand out Leads
     * where it is.
     *
     * @return Collection<int, LeadQueueEvent>
     */
    #[Computed]
    public function queueHistory(): Collection
    {
        if ($this->cadence === null) {
            return new Collection;
        }

        return LeadQueueEvent::query()
            ->where('lead_id', $this->lead->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * The timezone the Lead's queue times are judged in.
     */
    public function queueTimezone(): string
    {
        return CallingHours::timezoneFor($this->lead->location);
    }

    /**
     * Open the dialog for putting the Lead back in the call queue.
     */
    public function openPutBack(): void
    {
        $this->lead->refresh();
        $this->authorize('returnToQueue', $this->lead);

        $this->putBackNote = '';

        $this->resetValidation();
        Flux::modal('put-back-in-queue')->show();
    }

    /**
     * Put the Lead back in the call queue, with the required note. The Lead and
     * the person are resolved and authorized again by the action.
     */
    public function putBack(ReturnLeadToQueue $returnLeadToQueue): void
    {
        $this->lead->refresh();
        $this->authorize('returnToQueue', $this->lead);

        $this->resetValidation('putBackNote');

        try {
            $returnLeadToQueue->handle($this->lead, $this->putBackNote, Auth::user());
        } catch (ValidationException $exception) {
            // The action speaks of its "note". Here that is the note field.
            throw array_key_exists('note', $exception->errors())
                ? ValidationException::withMessages(['putBackNote' => $exception->errors()['note']])
                : $exception;
        } catch (\Throwable $exception) {
            if (! app(QueueTransaction::class)->isBusy($exception)) {
                throw $exception;
            }

            $this->addError('putBackNote', __('The system was busy, so nothing was saved. Please try again.'));

            return;
        }

        unset($this->cadence, $this->queueHistory);

        Flux::modal('put-back-in-queue')->close();
        Flux::toast(variant: 'success', text: __('Put back in the call queue.'));
    }

    /**
     * Visible attempt history, ordered by actual event time with a stable tie-breaker.
     *
     * @return Collection<int, ContactAttempt>
     */
    #[Computed]
    public function contactAttempts(): Collection
    {
        $user = Auth::user();

        return $this->lead->contactAttempts()
            ->visibleTo($user)
            ->with([
                'account' => fn ($query) => $query->visibleTo($user),
                'contact',
                'location',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The locations this person may send the Lead to: every active location for
     * someone who routes company-wide, otherwise only the locations they manage.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function destinations(): Collection
    {
        $user = Auth::user();

        $locations = $user->hasPermissionAcrossAllLocations(Permission::RouteLeads)
            ? Location::query()->active()->orderBy('name')->get()
            : $user->locationsWhere(Permission::TransferLeads);

        return $locations->reject(fn (Location $location): bool => $location->id === $this->lead->location_id)->values();
    }

    /**
     * The location whose service area includes the Lead's address, if exactly one does.
     */
    #[Computed]
    public function suggestedLocation(): ?Location
    {
        $address = StructuredAddress::ofLead($this->lead);

        return $address === null ? null : app(ServiceAreaResolver::class)->locationFor($address);
    }

    /**
     * Everyone who could be made responsible for this Lead: the people who can
     * already work it where it is.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function eligibleAssignees(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->canBeAssigned($this->lead))
            ->values();
    }

    /**
     * How many open Leads each eligible person already has, counting only the
     * Leads the person looking is allowed to see.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function openLeadCounts(): array
    {
        return Lead::query()
            ->visibleTo(Auth::user())
            // New, Working and Qualified, and Nurture once its follow-up is due. Not Nurture still ahead.
            ->actionable()
            ->whereIn('assigned_user_id', $this->eligibleAssignees->modelKeys())
            ->toBase()
            ->selectRaw('assigned_user_id, count(*) as open_leads')
            ->groupBy('assigned_user_id')
            ->pluck('open_leads', 'assigned_user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function rememberWhereTheLeadIs(): void
    {
        $this->knownLocationId = $this->lead->location_id;
        $this->knownAssigneeId = $this->lead->assigned_user_id;
    }

    /**
     * Refuse to act on a screen that is out of date: if someone else moved or
     * reassigned the Lead after the dialog was opened, nothing is saved.
     */
    private function leadHasChangedSinceTheDialogOpened(string $dialog): bool
    {
        if ($this->lead->location_id === $this->knownLocationId && $this->lead->assigned_user_id === $this->knownAssigneeId) {
            return false;
        }

        Flux::modal($dialog)->close();

        $this->addError('stale', __('This lead was just changed by someone else, so nothing was saved. Check where it is now and try again.'));

        return true;
    }

    /**
     * After a move, stay on the Lead if this person can still see it; otherwise
     * go back to the list, because it is no longer theirs to open.
     */
    private function afterTheLeadMoved(string $message): void
    {
        unset($this->destinations, $this->eligibleAssignees, $this->openLeadCounts);

        Flux::toast(variant: 'success', text: $message);

        if (! Auth::user()->can('view', $this->lead)) {
            $this->redirectRoute('leads', navigate: true);
        }
    }

    /**
     * Open the dialog for correcting the inquiry and its attribution.
     */
    public function edit(): void
    {
        $this->authorize('update', $this->lead);

        $this->name = (string) $this->lead->inquiry_name;
        $this->phone = (string) $this->lead->inquiry_phone;
        $this->email = (string) $this->lead->inquiry_email;
        $this->businessName = (string) $this->lead->inquiry_business_name;
        $this->message = (string) $this->lead->inquiry_message;
        $this->fillAddress(StructuredAddress::ofLead($this->lead));
        $this->channelId = $this->lead->lead_channel_id;
        $this->leadSourceId = $this->lead->lead_source_id;
        $this->marketingSourceId = $this->lead->marketing_source_id;
        $this->serviceIds = $this->lead->services->map(fn (Service $service): string => (string) $service->id)->values()->all();
        $this->primaryServiceId = $this->lead->primaryService() === null ? null : (string) $this->lead->primaryService()->id;
        $this->projectSizeId = $this->lead->project_size_id;

        $this->resetValidation();

        Flux::modal('edit-lead')->show();
    }

    /**
     * Save corrections. The Lead's status is never changed by this.
     */
    public function update(UpdateLeadDetails $updateLeadDetails): void
    {
        $this->authorize('update', $this->lead);

        $validated = $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'businessName' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            ...$this->addressRules(),
            'channelId' => ['required', 'integer', Rule::exists(LeadChannel::class, 'id')],
            'leadSourceId' => ['required', 'integer', Rule::exists(LeadSource::class, 'id')],
            'marketingSourceId' => ['required', 'integer', Rule::exists(MarketingSource::class, 'id')],
            'serviceIds' => ['required', 'array', 'min:1'],
            'serviceIds.*' => ['integer', Rule::exists(Service::class, 'id')],
            'projectSizeId' => ['required', 'integer', Rule::exists(ProjectSize::class, 'id')],
        ], [
            'serviceIds.required' => __('Choose at least one service.'),
            'serviceIds.min' => __('Choose at least one service.'),
            'projectSizeId.required' => __('Choose a project size. It is your best judgment, not an estimate.'),
        ], [
            'addressLine1' => 'street address',
            'addressLine2' => 'unit',
            'addressCity' => 'city',
            'addressState' => 'state',
            'addressPostalCode' => 'ZIP code',
            'businessName' => 'business name',
            'channelId' => 'lead channel',
            'leadSourceId' => 'lead source',
            'marketingSourceId' => 'marketing source',
            'serviceIds' => 'services',
            'serviceIds.*' => 'service',
            'projectSizeId' => 'project size',
        ]);

        $updateLeadDetails->handle(
            $this->lead,
            [
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'business_name' => $this->businessName,
                'message' => $this->message,
            ],
            LeadChannel::query()->findOrFail($validated['channelId']),
            LeadSource::query()->findOrFail($validated['leadSourceId']),
            MarketingSource::query()->findOrFail($validated['marketingSourceId']),
            ProjectSize::query()->findOrFail($validated['projectSizeId']),
            ServiceSelection::fromIds($this->serviceIds, $this->primaryServiceId),
            $this->structuredAddress(),
        );

        $this->lead->refresh();

        Flux::modal('edit-lead')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    #[Computed]
    public function phoneIsUnrecognized(): bool
    {
        return trim($this->phone) !== '' && PhoneNumber::normalize($this->phone) === null;
    }

    /**
     * @return Collection<int, LeadChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return $this->optionsIncluding(LeadChannel::class, $this->lead->lead_channel_id);
    }

    /**
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function leadSources(): Collection
    {
        return $this->optionsIncluding(LeadSource::class, $this->lead->lead_source_id);
    }

    /**
     * @return Collection<int, MarketingSource>
     */
    #[Computed]
    public function marketingSources(): Collection
    {
        return $this->optionsIncluding(MarketingSource::class, $this->lead->marketing_source_id);
    }

    /**
     * The services on offer, plus any this Lead already has even if since deactivated.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $this->lead->services->modelKeys()))
            ->ordered()
            ->get();
    }

    /**
     * @return Collection<int, ProjectSize>
     */
    #[Computed]
    public function projectSizes(): Collection
    {
        return ProjectSize::query()->ordered()->get();
    }

    /**
     * The choices on offer, plus the one this Lead already uses even if it has since been deactivated.
     *
     * @template TOption of ManagedListOption
     *
     * @param  class-string<TOption>  $class
     * @return Collection<int, TOption>
     */
    private function optionsIncluding(string $class, int $currentId): Collection
    {
        return $class::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentId))
            ->ordered()
            ->get();
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:link :href="route('leads')" wire:navigate class="text-sm">&larr; {{ __('Leads') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $lead->displayName() ?? __('No name given') }}</flux:heading>
                <x-lead-status :status="$lead->status" data-test="lead-status" />
                @if ($lead->isUnrouted())
                    <flux:badge size="sm" color="amber">{{ __('Unrouted') }}</flux:badge>
                @endif
            </div>

            <flux:subheading>
                {{ __('Received') }} <x-local-time :at="$lead->received_at" />
                &middot;
                {{ $lead->location?->name ?? __('Not yet routed to a location') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('update', $lead)
                <flux:button wire:click="edit" icon="pencil-square" data-test="edit-lead">{{ __('Correct details') }}</flux:button>
            @endcan

            @if ($lead->status === LeadStatus::New)
                @can('startWorking', $lead)
                    <flux:button variant="primary" wire:click="startWorking" data-test="start-working">
                        {{ $lead->isAssigned() ? __('Start working') : __('Start working (assigns it to you)') }}
                    </flux:button>
                @endcan
            @endif

            @can('changeStatus', $lead)
                @if ($lead->status === LeadStatus::Working)
                    <flux:button variant="primary" wire:click="openQualify" data-test="qualify-lead">{{ __('Qualify') }}</flux:button>
                @endif

                @if ($lead->status === LeadStatus::Nurture)
                    <flux:button variant="primary" wire:click="openReturnToWorking" data-test="resume-working">{{ __('Resume working') }}</flux:button>
                    <flux:button wire:click="openNurture" data-test="reschedule-follow-up">{{ __('Reschedule follow-up') }}</flux:button>
                @endif

                @if ($lead->status === LeadStatus::Qualified)
                    <flux:button
                        wire:click="openReturnToWorking"
                        data-test="return-to-working"
                    >
                        {{ __('Return to working') }}
                    </flux:button>
                @endif

                @if ($lead->status === LeadStatus::Working || $lead->status === LeadStatus::Qualified)
                    <flux:button wire:click="openNurture" data-test="nurture-lead">{{ __('Schedule follow-up') }}</flux:button>
                @endif

                <flux:button variant="ghost" wire:click="openDisqualify" data-test="disqualify-lead">{{ __('Disqualify') }}</flux:button>
            @endcan

            @can('reopen', $lead)
                <flux:button variant="primary" wire:click="openReopen" data-test="reopen-lead">{{ __('Reopen') }}</flux:button>
                <flux:button wire:click="openCorrectDisqualification" data-test="correct-disqualification">{{ __('Correct reason') }}</flux:button>
            @endcan
        </div>
    </div>

    @if ($lead->status === LeadStatus::Qualified)
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-ink" data-test="lead-qualified">
            <span class="font-semibold">{{ __('Qualified') }}</span>
            <span>{{ __('Qualification milestone only; not booked, Lead Issued, demoed, sold or Project-created.') }}</span>
            @if ($lead->first_qualified_at)
                &middot; {{ __('first qualified') }} <x-local-time :at="$lead->first_qualified_at" />
            @endif
        </div>
    @elseif ($lead->status === LeadStatus::Nurture)
        <div class="rounded-lg border p-4 text-sm text-ink {{ $lead->followUpIsDue() ? 'border-amber-300 bg-amber-50' : 'border-zinc-200 bg-card' }}" data-test="lead-follow-up">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold">{{ __('Follow up on :date', ['date' => $lead->follow_up_on?->format('M j, Y')]) }}</span>

                @if ($lead->daysOverdue() > 0)
                    <flux:badge size="sm" color="red" data-test="follow-up-overdue">{{ trans_choice('{1} Overdue by 1 day|[2,*] Overdue by :count days', $lead->daysOverdue()) }}</flux:badge>
                @elseif ($lead->followUpIsDue())
                    <flux:badge size="sm" color="amber" data-test="follow-up-due">{{ __('Follow-up due') }}</flux:badge>
                @endif
            </div>

            <div class="mt-1 whitespace-pre-line">{{ $lead->follow_up_note }}</div>
        </div>
    @elseif ($lead->status === LeadStatus::Disqualified)
        <div class="rounded-lg border border-zinc-300 bg-card p-4 text-sm text-ink" data-test="lead-disqualified">
            <div>
                <span class="font-semibold">{{ __('Disqualified: :reason', ['reason' => $lead->disqualificationReason?->name ?? __('no reason recorded')]) }}</span>
                @if ($lead->last_disqualified_at)
                    &middot; <x-local-time :at="$lead->last_disqualified_at" />
                @endif
            </div>

            @if ($lead->duplicateOf)
                <div class="mt-1" data-test="lead-duplicate-of">
                    {{ __('A duplicate of') }}
                    @can('view', $lead->duplicateOf)
                        <a href="{{ route('leads.show', $lead->duplicateOf) }}" wire:navigate class="underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $lead->duplicateOf->displayName() ?? __('another lead') }}</a>
                        ({{ __('received') }} <x-local-time :at="$lead->duplicateOf->received_at" />)
                    @else
                        {{ __('a lead you cannot open') }}
                    @endcan
                </div>
            @endif

            @if ($lead->disqualification_note)
                <div class="mt-1 whitespace-pre-line">{{ $lead->disqualification_note }}</div>
            @endif
        </div>
    @endif

    @error('status')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="status-refused">{{ $message }}</div>
    @enderror

    @error('stale')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="lead-stale">{{ $message }}</div>
    @enderror

    @error('lead')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink">{{ $message }}</div>
    @enderror

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-6 md:col-span-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
            <div>
                <flux:heading size="lg" level="2">{{ __('The inquiry') }}</flux:heading>
                <flux:subheading>{{ __('As it was received. Linking this lead to a customer later will not change it.') }}</flux:subheading>
            </div>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Name') }}</dt>
                    <dd class="text-ink">{{ $lead->inquiry_name ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Business name') }}</dt>
                    <dd class="text-ink">{{ $lead->inquiry_business_name ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Phone') }}</dt>
                    <dd class="tabular-nums text-ink">
                        @if ($lead->inquiry_phone !== null)
                            <a href="tel:{{ $lead->inquiry_phone_normalized ?? $lead->inquiry_phone }}" class="text-wall-teal underline">{{ $lead->displayPhone() }}</a>
                            @if ($lead->displayPhone() !== $lead->inquiry_phone)
                                <div class="text-sm text-zinc-500">{{ __('Typed as :typed', ['typed' => $lead->inquiry_phone]) }}</div>
                            @endif
                        @else
                            —
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Email') }}</dt>
                    <dd class="break-all text-ink">
                        @if ($lead->inquiry_email !== null)
                            <a href="mailto:{{ $lead->inquiry_email }}" class="text-wall-teal underline">{{ $lead->inquiry_email }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Service(s)') }}</dt>
                    <dd class="text-ink" data-test="lead-services">
                        @forelse ($lead->services as $service)
                            <div>
                                {{ $service->name }}
                                @if ($lead->services->count() > 1 && $service->is($lead->primaryService()))
                                    <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Main') }}</flux:badge>
                                @endif
                            </div>
                        @empty
                            —
                        @endforelse
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Project size') }}</dt>
                    <dd class="text-ink" data-test="lead-size">
                        @if ($lead->projectSize)
                            {{ $lead->projectSize->name }}
                            <div class="text-sm tabular-nums text-zinc-500">{{ __(':range when classified', ['range' => $lead->projectSizeRangeLabel()]) }}</div>
                        @else
                            {{ __('Not classified') }}
                        @endif
                    </dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500">{{ __('About what we\'re painting') }}</dt>
                    <dd class="whitespace-pre-line text-ink">{{ $lead->inquiry_message ?? '—' }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500">{{ __('Property address') }}</dt>
                    <dd class="text-ink" data-test="lead-address">
                        @if ($lead->inquiry_address !== null)
                            {{ $lead->inquiry_address }}
                            <div class="text-sm text-zinc-500">
                                @if ($lead->address_entry === 'lookup')
                                    {{ __('Found by address lookup') }}
                                @else
                                    {{ __('Typed by hand, so it has no map position') }}
                                @endif
                            </div>
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        @if ($this->cadence !== null)
            @php($cadence = $this->cadence)
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="cadence-panel">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Call queue') }}</flux:heading>
                        <flux:subheading>{{ __('Where this lead stands in the phone cadence. Holding a lead in the queue does not assign it to anyone.') }}</flux:subheading>
                    </div>

                    @can('returnToQueue', $lead)
                        <flux:button size="sm" wire:click="openPutBack" data-test="put-back-in-queue">{{ __('Put back in queue') }}</flux:button>
                    @endcan
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <flux:badge size="sm" :color="$cadence->state === \App\Enums\CadenceState::FinishedNoContact ? 'amber' : 'zinc'" data-test="cadence-state">{{ $cadence->state->label() }}</flux:badge>
                    <span class="text-sm text-zinc-500">
                        {{ __(':made of :total attempts made (cycle :cycle)', ['made' => $cadence->attempts_made, 'total' => $this->cadenceAttemptTotal, 'cycle' => $cadence->cycle]) }}
                    </span>
                </div>

                @if ($cadence->isInQueue() && $cadence->due_at !== null)
                    <flux:text data-test="cadence-due">
                        {{ __('Next attempt due') }} <x-queue-time :at="$cadence->due_at" :timezone="$this->queueTimezone()" />
                    </flux:text>
                @endif

                @if ($cadence->isHeldAt(now()))
                    <flux:text data-test="cadence-held">{{ __('Held by :name until', ['name' => $cadence->holder?->name ?? __('a setter')]) }} <x-queue-time :at="$cadence->held_until" :timezone="$this->queueTimezone()" /></flux:text>
                @endif

                <div class="flex flex-col gap-2" data-test="queue-history">
                    <flux:heading size="sm" level="3">{{ __('Queue history') }}</flux:heading>

                    @if ($this->queueHistory->isEmpty())
                        <flux:text>{{ __('Nothing has happened in the queue yet.') }}</flux:text>
                    @else
                        <ul class="divide-y divide-zinc-200">
                            @foreach ($this->queueHistory as $event)
                                <li wire:key="queue-event-{{ $event->id }}" class="flex flex-col gap-1 py-2 text-sm first:pt-0 last:pb-0" data-test="queue-event">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-semibold text-ink">{{ $event->type->label() }}</span>
                                        <span class="text-zinc-500"><x-local-time :at="$event->created_at" /></span>
                                    </div>
                                    <div class="text-zinc-700">
                                        @if ($event->reason !== null)
                                            {{ match (true) {
                                                $event->type === \App\Enums\QueueEventType::Skipped => \App\Enums\QueueSkipReason::tryFrom($event->reason)?->label() ?? $event->reason,
                                                $event->type === \App\Enums\QueueEventType::HoldEnded => \App\Enums\QueueHoldEndReason::tryFrom($event->reason)?->label() ?? $event->reason,
                                                default => str_replace('_', ' ', ucfirst($event->reason)),
                                            } }}
                                        @endif
                                        @if ($event->note !== null)
                                            <span class="whitespace-pre-line">&ldquo;{{ $event->note }}&rdquo;</span>
                                        @endif
                                        @if ($event->actor_name !== null)
                                            @if ($event->reason !== null || $event->note !== null) &middot; @endif
                                            {{ $event->actor_name }}@if ($event->impersonator_name !== null) ({{ __('by :owner, viewing as them', ['owner' => $event->impersonator_name]) }})@endif
                                        @endif
                                        @if ($event->reason !== null || $event->note !== null || $event->actor_name !== null) &middot; @endif
                                        {{ __('attempt :step', ['step' => $event->step]) }}
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="contact-attempts">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Contact attempts') }}</flux:heading>
                    <flux:subheading>{{ __('A factual history of calls and messages. Manual entries are never provider-verified and do not change the lead lifecycle.') }}</flux:subheading>
                </div>

                @can('update', $lead)
                    <flux:button size="sm" variant="primary" wire:click="openContactAttempt" data-test="record-contact-attempt">
                        {{ __('Record attempt') }}
                    </flux:button>
                @endcan
            </div>

            @if ($this->contactAttempts->isEmpty())
                <flux:text data-test="no-contact-attempts">{{ __('No contact attempts recorded yet.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->contactAttempts as $attempt)
                        <li wire:key="contact-attempt-{{ $attempt->id }}" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0" data-test="contact-attempt">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-ink">{{ $attempt->disposition->label() }}</span>
                                    <flux:badge size="sm" color="zinc">{{ $attempt->source->label() }}</flux:badge>
                                    <flux:badge size="sm" color="zinc">{{ $attempt->direction->label() }} {{ $attempt->channel->label() }}</flux:badge>
                                </div>

                                <span class="text-sm text-zinc-500"><x-local-time :at="$attempt->occurred_at" /></span>
                            </div>

                            <dl class="grid gap-x-5 gap-y-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-zinc-500">{{ __('Responsible rep') }}</dt>
                                    <dd class="text-ink">{{ $attempt->responsible_user_name }}</dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500">{{ __('Recorded by') }}</dt>
                                    <dd class="text-ink">{{ $attempt->recordedBy() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500">{{ __('Answered / response received') }}</dt>
                                    <dd class="text-ink">{{ $attempt->was_answered ? __('Yes') : __('No') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500">{{ __('Real two-way connection') }}</dt>
                                    <dd class="text-ink">{{ $attempt->was_connected ? __('Yes — manually reported') : __('No') }}</dd>
                                </div>
                                @if ($attempt->displayNumber() !== null)
                                    <div>
                                        <dt class="text-zinc-500">{{ __('Number') }}</dt>
                                        <dd class="tabular-nums text-ink">{{ $attempt->displayNumber() }}</dd>
                                    </div>
                                @endif
                                @if ($attempt->durationLabel() !== null)
                                    <div>
                                        <dt class="text-zinc-500">{{ __('Duration') }}</dt>
                                        <dd class="tabular-nums text-ink">{{ $attempt->durationLabel() }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="text-zinc-500">{{ __('Lead lifecycle') }}</dt>
                                    <dd class="text-ink">{{ $attempt->lifecycle_result->label() }} &middot; {{ $attempt->lead_status->label() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500">{{ __('Location at the time') }}</dt>
                                    <dd class="text-ink">{{ $attempt->location?->name ?? __('Central (not yet routed)') }}</dd>
                                </div>
                                @if ($attempt->contact !== null && $attempt->account !== null)
                                    <div class="sm:col-span-2">
                                        <dt class="text-zinc-500">{{ __('Linked contact at the time') }}</dt>
                                        <dd class="text-ink">{{ $attempt->contact->fullName() ?? __('Unnamed contact') }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-notes">
            <div>
                <flux:heading size="lg" level="2">{{ __('Notes') }}</flux:heading>
                <flux:subheading>{{ __('What the team should know, in your own words. A note cannot be edited once it is added.') }}</flux:subheading>
            </div>

            @can('create', [Note::class, $lead])
                <form wire:submit="addNote" class="flex flex-col gap-3">
                    <flux:textarea
                        wire:model="noteBody"
                        rows="3"
                        :label="__('New note')"
                        :placeholder="__('For example: prefers a call after 5 PM')"
                    />

                    <div>
                        <flux:button type="submit" variant="primary" data-test="add-note">{{ __('Add note') }}</flux:button>
                    </div>
                </form>
            @endcan

            @if ($this->notes->isEmpty())
                <flux:text data-test="no-notes">{{ __('No notes yet.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->notes as $note)
                        <li wire:key="note-{{ $note->id }}" class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0" data-test="lead-note">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="text-sm">
                                    <span class="font-semibold text-ink">{{ $note->author?->name ?? __('Unknown') }}</span>
                                    <span class="text-zinc-500">&middot; <x-local-time :at="$note->created_at" /></span>
                                </div>

                                @can('remove', $note)
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        wire:click="openRemoveNote({{ $note->id }})"
                                        data-test="remove-note"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endcan
                            </div>

                            <div class="whitespace-pre-line break-words text-ink">{{ $note->body }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        </div>

        <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-customer">
            <flux:heading size="lg" level="2">{{ __('Customer') }}</flux:heading>

            @error('customerChoice')
                <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-ink">{{ $message }}</div>
            @enderror

            @if ($lead->account)
                <dl class="flex flex-col gap-3">
                    <div>
                        <dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt>
                        <dd class="flex flex-wrap items-center gap-2 text-ink" data-test="lead-customer-name">
                            @can('view', $lead->account)
                                <a href="{{ route('customers.show', $lead->account) }}" wire:navigate class="font-semibold underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $lead->account->display_name }}</a>
                                <x-account-type :type="$lead->account->type" />
                                <a
                                    href="{{ route('customers.workspace', ['account' => $lead->account, 'from' => 'lead', 'lead' => $lead->id]) }}"
                                    wire:navigate
                                    class="text-sm font-medium text-wall-teal underline decoration-zinc-300 underline-offset-2 hover:decoration-wall-teal"
                                    data-test="customer-workspace"
                                >
                                    {{ __('Activity & notes') }}
                                </a>
                            @else
                                {{ __('Linked to a customer') }}
                            @endcan
                        </dd>
                    </div>

                    @can('view', $lead->account)
                        <div>
                            <dt class="text-sm text-zinc-500">{{ __('Contact') }}</dt>
                            <dd class="text-ink">{{ $lead->contact?->fullName() ?? __('No contact yet') }}</dd>
                        </div>

                        <div>
                            <dt class="text-sm text-zinc-500">{{ __('Property') }}</dt>
                            <dd class="text-ink">{{ $lead->property?->summary() ?? __('No property yet') }}</dd>
                        </div>
                    @endcan
                </dl>

                @if ($lead->isActive())
                    @can('resolveCustomer', $lead)
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" :href="route('leads.customer', $lead)" wire:navigate data-test="change-customer">{{ __('Change') }}</flux:button>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="openUnlinkCustomer"
                                data-test="unlink-customer"
                            >
                                {{ __('Remove link') }}
                            </flux:button>
                        </div>
                    @endcan
                @endif
            @else
                <flux:text data-test="lead-customer-none">{{ __('Not linked to a customer yet.') }}</flux:text>

                @if ($lead->isActive())
                    @can('resolveCustomer', $lead)
                        <div>
                            <flux:button size="sm" variant="primary" :href="route('leads.customer', $lead)" wire:navigate data-test="resolve-customer">{{ __('Resolve customer') }}</flux:button>
                        </div>
                    @endcan
                @endif
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-ownership">
            <flux:heading size="lg" level="2">{{ __('Who has it') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Location') }}</dt>
                    <dd class="text-ink" data-test="lead-location">{{ $lead->location?->name ?? __('Central (not yet routed)') }}</dd>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @can('routeAnywhere', $lead)
                            <flux:button size="sm" wire:click="openRouting" data-test="route-lead">
                                {{ $lead->isUnrouted() ? __('Route to a location') : __('Change location') }}
                            </flux:button>
                        @endcan

                        @can('returnToCentral', $lead)
                            <flux:button size="sm" variant="ghost" wire:click="openReturn" data-test="return-lead">{{ __('Return to central') }}</flux:button>
                        @endcan
                    </div>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Assigned to') }}</dt>
                    <dd class="text-ink" data-test="lead-assignee">
                        @if ($lead->assignee)
                            {{ $lead->assignee->name }}
                            @if ($lead->assigned_at !== null)
                                <div class="text-sm text-zinc-500">{{ __('since') }} <x-local-time :at="$lead->assigned_at" /></div>
                            @endif
                        @else
                            {{ __('Nobody yet') }}
                        @endif
                    </dd>

                    @can('assign', $lead)
                        <div class="mt-2">
                            <flux:button size="sm" wire:click="openAssign" data-test="assign-lead">
                                {{ $lead->isAssigned() ? __('Reassign') : __('Assign') }}
                            </flux:button>
                        </div>
                    @elseif (! $lead->isAssigned())
                        <div class="text-sm text-zinc-500" data-test="awaiting-assignment">{{ __('A manager or the call center decides who works it.') }}</div>
                    @endcan
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Received') }}</dt>
                    <dd class="text-ink"><x-local-time :at="$lead->received_at" /></dd>

                    @can('update', $lead)
                        <div class="mt-2">
                            <flux:button size="sm" variant="ghost" wire:click="openReceivedTime" data-test="correct-received-time">{{ __('Correct the time') }}</flux:button>
                        </div>
                    @endcan
                </div>
            </dl>
        </div>

        <livewire:pages::leads.appointment :lead="$lead" :key="'appointment-'.$lead->id" />

        <livewire:pages::leads.proposals :lead="$lead" :key="'proposals-'.$lead->id" />

        <livewire:pages::leads.estimates :lead="$lead" :key="'estimates-'.$lead->id" />

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
            <flux:heading size="lg" level="2">{{ __('Where it came from') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Lead channel') }}</dt>
                    <dd class="text-ink">{{ $lead->channel->name }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Lead source') }}</dt>
                    <dd class="text-ink">{{ $lead->leadSource->name }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Marketing source') }}</dt>
                    <dd class="text-ink">{{ $lead->marketingSource->name }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt>
                    <dd class="text-ink">{{ $lead->capturedBy?->name ?? '—' }}</dd>
                </div>
            </dl>
        </div>
        </div>
    </div>

    @include('partials.contact-attempt-form', ['phoneCallOnly' => false])

    <flux:modal name="edit-lead" class="w-full max-w-2xl">
        <form wire:submit="update" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Correct details') }}</flux:heading>
                <flux:subheading>{{ __('Each correction is recorded with what it was before. This does not change the lead\'s status.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" type="text" autocomplete="off" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <flux:input wire:model.blur="phone" :label="__('Phone')" type="tel" inputmode="tel" autocomplete="off" />

                    @if ($this->phoneIsUnrecognized)
                        <flux:text class="text-sm text-amber-700">
                            {{ __('This does not look like a complete phone number. It will be saved exactly as typed.') }}
                        </flux:text>
                    @endif
                </div>

                <flux:input wire:model="email" :label="__('Email')" type="email" inputmode="email" autocomplete="off" />
            </div>

            <flux:input wire:model="businessName" :label="__('Business name')" type="text" autocomplete="off" />

            <x-lead.service-picker :services="$this->services" />

            <x-lead.size-picker :sizes="$this->projectSizes" />

            <flux:textarea wire:model="message" :label="__('Tell us about what we\'re painting today')" rows="3" />

            <x-lead.address-picker
                :mode="$addressMode"
                :suggestions="$addressSuggestions"
                :selected="$selectedAddress"
                :available="$this->addressLookupIsAvailable()"
                :failed="$addressLookupFailed"
            />

            <div class="grid gap-5 sm:grid-cols-3">
                <flux:select wire:model="channelId" :label="__('Lead channel')">
                    @foreach ($this->channels as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="leadSourceId" :label="__('Lead source')">
                    @foreach ($this->leadSources as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="marketingSourceId" :label="__('Marketing source')">
                    @foreach ($this->marketingSources as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @can('routeAnywhere', $lead)
    <flux:modal name="route-lead" class="w-full max-w-lg">
        <form wire:submit="route" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $lead->isUnrouted() ? __('Route to a location') : __('Change location') }}</flux:heading>
                <flux:subheading>
                    @if ($lead->isAssigned())
                        {{ __('This is a handoff: :name will no longer be assigned, and the new location decides who works it.', ['name' => $lead->assignee?->name]) }}
                    @else
                        {{ __('The location decides who works it. Its status does not change.') }}
                    @endif
                </flux:subheading>
            </div>

            @if ($this->destinations->isEmpty())
                <flux:text>{{ __('There is no other location you can send this lead to.') }}</flux:text>
            @else
                <flux:select wire:model="destinationId" :label="__('Location')" data-test="route-destination">
                    <flux:select.option value="">{{ __('Choose a location') }}</flux:select.option>
                    @foreach ($this->destinations as $destination)
                        <flux:select.option :value="$destination->id" wire:key="destination-{{ $destination->id }}">{{ $destination->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->suggestedLocation !== null && $this->destinations->contains($this->suggestedLocation))
                    <flux:text class="text-sm" data-test="route-suggestion">
                        {{ __('Suggested: :location, because ZIP code :zip is in its service area. You can choose another.', ['location' => $this->suggestedLocation->name, 'zip' => $lead->address_postal_code]) }}
                    </flux:text>
                @elseif ($lead->address_postal_code === null)
                    <flux:text class="text-sm">{{ __('No location is suggested because this lead has no ZIP code.') }}</flux:text>
                @endif

                <flux:textarea
                    wire:model="routingReason"
                    rows="2"
                    :label="$lead->isUnrouted() ? __('Reason (optional)') : __('Reason')"
                    :description="$lead->isUnrouted() ? null : __('Required when a lead moves from one location to another.')"
                />

                <x-lead.issued-count :lead="$lead" goes-back />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                @if ($this->destinations->isNotEmpty())
                    <flux:button type="submit" variant="primary" data-test="route-submit">{{ $lead->isUnrouted() ? __('Route') : __('Move') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('returnToCentral', $lead)
    <flux:modal name="return-lead" class="w-full max-w-lg">
        <form wire:submit="returnToCentral" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Return to central') }}</flux:heading>
                <flux:subheading>
                    {{ __('The lead leaves :location and waits centrally to be routed again. Nobody stays assigned to it.', ['location' => $lead->location?->name]) }}
                    @cannot(Permission::ViewUnroutedLeads)
                        {{ __('You will no longer be able to open it.') }}
                    @endcannot
                </flux:subheading>
            </div>

            <flux:textarea wire:model="returnReason" rows="2" :label="__('Reason')" />

            <x-lead.issued-count :lead="$lead" goes-back />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="return-submit">{{ __('Return to central') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('assign', $lead)
    <flux:modal name="assign-lead" class="w-full max-w-lg">
        <form wire:submit="assign" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $lead->isAssigned() ? __('Reassign') : __('Assign') }}</flux:heading>
                <flux:subheading>{{ __('Only people who can already work leads here are listed. Assigning gives nobody new access.') }}</flux:subheading>
            </div>

            @if ($this->eligibleAssignees->isEmpty())
                <flux:text data-test="no-eligible-assignees">{{ __('Nobody can work leads here yet. Give someone access to this location first.') }}</flux:text>
            @else
                <fieldset class="flex flex-col gap-2" data-test="assignee-options">
                    <legend class="sr-only">{{ __('Who will work this lead') }}</legend>

                    @foreach ($this->eligibleAssignees as $person)
                        <label wire:key="assignee-{{ $person->id }}" class="cursor-pointer">
                            <input type="radio" wire:model="assigneeId" name="assigneeId" value="{{ $person->id }}" class="peer sr-only" />
                            <span class="flex items-center justify-between gap-3 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-medium">
                                    {{ $person->name }}
                                    @if ($person->is(auth()->user()))
                                        <span class="font-normal text-zinc-700">({{ __('you') }})</span>
                                    @endif
                                </span>
                                <span class="text-sm tabular-nums text-zinc-700">
                                    {{ trans_choice('{0} no open leads|{1} 1 open lead|[2,*] :count open leads', $this->openLeadCounts[$person->id] ?? 0) }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                <flux:error name="assigneeId" />
            @endif

            <div class="flex flex-wrap justify-between gap-2">
                <div>
                    @if ($lead->isAssigned())
                        <flux:button variant="ghost" wire:click="unassign" data-test="unassign-lead">{{ __('Leave unassigned') }}</flux:button>
                    @endif
                </div>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    @if ($this->eligibleAssignees->isNotEmpty())
                        <flux:button type="submit" variant="primary" data-test="assign-submit">{{ __('Assign') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('update', $lead)
    <flux:modal name="received-time" class="w-full max-w-lg">
        <form wire:submit="correctReceivedTime" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Correct the received time') }}</flux:heading>
                <flux:subheading>{{ __('Response times are measured from this moment, so a correction is recorded with your reason.') }}</flux:subheading>
            </div>

            <flux:input wire:model="correctedReceivedAt" type="datetime-local" :label="__('Received')" />

            <flux:textarea wire:model="receivedTimeReason" rows="2" :label="__('Reason')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="received-time-submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan
    @can('changeStatus', $lead)
        <flux:modal name="qualify-lead" class="w-full max-w-lg">
            <form wire:submit="qualify" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <div>
                    <flux:heading size="lg">{{ __('Qualify this lead') }}</flux:heading>
                    <flux:subheading>{{ __('This records the qualification milestone only. It does not mean booked, Lead Issued, demoed, sold or Project-created.') }}</flux:subheading>
                </div>

                @if ($this->qualificationGaps === [])
                    <flux:text data-test="qualification-ready">{{ __('Everything needed is here: a location, someone assigned, a customer with a contact and a property, a service and a project size.') }}</flux:text>
                @else
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="qualification-gaps">
                        <div class="font-semibold">{{ __('This lead cannot be qualified yet. It still needs:') }}</div>
                        <ul class="mt-2 list-disc ps-5">
                            @foreach ($this->qualificationGaps as $gap)
                                <li wire:key="gap-{{ $loop->index }}">{{ $gap }}</li>
                            @endforeach
                        </ul>

                        @if (array_intersect(['customer', 'contact', 'property'], $lead->qualificationGaps()) !== [])
                            @can('resolveCustomer', $lead)
                                <div class="mt-3">
                                    <a href="{{ route('leads.customer', $lead) }}" wire:navigate class="font-semibold underline underline-offset-2" data-test="qualification-link-customer">{{ __('Link this lead to a customer, contact and property') }}</a>
                                </div>
                            @endcan
                        @endif
                    </div>
                @endif

                <flux:error name="qualification" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    @if ($this->qualificationGaps === [])
                        <flux:button type="submit" variant="primary" data-test="qualify-submit">{{ __('Qualify') }}</flux:button>
                    @endif
                </div>
            </form>
        </flux:modal>

        <flux:modal name="return-to-working" class="w-full max-w-lg">
            <form wire:submit="resumeWorking" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <div>
                    <flux:heading size="lg">{{ __('Return this lead to working?') }}</flux:heading>
                    <flux:subheading>
                        @if ($lead->status === LeadStatus::Qualified)
                            {{ __('It will no longer count as qualified now, though it stays on record as having been qualified. Whoever is assigned keeps it.') }}
                        @else
                            {{ __('The scheduled follow-up will clear, and whoever is assigned keeps the lead. The previous follow-up stays in its history.') }}
                        @endif
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="return-to-working-submit">{{ __('Return to working') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="nurture-lead" class="w-full max-w-lg">
            <form wire:submit="saveNurture" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <div>
                    <flux:heading size="lg">{{ $lead->status === LeadStatus::Nurture ? __('Reschedule follow-up') : __('Schedule follow-up') }}</flux:heading>
                    <flux:subheading>{{ __('Schedule a manual callback with a date and note. It stays with whoever is assigned and shows as due on that date. This does not enroll the lead in automated outreach.') }}</flux:subheading>
                </div>

                <flux:input wire:model="followUpOn" type="date" :label="__('Follow up on')" :min="\App\Models\Lead::today()" />

                <flux:textarea wire:model="followUpNote" rows="3" :label="__('What to follow up about')" :placeholder="__('For example: wants a quote once the roof is finished in March')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="nurture-submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @canany(['changeStatus', 'reopen'], $lead)
        <flux:modal name="disqualify-lead" class="w-full max-w-xl">
            <form wire:submit="disqualify" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <div>
                    <flux:heading size="lg">{{ $correctingDisqualification ? __('Correct the reason') : __('Disqualify this lead') }}</flux:heading>
                    <flux:subheading>
                        @if ($correctingDisqualification)
                            {{ __('The lead will show the corrected reason. What was recorded first stays in its history.') }}
                        @else
                            {{ __('For an inquiry that is not a real opportunity. The inquiry, where it came from and its customer are all kept. Nobody stays assigned to it.') }}
                        @endif
                    </flux:subheading>
                </div>

                <flux:select wire:model.live="disqualificationReasonId" :label="__('Reason')" data-test="disqualification-reason">
                    <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
                    @foreach ($this->disqualificationReasons as $reason)
                        <flux:select.option :value="$reason->id" wire:key="reason-{{ $reason->id }}">{{ $reason->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->chosenReason?->isUnableToContact())
                    <flux:text>{{ __('This historical reason can be corrected or the lead reopened. It is no longer offered for new disqualifications.') }}</flux:text>
                @endif

                @if (! $correctingDisqualification && in_array($lead->status, [LeadStatus::Working, LeadStatus::Qualified, LeadStatus::Nurture], true))
                    <flux:text>{{ __('No response alone is not a reason to disqualify. For a deliberate callback, schedule a follow-up.') }}</flux:text>
                    <flux:button size="sm" type="button" wire:click="tryAgainLater" data-test="try-again-later">{{ __('Schedule follow-up instead') }}</flux:button>
                @endif

                @if ($this->chosenReason?->isDuplicate())
                    <fieldset class="flex flex-col gap-2" data-test="duplicate-originals">
                        <legend class="text-sm font-medium text-ink">{{ __('Which lead does this one repeat?') }}</legend>

                        <flux:text class="text-sm">{{ __('A duplicate is the same inquiry recorded twice. A new inquiry from an existing customer is not a duplicate.') }}</flux:text>

                        @foreach ($this->possibleOriginals as $original)
                            <label wire:key="original-{{ $original->id }}" class="cursor-pointer">
                                <input type="radio" wire:model="duplicateOfId" name="duplicateOfId" value="{{ $original->id }}" class="peer sr-only" />
                                <span class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                    <span>
                                        <span class="font-semibold">{{ $original->displayName() ?? __('No name given') }}</span>
                                        <span class="text-sm text-zinc-700">{{ $original->primaryService()?->name }}</span>
                                    </span>
                                    <span class="text-sm text-zinc-700">
                                        {{ __('received') }} <x-local-time :at="$original->received_at" />
                                        &middot; {{ $original->location?->name ?? __('Unrouted') }}
                                        &middot; {{ $original->status->label() }}
                                    </span>
                                </span>
                            </label>
                        @endforeach

                        <label class="cursor-pointer">
                            <input type="radio" wire:model="duplicateOfId" name="duplicateOfId" value="" class="peer sr-only" />
                            <span class="flex flex-col rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-semibold">{{ __('The original cannot be identified, or I cannot open it') }}</span>
                                <span class="text-sm text-zinc-700">{{ __('Explain in the note below.') }}</span>
                            </span>
                        </label>

                        <flux:error name="duplicateOfId" />
                    </fieldset>
                @endif

                <flux:textarea
                    wire:model="disqualificationNote"
                    rows="3"
                    :label="match (true) {
                        (bool) $this->chosenReason?->isUnableToContact() => __('Historical explanation (required)'),
                        (bool) $this->chosenReason?->needsNote() => __('Explanation (required)'),
                        default => __('Note (optional)'),
                    }"
                />

                @if (! $correctingDisqualification)
                    <x-lead.issued-count :lead="$lead" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="disqualify-submit">{{ $correctingDisqualification ? __('Save') : __('Disqualify') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcanany

    @can('reopen', $lead)
        <flux:modal name="reopen-lead" class="w-full max-w-lg">
            <form wire:submit="reopen" class="flex flex-col gap-5">
                <flux:error name="stale" />
                <div>
                    <flux:heading size="lg">{{ __('Reopen this lead') }}</flux:heading>
                    <flux:subheading>{{ __('It comes back as Working with nobody assigned, and is handed out again in the usual way. Why it was disqualified stays in its history.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="reopenReason" rows="3" :label="__('Why it is being reopened')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="reopen-submit">{{ __('Reopen') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @can('returnToQueue', $lead)
        <x-confirmation-modal
            name="put-back-in-queue"
            :title="__('Put this lead back in the call queue?')"
            :description="__('It becomes due at the next opening of calling hours and is offered to setters again. Its status and who it is assigned to do not change.')"
            action="putBack"
            :confirm-label="__('Put back in queue')"
            confirm-variant="primary"
        >
            <flux:textarea wire:model="putBackNote" rows="3" :label="__('Why it is going back (required)')" data-test="put-back-note" />
        </x-confirmation-modal>
    @endcan

    <x-confirmation-modal
        name="remove-note-confirmation"
        :title="__('Remove this note?')"
        :description="__('It will no longer be shown on the lead. The note is kept in history.')"
        action="confirmRemoveNote"
        :confirm-label="__('Remove note')"
    />

    <x-confirmation-modal
        name="unlink-customer-confirmation"
        :title="__('Remove this lead\'s link to the customer?')"
        :description="__('The customer and the inquiry are both kept as they are.')"
        action="confirmUnlinkCustomer"
        :confirm-label="__('Remove link')"
        error="customerChoice"
    />
</section>
