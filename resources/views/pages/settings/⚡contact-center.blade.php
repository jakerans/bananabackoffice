<?php

use App\Actions\ContactCenter\ChangeContactCenterSettings;
use App\Actions\ContactCenter\SetQueueManagerView;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\ContactCenterSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Contact Center settings')] class extends Component {
    /** The saved version this page was loaded from. A save writes the one after it. */
    #[Locked]
    public int $loadedVersion = 0;

    /** Waits in minutes for attempts 2 onwards on day 1. Attempt 1 is always right away. */
    public array $day1Waits = [];

    /** The number of attempts on each cadence day after day 1. */
    public array $laterDays = [];

    public string $opensAt = '';

    public string $closesAt = '';

    /** ISO weekday numbers, 1 (Monday) to 7 (Sunday). */
    public array $callingDays = [];

    public string $holdMinutes = '';

    public string $releaseDays = '';

    public string $proposalValidDays = '';

    /**
     * Each Call Center Agent's manager-view switch, by staff ID. A switch
     * saves by itself the moment it is changed.
     *
     * @var array<int, bool>
     */
    public array $managerView = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $this->loadSettings();
        $this->loadManagerView();
    }

    /**
     * Every active person with Call Center Agent access.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function agents(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('accessGrants', fn ($grants) => $grants->where('role', Role::CallCenterAgent))
            ->with('accessGrants.location')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Where a person's Call Center Agent access applies, in words.
     */
    protected function agentAccess(User $agent): string
    {
        $grants = $agent->accessGrants->filter(fn (AccessGrant $grant): bool => $grant->role === Role::CallCenterAgent);

        if ($grants->contains(fn (AccessGrant $grant): bool => $grant->isCompanyWide())) {
            return __('All locations');
        }

        return $grants->map(fn (AccessGrant $grant): ?string => $grant->location?->name)->filter()->sort()->implode(', ');
    }

    /**
     * Show every switch as it is saved now.
     */
    private function loadManagerView(): void
    {
        unset($this->agents);

        $this->managerView = $this->agents->mapWithKeys(fn (User $agent): array => [$agent->id => $agent->queue_manager_view])->all();
    }

    /**
     * Save one agent's switch as soon as it is changed. The person is found
     * again from the ID and the action checks that they are a Call Center
     * Agent, so a made-up ID or anyone else's cannot be switched.
     */
    public function updatedManagerView(mixed $value, ?string $key = null): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);
        $this->resetValidation('agent');

        $agent = $key !== null && ctype_digit($key) ? User::query()->find((int) $key) : null;

        if ($agent === null) {
            $this->loadManagerView();

            return;
        }

        try {
            $changed = app(SetQueueManagerView::class)->handle(auth()->user(), $agent, (bool) $value);
        } finally {
            $this->loadManagerView();
        }

        if ($changed) {
            Flux::toast(variant: 'success', text: $agent->queue_manager_view
                ? __(':name now sees the manager part of the Call Queue.', ['name' => $agent->name])
                : __(':name no longer sees the manager part of the Call Queue.', ['name' => $agent->name]));
        }
    }

    /**
     * Show the settings now in force: the highest saved version.
     */
    private function loadSettings(): void
    {
        $settings = ContactCenterSettings::current();

        $this->loadedVersion = $settings->version;

        $this->loadCadence($settings);
        $this->loadHours($settings);
        $this->loadDays($settings);
        $this->loadHold($settings);
        $this->loadReleaseDays($settings);
        $this->loadProposalValidDays($settings);

        $this->resetValidation();
    }

    private function loadCadence(ContactCenterSettings $settings): void
    {
        $this->day1Waits = array_map(strval(...), array_slice($settings->cadence[0]['waits_minutes'] ?? [], 1));
        $this->laterDays = array_map(
            fn (array $day): string => (string) ($day['attempts'] ?? ''),
            array_slice($settings->cadence, 1),
        );
    }

    private function loadHours(ContactCenterSettings $settings): void
    {
        $this->opensAt = (string) $settings->calling_opens_at;
        $this->closesAt = (string) $settings->calling_closes_at;
    }

    private function loadDays(ContactCenterSettings $settings): void
    {
        $this->callingDays = array_map(strval(...), $settings->calling_days);
    }

    private function loadHold(ContactCenterSettings $settings): void
    {
        $this->holdMinutes = (string) $settings->hold_minutes;
    }

    private function loadReleaseDays(ContactCenterSettings $settings): void
    {
        $this->releaseDays = (string) $settings->cancelled_release_days;
    }

    private function loadProposalValidDays(ContactCenterSettings $settings): void
    {
        $this->proposalValidDays = (string) $settings->proposal_valid_days;
    }

    /**
     * Who saved the version now in force.
     */
    #[Computed]
    public function current(): ContactCenterSettings
    {
        return ContactCenterSettings::current();
    }

    /**
     * The attempts the cadence being edited holds in total.
     */
    #[Computed]
    public function totalAttempts(): int
    {
        $count = 1;

        foreach ([$this->day1Waits, $this->laterDays] as $index => $values) {
            $count += $index === 0 ? count($values) : array_sum(array_map(fn ($value) => is_numeric($value) ? max(0, (int) $value) : 0, $values));
        }

        return $count;
    }

    /**
     * The opening and closing times offered: 8:00 am to 9:00 pm in 15-minute steps.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function timeOptions(): array
    {
        $options = [];

        for ($minutes = ContactCenterSettings::EARLIEST_OPENING; $minutes <= ContactCenterSettings::LATEST_CLOSING; $minutes += ContactCenterSettings::HOURS_STEP) {
            $options[$minutes] = CarbonImmutable::createFromTime(intdiv($minutes, 60), $minutes % 60)->format('g:i a');
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function weekdays(): array
    {
        return [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')];
    }

    public function addDay1Attempt(): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        if (count($this->day1Waits) + 1 < ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY) {
            $this->day1Waits[] = '30';
        }
    }

    public function removeDay1Attempt(int $position): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        unset($this->day1Waits[$position]);
        $this->day1Waits = array_values($this->day1Waits);
    }

    public function addDay(): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        if (count($this->laterDays) + 1 < ContactCenterSettings::MAXIMUM_CADENCE_DAYS) {
            $this->laterDays[] = '2';
        }
    }

    public function removeDay(int $position): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        unset($this->laterDays[$position]);
        $this->laterDays = array_values($this->laterDays);
    }

    public function saveCadence(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $cadence = [['type' => 'timed', 'waits_minutes' => [0, ...array_values($this->day1Waits)]]];

        foreach (array_values($this->laterDays) as $attempts) {
            $cadence[] = ['type' => 'spread', 'attempts' => $attempts];
        }

        $this->saved(fn () => $change->cadence(auth()->user(), $this->loadedVersion, $cadence), __('Call cadence saved.'), fn () => $this->loadCadence(ContactCenterSettings::current()));
    }

    public function saveHours(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $this->saved(fn () => $change->callingHours(auth()->user(), $this->loadedVersion, $this->opensAt, $this->closesAt), __('Calling hours saved.'), fn () => $this->loadHours(ContactCenterSettings::current()));
    }

    /**
     * Open the Reset to default dialog. Opening it changes nothing.
     */
    public function openResetHours(): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        Flux::modal('reset-calling-hours-confirmation')->show();
    }

    /**
     * Confirm the reset, authorizing again first.
     */
    public function confirmResetHours(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        Flux::modal('reset-calling-hours-confirmation')->close();

        $this->saved(
            fn () => $change->callingHours(
                auth()->user(),
                $this->loadedVersion,
                ContactCenterSettings::EARLIEST_OPENING,
                ContactCenterSettings::LATEST_CLOSING,
                reset: true,
            ),
            __('Calling hours reset to 8:00 am to 9:00 pm.'),
            fn () => $this->loadHours(ContactCenterSettings::current()),
        );
    }

    public function saveDays(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $this->saved(fn () => $change->callingDays(auth()->user(), $this->loadedVersion, $this->callingDays), __('Calling days saved.'), fn () => $this->loadDays(ContactCenterSettings::current()));
    }

    public function saveHold(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $this->saved(fn () => $change->holdLength(auth()->user(), $this->loadedVersion, $this->holdMinutes), __('Hold length saved.'), fn () => $this->loadHold(ContactCenterSettings::current()));
    }

    public function saveReleaseDays(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $this->saved(fn () => $change->releaseDays(auth()->user(), $this->loadedVersion, $this->releaseDays), __('Days saved.'), fn () => $this->loadReleaseDays(ContactCenterSettings::current()));
    }

    public function saveProposalValidDays(ChangeContactCenterSettings $change): void
    {
        $this->authorize(Permission::ManageContactCenterSettings);

        $this->saved(fn () => $change->proposalValidDays(auth()->user(), $this->loadedVersion, $this->proposalValidDays), __('Days saved.'), fn () => $this->loadProposalValidDays(ContactCenterSettings::current()));
    }

    /**
     * Run a save. A new version moves the loaded version on by one, which is
     * exactly what the action wrote. A refusal because the settings moved on
     * reloads the whole page from the latest version before it is shown.
     */
    private function saved(\Closure $save, string $message, \Closure $reload): void
    {
        try {
            $wasSaved = $save();
        } catch (ValidationException $exception) {
            if (array_key_exists('version', $exception->errors())) {
                $this->loadSettings();
                unset($this->current);
            }

            throw $exception;
        }

        unset($this->current);

        if (! $wasSaved) {
            Flux::toast(text: __('Nothing was different, so nothing was saved.'));

            return;
        }

        $this->loadedVersion++;
        $reload();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Contact Center') }}</flux:heading>
        <flux:subheading>
            {{ __('How often a new Lead is called, when calling is allowed, and how long a Lead is held for one person.') }}
        </flux:subheading>
    </div>

    <div class="rounded-lg border border-zinc-200 bg-card p-4 text-sm text-ink" data-test="settings-scope-note">
        <p>{{ __('Changes apply to attempts scheduled from now on. Attempts already scheduled keep their time.') }}</p>
        <p>{{ __('Calling hours and days are applied in each Location\'s local time.') }}</p>
        <p class="mt-1 text-zinc-600" data-test="settings-version">
            {{ __('Settings version :version', ['version' => $this->current->version]) }}@if ($this->current->changed_by_name), {{ __('last saved by :name', ['name' => $this->current->changed_by_name]) }}@endif
        </p>
    </div>

    <flux:error name="version" />

    {{-- Call cadence --}}
    <form wire:submit="saveCadence" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="cadence-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Call cadence') }}</flux:heading>
            <flux:text>{{ __('Day 1 is the day the Lead arrives. Later days spread their attempts evenly through calling hours.') }}</flux:text>
        </div>

        <div class="flex flex-col gap-3">
            <flux:heading level="3">{{ __('Day 1') }}</flux:heading>

            <div class="flex items-center gap-3">
                <span class="w-28 text-sm">{{ __('Attempt 1') }}</span>
                <flux:text>{{ __('Right away') }}</flux:text>
            </div>

            @foreach ($day1Waits as $position => $wait)
                <div class="flex items-end gap-3" wire:key="day1-wait-{{ $position }}">
                    <span class="w-28 pb-2 text-sm">{{ __('Attempt :number', ['number' => $position + 2]) }}</span>
                    <flux:input
                        wire:model.live.debounce.300ms="day1Waits.{{ $position }}"
                        type="number"
                        inputmode="numeric"
                        :min="ContactCenterSettings::MINIMUM_WAIT"
                        :max="ContactCenterSettings::MAXIMUM_WAIT"
                        :label="__('Minutes after the previous attempt')"
                        class="max-w-40 tabular-nums"
                    />
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon="x-mark"
                        wire:click="removeDay1Attempt({{ $position }})"
                        aria-label="{{ __('Remove attempt :number from day 1', ['number' => $position + 2]) }}"
                    />
                </div>
            @endforeach

            <div>
                <flux:button type="button" size="sm" icon="plus" wire:click="addDay1Attempt" :disabled="count($day1Waits) + 1 >= ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY">
                    {{ __('Add an attempt to day 1') }}
                </flux:button>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            @foreach ($laterDays as $position => $attempts)
                <div class="flex items-end gap-3" wire:key="later-day-{{ $position }}">
                    <span class="w-28 pb-2 text-sm font-medium">{{ __('Day :number', ['number' => $position + 2]) }}</span>
                    <flux:input
                        wire:model.live.debounce.300ms="laterDays.{{ $position }}"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        :max="ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY"
                        :label="__('Attempts, spread evenly through calling hours')"
                        class="max-w-40 tabular-nums"
                    />
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon="x-mark"
                        wire:click="removeDay({{ $position }})"
                        aria-label="{{ __('Remove day :number', ['number' => $position + 2]) }}"
                    />
                </div>
            @endforeach

            <div>
                <flux:button type="button" size="sm" icon="plus" wire:click="addDay" :disabled="count($laterDays) + 1 >= ContactCenterSettings::MAXIMUM_CADENCE_DAYS">
                    {{ __('Add a day') }}
                </flux:button>
            </div>
        </div>

        <flux:error name="cadence" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:text class="font-medium tabular-nums" data-test="total-attempts">
                {{ __('Total attempts: :count', ['count' => $this->totalAttempts]) }}
            </flux:text>

            <flux:button type="submit" variant="primary" data-test="save-cadence">{{ __('Save call cadence') }}</flux:button>
        </div>
    </form>

    {{-- Calling hours --}}
    <form wire:submit="saveHours" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="hours-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Calling hours') }}</flux:heading>
            <flux:text>{{ __('Applied in each Location\'s local time. Hours can be narrowed within 8:00 am and 9:00 pm, never widened beyond them.') }}</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="opensAt" :label="__('Opens')">
                @foreach ($this->timeOptions as $minutes => $label)
                    <flux:select.option :value="$minutes">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="closesAt" :label="__('Closes')">
                @foreach ($this->timeOptions as $minutes => $label)
                    <flux:select.option :value="$minutes">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:button type="button" wire:click="openResetHours" data-test="reset-hours">{{ __('Reset to default') }}</flux:button>

            <flux:button type="submit" variant="primary" data-test="save-hours">{{ __('Save calling hours') }}</flux:button>
        </div>
    </form>

    {{-- Calling days --}}
    <form wire:submit="saveDays" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="days-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Calling days') }}</flux:heading>
            <flux:text>{{ __('The days of the week Leads may be called. All seven are ticked by default.') }}</flux:text>
        </div>

        <flux:checkbox.group wire:model="callingDays">
            @foreach ($this->weekdays as $number => $label)
                <flux:checkbox :value="(string) $number" :label="$label" />
            @endforeach
        </flux:checkbox.group>

        <flux:error name="callingDays" />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-days">{{ __('Save calling days') }}</flux:button>
        </div>
    </form>

    {{-- Hold length --}}
    <form wire:submit="saveHold" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="hold-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Hold length') }}</flux:heading>
            <flux:text>{{ __('How long one Lead is kept for one person before it returns to the queue.') }}</flux:text>
        </div>

        <flux:input
            wire:model="holdMinutes"
            type="number"
            inputmode="numeric"
            :min="ContactCenterSettings::MINIMUM_HOLD"
            :max="ContactCenterSettings::MAXIMUM_HOLD"
            :label="__('Minutes')"
            class="max-w-40 tabular-nums"
        />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-hold">{{ __('Save hold length') }}</flux:button>
        </div>
    </form>

    {{-- Cancelled appointments --}}
    <form wire:submit="saveReleaseDays" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="release-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Cancelled appointments') }}</flux:heading>
            <flux:text>{{ __('How many days a lead whose appointment was cancelled stays with its salesperson to be booked again. After that it goes back to the call center, in the Cancelled appointments queue. The salesperson is warned :days days before.', ['days' => ContactCenterSettings::RELEASE_WARNING_DAYS]) }}</flux:text>
        </div>

        <flux:input
            wire:model="releaseDays"
            type="number"
            inputmode="numeric"
            :min="ContactCenterSettings::MINIMUM_RELEASE_DAYS"
            :max="ContactCenterSettings::MAXIMUM_RELEASE_DAYS"
            :label="__('Days')"
            class="max-w-40 tabular-nums"
            data-test="release-days"
        />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-release-days">{{ __('Save days') }}</flux:button>
        </div>
    </form>

    {{-- Pending proposals --}}
    <form wire:submit="saveProposalValidDays" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="proposal-valid-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Pending estimates') }}</flux:heading>
            <flux:text>{{ __('How many days an estimate is valid from the day it was presented. A lead stays with its salesperson until its last pending estimate expires, and the salesperson is warned :days days before. A new number applies to estimates presented from then on; the salesperson can change the day on any one estimate.', ['days' => ContactCenterSettings::PENDING_WARNING_DAYS]) }}</flux:text>
        </div>

        <flux:input
            wire:model="proposalValidDays"
            type="number"
            inputmode="numeric"
            :min="ContactCenterSettings::MINIMUM_PROPOSAL_VALID_DAYS"
            :max="ContactCenterSettings::MAXIMUM_PROPOSAL_VALID_DAYS"
            :label="__('Days an estimate is valid')"
            class="max-w-40 tabular-nums"
            data-test="proposal-valid-days"
        />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-proposal-valid-days">{{ __('Save days') }}</flux:button>
        </div>
    </form>

    {{-- Manager view for Call Center Agents --}}
    <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-6" data-test="manager-view-section">
        <div>
            <flux:heading size="lg" level="2">{{ __('Manager view for Call Center Agents') }}</flux:heading>
            <flux:text>{{ __('Switched on, an agent also sees the manager part of the Call Queue where they work: the summary, the timer warning, the "Cadence finished, no contact" list and Put back in queue, and the Call queue panel on a lead. It is off until you switch it on, and each switch saves by itself. Owners, Leadership and Location Managers always see the manager part.') }}</flux:text>
        </div>

        <flux:error name="agent" />

        @if ($this->agents->isEmpty())
            <flux:text data-test="manager-view-empty">{{ __('Nobody has Call Center Agent access yet.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-200">
                @foreach ($this->agents as $agent)
                    <li wire:key="manager-view-{{ $agent->id }}" class="py-3 first:pt-0 last:pb-0" data-test="manager-view-agent">
                        <flux:switch
                            wire:model.live="managerView.{{ $agent->id }}"
                            :label="$agent->name"
                            :description="$this->agentAccess($agent)"
                        />
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-confirmation-modal
        name="reset-calling-hours-confirmation"
        :title="__('Reset calling hours to 8:00 am to 9:00 pm?')"
        :description="__('Only the opening and closing times change. Calling days and everything else stay as they are. Attempts already scheduled keep their time.')"
        action="confirmResetHours"
        :confirm-label="__('Reset to default')"
        confirm-variant="primary"
    />
</section>
