<?php

use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\RecordManualContactAttempt;
use App\Actions\Queue\ClaimNextLead;
use App\Actions\Queue\ReturnLeadToQueue;
use App\Actions\Queue\SkipHeldLead;
use App\Enums\CallQueue;
use App\Enums\Permission;
use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Enums\QueueSkipReason;
use App\Livewire\Concerns\RecordsContactAttempts;
use App\Models\ContactAttempt;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadQueueEvent;
use App\Support\Queue\CallingHours;
use App\Support\Queue\DueQueue;
use App\Support\Queue\QueueClaim;
use App\Support\Queue\QueueEligibility;
use App\Support\Queue\QueueTransaction;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Call Queue')] class extends Component {
    use RecordsContactAttempts;

    /**
     * The hold this page shows, as the server knows it. Locked: the browser
     * can neither send nor change them, and every action looks the hold up
     * again from them.
     */
    #[Locked]
    public ?int $cadenceId = null;

    #[Locked]
    public ?string $holdToken = null;

    /** The Lead a dialog (log, disqualify) was opened for, set by the server. */
    #[Locked]
    public ?int $workLeadId = null;

    #[Locked]
    public ?int $putBackLeadId = null;

    /** Why the card went away, when this page ended it itself. */
    #[Locked]
    public ?string $endedNotice = null;

    public string $skipReason = '';

    public string $skipNote = '';

    public ?int $disqualificationReasonId = null;

    public string $disqualificationNote = '';

    public ?int $duplicateOfId = null;

    #[Locked]
    public int $knownLifecycleVersion = 0;

    #[Locked]
    public string $knownStatus = '';

    #[Locked]
    public ?int $knownLocationId = null;

    #[Locked]
    public ?int $knownAssigneeId = null;

    public string $putBackNote = '';

    /**
     * Refusals that belong to no field of this page, by name: for example
     * "this lead was just changed by someone else". The framework carries
     * only a field's own error from one request to the next, and this page
     * asks again every ten seconds, so these are kept here and shown again on
     * each request until the person's next action clears the messages.
     *
     * @var array<string, list<string>>
     */
    #[Locked]
    public array $keptErrors = [];

    /**
     * Show the kept refusals again. This runs after the framework has put
     * back the field errors it carries itself.
     */
    public function hydrate(): void
    {
        foreach ($this->keptErrors as $name => $messages) {
            foreach ($messages as $message) {
                $this->addError($name, $message);
            }
        }
    }

    /**
     * Keep whatever refusals are on screen now that the framework will not
     * carry. Clearing the messages, as every dialog does when it opens,
     * leaves nothing to keep.
     */
    public function dehydrate(): void
    {
        $this->keptErrors = array_filter(
            $this->getErrorBag()->toArray(),
            fn (string $name): bool => ! property_exists($this, explode('.', $name)[0]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Mount the component. If the person is already holding a Lead (a reload,
     * a second tab) the card comes back; nothing is claimed.
     */
    public function mount(): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $held = LeadCadence::query()
            ->where('held_by_user_id', Auth::id())
            ->where('state', \App\Enums\CadenceState::InQueue)
            ->where('held_until', '>', now())
            ->first();

        if ($held !== null) {
            $this->cadenceId = $held->id;
            $this->holdToken = $held->hold_token;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reading: the poll only reads
    |--------------------------------------------------------------------------
    */

    /**
     * The 10-second poll. Rendering is the whole job: nothing here claims,
     * extends or releases a hold. It only checks the permission again.
     */
    public function refreshQueue(): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        unset($this->card, $this->goneNotice, $this->dueNow, $this->summary);
    }

    /**
     * The Lead the person holds right now, or null when the hold is gone or
     * they may no longer work the Lead.
     */
    #[Computed]
    public function card(): ?LeadCadence
    {
        if ($this->cadenceId === null || $this->holdToken === null) {
            return null;
        }

        $cadence = LeadCadence::query()
            ->with(['lead.location', 'lead.services'])
            ->whereKey($this->cadenceId)
            ->where('hold_token', $this->holdToken)
            ->where('held_by_user_id', Auth::id())
            ->first();

        if ($cadence === null || ! $cadence->isInQueue() || ! $cadence->isHeldAt(now())) {
            return null;
        }

        if (! app(QueueEligibility::class)->mayWork(Auth::user(), $cadence->lead) || $this->hoursClosedFor($cadence)) {
            return null;
        }

        return $cadence;
    }

    /** Whether calling hours at the Lead's Location are closed right now. Read only. */
    private function hoursClosedFor(LeadCadence $cadence): bool
    {
        return ! CallingHours::fromSettings(ContactCenterSettings::current())
            ->isOpen(now(), CallingHours::timezoneFor($cadence->lead->location));
    }

    /**
     * Why there is no card, when there was one: the reason only, never
     * anything about the Lead.
     */
    #[Computed]
    public function goneNotice(): ?string
    {
        if ($this->endedNotice !== null) {
            return $this->endedNotice;
        }

        if ($this->cadenceId === null || $this->card !== null) {
            return null;
        }

        $cadence = LeadCadence::query()->with('lead.location')->find($this->cadenceId);

        if ($cadence !== null && $cadence->hold_token === $this->holdToken && $cadence->held_by_user_id === Auth::id() && $cadence->isHeldAt(now())) {
            return $cadence->lead !== null && app(QueueEligibility::class)->mayWork(Auth::user(), $cadence->lead) && $this->hoursClosedFor($cadence)
                ? QueueHoldEndReason::OutsideCallingHours->notice()
                : QueueHoldEndReason::AccessLost->notice();
        }

        $event = LeadQueueEvent::query()
            ->where('hold_token', $this->holdToken)
            ->whereIn('type', [QueueEventType::HoldExpired, QueueEventType::HoldEnded, QueueEventType::Skipped])
            ->orderByDesc('id')
            ->first();

        return match (true) {
            $event?->type === QueueEventType::HoldEnded => QueueHoldEndReason::tryFrom((string) $event->reason)?->notice() ?? __('This lead is no longer yours to call.'),
            $event?->type === QueueEventType::Skipped => __('You skipped this lead.'),
            default => __('Your time on this lead ran out.'),
        };
    }

    #[Computed]
    public function dueNow(): int
    {
        return app(DueQueue::class)->dueNowCount(Auth::user(), now());
    }

    /**
     * When calling hours next open, if everything this person may work is closed now.
     *
     * @return array{at: \Carbon\CarbonImmutable, timezone: string}|null
     */
    #[Computed]
    public function opensNext(): ?array
    {
        $scope = app(QueueEligibility::class)->scope(Auth::user(), now());

        if (! $scope['anywhere'] || $scope['locations'] !== [] || $scope['unrouted']) {
            return null;
        }

        return app(DueQueue::class)->nextOpening(Auth::user(), now());
    }

    #[Computed]
    public function managerScope(): array
    {
        return app(DueQueue::class)->managerScope(Auth::user());
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->managerScope['anywhere'] ? app(DueQueue::class)->summary($this->managerScope, now()) : null;
    }

    #[Computed]
    public function attemptTotal(): int
    {
        return ContactCenterSettings::current()->totalAttempts();
    }

    /**
     * Earlier attempts the person may see, newest first.
     *
     * @return Collection<int, ContactAttempt>
     */
    #[Computed]
    public function earlierAttempts(): Collection
    {
        $lead = $this->card?->lead;

        if ($lead === null) {
            return new Collection;
        }

        return $lead->contactAttempts()->visibleTo(Auth::user())->orderByDesc('occurred_at')->orderByDesc('id')->limit(5)->get();
    }

    public function timezoneFor(Lead $lead): string
    {
        return CallingHours::timezoneFor($lead->location);
    }

    /*
    |--------------------------------------------------------------------------
    | Next lead
    |--------------------------------------------------------------------------
    */

    public function nextLead(ClaimNextLead $claimNextLead): void
    {
        $this->claimFrom($claimNextLead, CallQueue::Main);
    }

    /**
     * The second button: the next Lead that came back from Sales with its
     * appointment cancelled (decision 117).
     */
    public function nextCancelledAppointment(ClaimNextLead $claimNextLead): void
    {
        $this->claimFrom($claimNextLead, CallQueue::CancelledAppointments);
    }

    /**
     * How many cancelled appointments the second button could give this
     * person right now.
     */
    public function cancelledDueNow(): int
    {
        return app(DueQueue::class)->dueNowCount(Auth::user(), now(), CallQueue::CancelledAppointments);
    }

    private function claimFrom(ClaimNextLead $claimNextLead, CallQueue $queue): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $claim = $claimNextLead->handle(Auth::user(), $queue);

        $this->endedNotice = null;
        $this->resetValidation();

        if ($claim->given()) {
            $this->cadenceId = $claim->cadence->id;
            $this->holdToken = $claim->cadence->hold_token;
            $this->putBackLeadId = null;
            unset($this->card, $this->goneNotice, $this->earlierAttempts, $this->dueNow);

            return;
        }

        $this->cadenceId = null;
        $this->holdToken = null;

        if ($claim->status === QueueClaim::TRY_AGAIN) {
            Flux::toast(variant: 'danger', text: __('The system was busy. Please press the button again.'));
        } elseif ($claim->endedHold !== null) {
            $this->endedNotice = $claim->endedHold->notice();
        }

        unset($this->card, $this->goneNotice, $this->dueNow, $this->opensNext);
    }

    /*
    |--------------------------------------------------------------------------
    | Log attempt
    |--------------------------------------------------------------------------
    */

    public function openContactAttempt(): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $lead = $this->card?->lead ?? abort(403);
        $this->authorize('update', $lead);

        $this->workLeadId = $lead->id;
        $this->startContactAttempt($lead);

        Flux::modal('record-contact-attempt')->show();
    }

    /**
     * Log the call through the shared action. The Lead is the one the dialog
     * was opened for, resolved and authorized again here. A hold that ran out
     * while the form was open does not matter: the call is saved if the
     * person may still work the Lead.
     */
    public function recordContactAttempt(RecordManualContactAttempt $recordManualContactAttempt): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $lead = $this->workLeadId === null ? null : Lead::query()->with('location')->find($this->workLeadId);

        if ($lead === null || ! app(QueueEligibility::class)->mayWork(Auth::user(), $lead)) {
            $this->closeBecauseLeadIsGone('record-contact-attempt', $lead);

            return;
        }

        if (! $this->guardBusy(fn () => $this->saveContactAttempt($lead, $recordManualContactAttempt, phoneCallOnly: true))) {
            return;
        }

        $this->finish('record-contact-attempt', __('Your call was logged.'));
        Flux::toast(variant: 'success', text: __('Contact attempt recorded.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Skip
    |--------------------------------------------------------------------------
    */

    public function openSkip(): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $this->card ?? abort(403);

        $this->reset('skipReason', 'skipNote');
        $this->resetValidation();

        Flux::modal('skip-lead')->show();
    }

    public function updatedSkipReason(): void
    {
        $this->resetValidation(['skipReason', 'skipNote']);
    }

    public function skip(SkipHeldLead $skipHeldLead): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        if ($this->cadenceId === null || $this->holdToken === null) {
            abort(403);
        }

        try {
            $done = $this->guardBusy(fn () => $skipHeldLead->handle(Auth::user(), $this->cadenceId, $this->holdToken, $this->skipReason, $this->skipNote));
        } catch (ValidationException $exception) {
            if (! array_key_exists('hold', $exception->errors())) {
                throw $exception;
            }

            // The hold is gone: nothing was saved. The card is replaced by the reason.
            Flux::modal('skip-lead')->close();
            unset($this->card, $this->goneNotice);

            return;
        }

        if ($done) {
            $this->finish('skip-lead', __('You skipped this lead. It is offered to the other setters.'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Disqualify
    |--------------------------------------------------------------------------
    */

    public function openDisqualify(): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $lead = $this->card?->lead ?? abort(403);
        $this->authorize('changeStatus', $lead);

        $this->workLeadId = $lead->id;
        $this->reset('disqualificationReasonId', 'disqualificationNote', 'duplicateOfId');
        $this->knownStatus = $lead->status->value;
        $this->knownLifecycleVersion = (int) $lead->lifecycle_version;
        $this->knownLocationId = $lead->location_id;
        $this->knownAssigneeId = $lead->assigned_user_id;
        unset($this->chosenReason, $this->possibleOriginals);
        $this->resetValidation();

        Flux::modal('disqualify-lead')->show();
    }

    public function updatedDisqualificationReasonId(): void
    {
        unset($this->chosenReason);
        $this->resetValidation(['disqualificationReasonId', 'disqualificationNote', 'duplicateOfId']);
    }

    public function disqualify(DisqualifyLead $disqualifyLead): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $lead = $this->workLeadId === null ? null : Lead::query()->with('location')->find($this->workLeadId);

        if ($lead === null || ! app(QueueEligibility::class)->mayWork(Auth::user(), $lead)) {
            $this->closeBecauseLeadIsGone('disqualify-lead', $lead);

            return;
        }

        $this->authorize('changeStatus', $lead);

        if ($lead->status->value !== $this->knownStatus
            || (int) $lead->lifecycle_version !== $this->knownLifecycleVersion
            || $lead->location_id !== $this->knownLocationId
            || $lead->assigned_user_id !== $this->knownAssigneeId) {
            Flux::modal('disqualify-lead')->close();
            $this->addError('stale', __('This lead was just changed by someone else, so nothing was saved. Check where it stands now and try again.'));

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

        $original = $reason->isDuplicate() && $this->duplicateOfId !== null
            ? (DisqualifyLead::possibleOriginals($lead, Auth::user())->firstWhere('id', $this->duplicateOfId) ?? abort(404))
            : null;

        $done = $this->guardBusy(fn () => $disqualifyLead->handle($lead, $reason, $this->disqualificationNote, Auth::user(), $original, $this->knownLifecycleVersion));

        if ($done) {
            $this->finish('disqualify-lead', __('You disqualified this lead.'));
            Flux::toast(variant: 'success', text: __('This lead is disqualified.'));
        }
    }

    /**
     * @return Collection<int, LeadDisqualificationReason>
     */
    #[Computed]
    public function disqualificationReasons(): Collection
    {
        return LeadDisqualificationReason::query()
            ->where('is_active', true)
            ->where(fn ($special) => $special->whereNull('special_handling')->orWhere('special_handling', '!=', LeadDisqualificationReason::UNABLE_TO_CONTACT))
            ->ordered()
            ->get();
    }

    #[Computed]
    public function chosenReason(): ?LeadDisqualificationReason
    {
        return $this->disqualificationReasons->firstWhere('id', $this->disqualificationReasonId);
    }

    /**
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function possibleOriginals(): Collection
    {
        $lead = $this->workLeadId === null ? null : Lead::query()->find($this->workLeadId);

        return $lead === null ? new Collection : DisqualifyLead::possibleOriginals($lead, Auth::user());
    }

    /*
    |--------------------------------------------------------------------------
    | Put back in queue (manager part)
    |--------------------------------------------------------------------------
    */

    public function openPutBack(int $leadId): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $lead = Lead::query()->findOrFail($leadId);
        $this->authorize('returnToQueue', $lead);

        $this->putBackLeadId = $lead->id;
        $this->putBackNote = '';
        $this->resetValidation();

        Flux::modal('put-back-in-queue')->show();
    }

    public function putBack(ReturnLeadToQueue $returnLeadToQueue): void
    {
        $this->authorize(Permission::WorkLeadQueue);

        $lead = $this->putBackLeadId === null ? null : Lead::query()->find($this->putBackLeadId);

        if ($lead === null) {
            abort(404);
        }

        $this->authorize('returnToQueue', $lead);

        $this->resetValidation('putBackNote');

        try {
            $done = $this->guardBusy(fn () => $returnLeadToQueue->handle($lead, $this->putBackNote, Auth::user()));
        } catch (ValidationException $exception) {
            // The action speaks of its "note". Here that is the note field, and
            // only an error kept under a field's own name survives the next refresh.
            throw array_key_exists('note', $exception->errors())
                ? ValidationException::withMessages(['putBackNote' => $exception->errors()['note']])
                : $exception;
        }

        if (! $done) {
            return;
        }

        $this->putBackLeadId = null;
        unset($this->summary, $this->dueNow);

        Flux::modal('put-back-in-queue')->close();
        Flux::toast(variant: 'success', text: __('Put back in the call queue.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Run a save. A busy database is answered with a plain "try again" and
     * nothing saved; anything else is left to fail as it should.
     */
    private function guardBusy(\Closure $work): bool
    {
        try {
            $work();

            return true;
        } catch (\Throwable $exception) {
            if (! app(QueueTransaction::class)->isBusy($exception)) {
                throw $exception;
            }

            Flux::toast(variant: 'danger', text: __('The system was busy, so nothing was saved. Please try again.'));

            return false;
        }
    }

    /**
     * A save came to a Lead the person may no longer work: nothing is saved
     * and the reason is shown, without details of the Lead.
     */
    private function closeBecauseLeadIsGone(string $modal, ?Lead $lead): void
    {
        Flux::modal($modal)->close();

        $this->endedNotice = $lead !== null && $lead->isActive() === false
            ? QueueHoldEndReason::Disqualified->notice()
            : QueueHoldEndReason::AccessLost->notice();

        unset($this->card, $this->goneNotice);
    }

    /**
     * The person's own action ended their hold: close the dialog, say so and
     * offer Next lead.
     */
    private function finish(string $modal, string $notice): void
    {
        Flux::modal($modal)->close();
        $this->resetValidation();

        $this->endedNotice = $notice;
        $this->cadenceId = null;
        $this->holdToken = null;
        $this->workLeadId = null;

        unset($this->card, $this->goneNotice, $this->dueNow, $this->summary, $this->earlierAttempts);
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" wire:poll.10s="refreshQueue">
    <div>
        <flux:heading size="xl" level="1">{{ __('Call Queue') }}</flux:heading>
        <flux:subheading>{{ __('One lead at a time. The queue hands you the next lead and nobody else gets it while you hold it.') }}</flux:subheading>
    </div>

    <flux:error name="stale" />

    @if ($this->card !== null)
        @php
            $cadence = $this->card;
            $lead = $cadence->lead;
        @endphp

        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="queue-card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:heading size="lg" level="2" data-test="card-name">{{ $lead->displayName() ?? __('No name given') }}</flux:heading>
                    <flux:text data-test="card-phone">
                        @if ($lead->displayPhone() !== null)
                            <a class="font-semibold tabular-nums text-ink underline" href="tel:{{ $lead->inquiry_phone_normalized ?? $lead->inquiry_phone }}">{{ $lead->displayPhone() }}</a>
                        @else
                            {{ __('No phone number given') }}
                        @endif
                    </flux:text>
                </div>

                <div class="flex flex-col items-end gap-1 text-sm">
                    <flux:badge color="amber" data-test="card-attempt">{{ __('Attempt :step of :total', ['step' => $cadence->nextStep(), 'total' => $this->attemptTotal]) }}</flux:badge>
                    <span class="text-zinc-500" data-test="card-hold-left"
                        x-data="{
                            until: new Date('{{ $cadence->held_until->toIso8601String() }}'),
                            left: '',
                            timer: null,
                            tick() {
                                const seconds = Math.max(0, Math.round((this.until - new Date()) / 1000));
                                this.left = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
                            },
                            init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000); },
                            destroy() { clearInterval(this.timer); },
                        }">{{ __('Time left on your hold') }}: <span class="tabular-nums" x-text="left">{{ max(0, (int) now()->diffInMinutes($cadence->held_until)) }} {{ __('min') }}</span></span>
                </div>
            </div>

            <x-lead.sales-return :lead="$lead" data-test="card-sales-return" />

            <dl class="grid gap-x-5 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-zinc-500">{{ __('Location') }}</dt>
                    <dd class="text-ink" data-test="card-location">{{ $lead->location?->name ?? __('Not sent to a location yet') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Asked for') }}</dt>
                    <dd class="text-ink" data-test="card-service">{{ $lead->primaryService()?->name ?? __('Not recorded') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Received') }}</dt>
                    <dd class="text-ink"><x-queue-time :at="$lead->received_at" :timezone="$this->timezoneFor($lead)" /></dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Due since') }}</dt>
                    <dd class="text-ink" data-test="card-due"><x-queue-time :at="$cadence->due_at" :timezone="$this->timezoneFor($lead)" /></dd>
                </div>
                @if ($lead->inquiry_message)
                    <div class="sm:col-span-2">
                        <dt class="text-zinc-500">{{ __('What they wrote') }}</dt>
                        <dd class="whitespace-pre-line text-ink" data-test="card-message">{{ $lead->inquiry_message }}</dd>
                    </div>
                @endif
            </dl>

            <div class="flex flex-col gap-2" data-test="card-attempts">
                <flux:heading size="sm" level="3">{{ __('Earlier attempts') }}</flux:heading>
                @forelse ($this->earlierAttempts as $attempt)
                    <div wire:key="earlier-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm" data-test="card-attempt-row">
                        <span class="text-ink">{{ $attempt->disposition->label() }} &middot; {{ $attempt->direction->label() }} {{ $attempt->channel->label() }}</span>
                        <span class="text-zinc-500"><x-queue-time :at="$attempt->occurred_at" :timezone="$this->timezoneFor($lead)" /></span>
                    </div>
                @empty
                    <flux:text>{{ __('No earlier attempts.') }}</flux:text>
                @endforelse
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @can('update', $lead)
                    <flux:button variant="primary" wire:click="openContactAttempt" data-test="log-attempt">{{ __('Log attempt') }}</flux:button>
                @endcan
                <flux:button wire:click="openSkip" data-test="skip-lead">{{ __('Skip') }}</flux:button>
                @can('changeStatus', $lead)
                    <flux:button variant="ghost" wire:click="openDisqualify" data-test="disqualify-lead">{{ __('Disqualify') }}</flux:button>
                @endcan
                @can('view', $lead)
                    <flux:link :href="route('leads.show', $lead)" wire:navigate data-test="card-open-lead" class="ml-auto text-sm">{{ __('Open the full lead') }}</flux:link>
                @endcan
            </div>
        </div>
    @else
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="queue-empty-card">
            @if ($this->goneNotice !== null)
                <flux:callout icon="information-circle" data-test="gone-notice">
                    <flux:callout.text>{{ $this->goneNotice }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg" level="2" data-test="due-now">{{ trans_choice(':count lead due now|:count leads due now', $this->dueNow) }}</flux:heading>
                    @if ($this->dueNow === 0)
                        <flux:text data-test="nothing-due">
                            {{ __('Nothing is due for you right now.') }}
                            @if ($this->opensNext !== null)
                                {{ __('Calling hours are closed. They open') }} <x-queue-time :at="$this->opensNext['at']" :timezone="$this->opensNext['timezone']" />.
                            @endif
                        </flux:text>
                    @endif
                </div>

                <flux:button variant="primary" wire:click="nextLead" wire:loading.attr="disabled" data-test="next-lead">{{ __('Next lead') }}</flux:button>
            </div>

            {{-- Cancelled appointments are a queue of their own, so the setter knows the script before dialling (decision 117). --}}
            @php($cancelledDue = $this->cancelledDueNow())

            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-zinc-200 pt-4" data-test="cancelled-queue">
                <div>
                    <flux:heading size="lg" level="2" data-test="cancelled-due-now">{{ trans_choice(':count cancelled appointment due now|:count cancelled appointments due now', $cancelledDue) }}</flux:heading>
                    <flux:text>{{ __('Leads whose appointment was cancelled, to call and try to rebook.') }}</flux:text>
                </div>

                <flux:button :variant="$cancelledDue > 0 ? 'primary' : 'filled'" wire:click="nextCancelledAppointment" wire:loading.attr="disabled" data-test="next-cancelled-appointment">{{ __('Next cancelled appointment') }}</flux:button>
            </div>
        </div>
    @endif

    @if ($this->card !== null)
        <flux:text data-test="due-now">
            {{ trans_choice(':count other lead due now|:count other leads due now', $this->dueNow) }}
            &middot;
            <span data-test="cancelled-due-now">{{ trans_choice(':count cancelled appointment due now|:count cancelled appointments due now', $this->cancelledDueNow()) }}</span>
        </flux:text>
    @endif

    <flux:error name="hold" />

    @if ($this->summary !== null)
        @php($summary = $this->summary)

        <div class="flex flex-col gap-5" data-test="manager-summary">
            @if ($summary['heartbeatStale'])
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="heartbeat-warning">
                    <flux:callout.heading>{{ __('The queue timer has not run lately') }}</flux:callout.heading>
                    <flux:callout.text>
                        @if ($summary['heartbeat'] === null)
                            {{ __('It has never reported in. The queue still works, but holds are tidied up late and finished leads are not noticed until it runs.') }}
                        @else
                            {{ __('It last ran :when. The queue still works, but holds are tidied up late and finished leads are not noticed until it runs.', ['when' => $summary['heartbeat']->diffForHumans()]) }}
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-card p-4">
                    <flux:text>{{ __('Due now') }}</flux:text>
                    <div class="text-2xl font-semibold tabular-nums text-ink" data-test="summary-due">{{ $summary['dueNow'] }}</div>
                    <flux:text class="text-sm" data-test="summary-cancelled-due">{{ trans_choice(':count cancelled appointment|:count cancelled appointments', $summary['cancelledDueNow']) }}</flux:text>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-card p-4">
                    <flux:text>{{ __('Longest wait') }}</flux:text>
                    <div class="text-2xl font-semibold text-ink" data-test="summary-wait">{{ $summary['longestWaitSince'] === null ? '—' : $summary['longestWaitSince']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-card p-4">
                    <flux:text>{{ __('Leads held now') }}</flux:text>
                    <div class="text-2xl font-semibold tabular-nums text-ink" data-test="summary-held">{{ $summary['heldCount'] }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-card p-4">
                    <flux:text>{{ __('Cadence finished, no contact') }}</flux:text>
                    <div class="text-2xl font-semibold tabular-nums text-ink" data-test="summary-finished">{{ $summary['finishedCount'] }}</div>
                </div>
            </div>

            @if ($summary['held']->isNotEmpty())
                <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5" data-test="held-list">
                    <flux:heading size="md" level="2">{{ __('Held right now') }}</flux:heading>
                    <ul class="divide-y divide-zinc-200">
                        @foreach ($summary['held'] as $held)
                            <li wire:key="held-{{ $held->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0" data-test="held-row">
                                <a class="font-semibold text-wall-teal underline" href="{{ route('leads.show', $held->lead_id) }}" wire:navigate>{{ $held->lead->displayName() ?? __('No name given') }}</a>
                                <span class="text-zinc-700">{{ __('held by :name', ['name' => $held->holder?->name ?? __('a setter')]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5" data-test="finished-list">
                <flux:heading size="md" level="2">{{ __('Cadence finished, no contact') }}</flux:heading>
                <flux:subheading>{{ __('Every attempt was made and nobody was reached. These stay out of the queue until a manager puts them back.') }}</flux:subheading>

                @forelse ($summary['finished'] as $finished)
                    @if ($loop->first)
                        <ul class="divide-y divide-zinc-200">
                    @endif
                    <li wire:key="finished-{{ $finished->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0" data-test="finished-row">
                        <div class="flex flex-col">
                            <a class="font-semibold text-wall-teal underline" href="{{ route('leads.show', $finished->lead_id) }}" wire:navigate>{{ $finished->lead->displayName() ?? __('No name given') }}</a>
                            <span class="text-zinc-500">{{ $finished->lead->location?->name ?? __('Unrouted') }} @if ($finished->ended_at) &middot; <x-local-time :at="$finished->ended_at" /> @endif</span>
                        </div>
                        @can('returnToQueue', $finished->lead)
                            <flux:button size="sm" wire:click="openPutBack({{ $finished->lead_id }})" data-test="put-back-in-queue">{{ __('Put back in queue') }}</flux:button>
                        @endcan
                    </li>
                    @if ($loop->last)
                        </ul>
                    @endif
                @empty
                    <flux:text data-test="finished-empty">{{ __('None right now.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endif

    @include('partials.contact-attempt-form', ['phoneCallOnly' => true])

    <x-confirmation-modal
        name="skip-lead"
        :title="__('Skip this lead?')"
        :description="__('It goes back to the other setters at once and is kept away from you for a while. Nothing is counted against anyone.')"
        action="skip"
        :confirm-label="__('Skip')"
        confirm-variant="primary"
        :error="['hold']"
    >
        <flux:select wire:model.live="skipReason" :label="__('Why are you skipping it?')" data-test="skip-reason">
            <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
            @foreach (QueueSkipReason::cases() as $reason)
                <flux:select.option :value="$reason->value">{{ $reason->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($skipReason === QueueSkipReason::Other->value)
            <flux:textarea wire:model="skipNote" rows="2" :label="__('Say briefly why (required)')" data-test="skip-note" />
        @endif
    </x-confirmation-modal>

    <flux:modal name="disqualify-lead" class="w-full max-w-xl">
        <form wire:submit="disqualify" class="flex flex-col gap-5">
            <flux:error name="stale" />
            <div>
                <flux:heading size="lg">{{ __('Disqualify this lead') }}</flux:heading>
                <flux:subheading>{{ __('For an inquiry that is not a real opportunity. The inquiry, where it came from and its customer are all kept. Nobody stays assigned to it.') }}</flux:subheading>
            </div>

            <flux:select wire:model.live="disqualificationReasonId" :label="__('Reason')" data-test="disqualification-reason">
                <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
                @foreach ($this->disqualificationReasons as $reason)
                    <flux:select.option :value="$reason->id" wire:key="reason-{{ $reason->id }}">{{ $reason->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:text>{{ __('No response alone is not a reason to disqualify. Use Skip, or schedule a follow-up from the full lead.') }}</flux:text>

            @if ($this->chosenReason?->isDuplicate())
                <fieldset class="flex flex-col gap-2" data-test="duplicate-originals">
                    <legend class="text-sm font-medium text-ink">{{ __('Which lead does this one repeat?') }}</legend>

                    @foreach ($this->possibleOriginals as $original)
                        <label wire:key="original-{{ $original->id }}" class="cursor-pointer">
                            <input type="radio" wire:model="duplicateOfId" name="duplicateOfId" value="{{ $original->id }}" class="peer sr-only" />
                            <span class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-semibold">{{ $original->displayName() ?? __('No name given') }}</span>
                                <span class="text-sm text-zinc-700">{{ $original->location?->name ?? __('Unrouted') }} &middot; {{ $original->status->label() }}</span>
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
                :label="$this->chosenReason?->needsNote() ? __('Explanation (required)') : __('Note (optional)')"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="disqualify-submit">{{ __('Disqualify') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($this->summary !== null)
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
    @endif
</section>
