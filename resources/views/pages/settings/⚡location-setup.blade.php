<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Locations\ChangeDepositPolicy;
use App\Actions\Locations\ChangeLocationTimezone;
use App\Actions\Locations\ChangeScorecardSetup;
use App\Actions\Locations\ChangeTargetMargin;
use App\Actions\Locations\SwitchOnLocation;
use App\Actions\Locations\UpdateServiceAreaPostalCodes;
use App\Actions\Staff\GrantAccess;
use App\Actions\Staff\InviteStaffMember;
use App\Concerns\ProfileValidationRules;
use App\Enums\GrantScope;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\InvitationNotSent;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\PayPlan;
use App\Models\User;
use App\Support\Access\Impersonation;
use App\Support\Locations\LocationSetup;
use App\Support\Locations\SetupStep;
use App\Support\Money;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\Queue\LocationTimezones;
use App\Support\Scorecards\ScorecardOwnership;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Settings > Locations > a Location > Set up (docs/slice-37-location-setup-plan.md,
 * step 37b; decisions 220 and 226): its steps in order, each marked done or
 * not. Its people, their pay plans, its scorecard and its QuickBooks company
 * are done right here, through the same actions as their own pages, by
 * whoever may do each; prices and subcontractors keep their own pages. A new
 * Location is switched on from here once its required steps are done.
 */
new #[Title('Set up a Location')] class extends Component {
    use ProfileValidationRules;

    /** The roles a Location's own people are given on its checklist. */
    private const array PEOPLE_ROLES = [Role::LocationManager, Role::SalesConsultant, Role::ProductionManager, Role::CallCenterAgent];

    #[Locked]
    public int $locationId;

    public string $timezone = '';

    public string $postalCodes = '';

    public string $depositPercent = '';

    public string $targetMarginPercent = '';

    /** Someone new to invite, with their role here. */
    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'location_manager';

    /** Someone already in Backoffice to give a role here. */
    public string $grantUserId = '';

    public string $grantRole = 'sales_consultant';

    /**
     * The pay plan chosen for each salesperson, and the day it starts.
     *
     * @var array<int|string, string>
     */
    public array $payPlans = [];

    /** @var array<int|string, string> */
    public array $payPlansFrom = [];

    /** Whose pay plan was last set, so its errors show beside them. */
    public ?int $payPlanUserId = null;

    public string $scorecardOwnerId = '';

    public string $scorecardNsliTarget = '';

    public string $scorecardCostPerCloseTarget = '';

    public string $scorecardNetProfitTarget = '';

    public function mount(Location $location): void
    {
        $this->authorize('update', $location);

        $this->locationId = $location->id;
        $this->fillForms();
    }

    #[Computed]
    public function location(): Location
    {
        return Location::query()->with('scorecardOwner')->findOrFail($this->locationId);
    }

    /**
     * @return list<SetupStep>
     */
    #[Computed]
    public function steps(): array
    {
        return app(LocationSetup::class)->steps($this->location);
    }

    /**
     * @return list<SetupStep>
     */
    #[Computed]
    public function company(): array
    {
        return app(LocationSetup::class)->company();
    }

    #[Computed]
    public function nextStep(): ?SetupStep
    {
        return app(LocationSetup::class)->next($this->location);
    }

    /**
     * @return list<SetupStep>
     */
    #[Computed]
    public function missing(): array
    {
        return app(LocationSetup::class)->missing($this->location);
    }

    /**
     * Inviting people and giving them access, for whoever manages staff, as
     * themselves: never while viewing Backoffice as someone else.
     */
    #[Computed]
    public function mayManagePeople(): bool
    {
        return Gate::allows('create', User::class) && ! app(Impersonation::class)->isActive();
    }

    #[Computed]
    public function mayManagePay(): bool
    {
        return Gate::allows(Permission::ManageCompensation);
    }

    /**
     * Everyone with a role at this Location, by name, with their roles here.
     *
     * @return Collection<int, array{user: User, roles: list<string>}>
     */
    #[Computed]
    public function people(): Collection
    {
        return AccessGrant::query()
            ->with('user')
            ->where('scope', GrantScope::Location)
            ->where('location_id', $this->locationId)
            ->get()
            ->filter(fn (AccessGrant $grant): bool => $grant->user->is_active)
            ->groupBy('user_id')
            ->map(fn (Collection $grants): array => [
                'user' => $grants->first()->user,
                'roles' => $grants->map(fn (AccessGrant $grant): string => $grant->role->label())->sort()->values()->all(),
            ])
            ->sortBy(fn (array $person): string => $person['user']->name)
            ->values();
    }

    /**
     * People already in Backoffice who may be given a role here: everyone
     * active who does not already work at every Location.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function grantCandidates(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->reject(fn (User $user): bool => $user->accessGrants->contains(fn (AccessGrant $grant): bool => $grant->isCompanyWide()))
            ->values();
    }

    /**
     * This Location's salespeople, by name, with the sales plan each is on today.
     *
     * @return Collection<int, array{user: User, plan: PayPlan|null}>
     */
    #[Computed]
    public function salespeople(): Collection
    {
        $today = $this->today();

        return AccessGrant::query()
            ->with('user')
            ->where('scope', GrantScope::Location)
            ->where('location_id', $this->locationId)
            ->where('role', Role::SalesConsultant)
            ->get()
            ->map(fn (AccessGrant $grant): User => $grant->user)
            ->filter(fn (User $user): bool => $user->is_active)
            ->unique('id')
            ->sortBy('name')
            ->map(fn (User $user): array => ['user' => $user, 'plan' => PayPlan::onDay($user->id, PayPlan::FAMILY_SALES, $today)])
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function scorecardCandidates(): Collection
    {
        return ScorecardOwnership::candidates($this->location);
    }

    /**
     * @return array<string, string>
     */
    public function peopleRoles(): array
    {
        return collect(self::PEOPLE_ROLES)->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public function salesPlans(): array
    {
        return collect(PayPlan::PLANS)
            ->filter(fn (array $terms): bool => $terms['family'] === PayPlan::FAMILY_SALES)
            ->map(fn (array $terms): string => $terms['label'])
            ->all();
    }

    public function saveTimezone(ChangeLocationTimezone $changeLocationTimezone): void
    {
        $location = $this->authorizedLocation();

        $changeLocationTimezone->handle($location, $this->timezone);

        $this->saved(__('Time zone saved.'));
    }

    public function saveServiceArea(UpdateServiceAreaPostalCodes $updateServiceAreaPostalCodes): void
    {
        $location = $this->authorizedLocation();

        $this->validate(['postalCodes' => ['nullable', 'string', 'max:20000']], attributes: ['postalCodes' => 'ZIP codes']);

        $updateServiceAreaPostalCodes->handle($location, preg_split('/[\s,;]+/', trim($this->postalCodes)) ?: []);

        $this->saved(__('Service area saved.'));
    }

    public function saveDepositAndMargin(ChangeDepositPolicy $changeDepositPolicy, ChangeTargetMargin $changeTargetMargin): void
    {
        $location = $this->authorizedLocation();

        $changeDepositPolicy->handle($location, $this->depositPercent, Auth::user());
        $changeTargetMargin->handle($location->refresh(), $this->targetMarginPercent, Auth::user());

        $this->saved(__('Deposit and target margin saved.'));
    }

    /**
     * Invite someone new with their role here, in one go: if the invitation
     * cannot be sent, neither the account nor the access is kept.
     */
    public function invitePerson(InviteStaffMember $inviteStaffMember, GrantAccess $grantAccess): void
    {
        $location = $this->peopleLocation();
        $this->authorize('create', User::class);

        $this->inviteName = trim($this->inviteName);
        // Compare and store the address in the same form sign-in uses.
        $this->inviteEmail = User::normalizeEmail($this->inviteEmail);

        $this->validate([
            'inviteName' => $this->nameRules(),
            // The same rules as inviting on Users and Access, checked against the email column, not this field's name.
            'inviteEmail' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'inviteRole' => ['required', Rule::in(array_keys($this->peopleRoles()))],
        ], attributes: ['inviteName' => 'name', 'inviteEmail' => 'email address', 'inviteRole' => 'role']);

        $role = Role::from($this->inviteRole);
        $actor = Auth::user();

        try {
            $staff = $inviteStaffMember->handle($this->inviteName, $this->inviteEmail, function (User $staff) use ($grantAccess, $actor, $role, $location): void {
                $grantAccess->handle($actor, $staff, $role, GrantScope::Location, $location);
            });
        } catch (InvitationNotSent) {
            $this->addError('inviteEmail', __('The invitation email could not be sent, so no account was created. Check the address and try again. If it keeps happening, the mail settings need attention.'));

            return;
        }

        $this->reset('inviteName', 'inviteEmail', 'inviteRole');
        $this->saved(__('Invitation sent to :email, as :role here.', ['email' => $staff->email, 'role' => $role->label()]));
    }

    /**
     * Give someone already in Backoffice a role here.
     */
    public function grantPerson(GrantAccess $grantAccess): void
    {
        $location = $this->peopleLocation();

        $this->validate([
            'grantUserId' => ['required', Rule::in($this->grantCandidates->modelKeys())],
            'grantRole' => ['required', Rule::in(array_keys($this->peopleRoles()))],
        ], ['grantUserId.required' => __('Choose who.'), 'grantUserId.in' => __('Choose who.')]);

        $staff = User::query()->findOrFail((int) $this->grantUserId);
        $this->authorize('update', $staff);
        $role = Role::from($this->grantRole);

        $grantAccess->handle(Auth::user(), $staff, $role, GrantScope::Location, $location);

        $this->reset('grantUserId', 'grantRole');
        $this->saved(__(':name is now :role here.', ['name' => $staff->name, 'role' => $role->label()]));
    }

    /**
     * Put one of this Location's salespeople on their first plan. Nobody sets
     * their own, and changing a plan someone already has, which says why, is
     * done on their pay page.
     */
    public function savePayPlan(int $userId, ManagePay $managePay): void
    {
        $this->authorizedLocation();
        $this->payPlanUserId = $userId;

        $salesperson = $this->salespeople->first(fn (array $salesperson): bool => $salesperson['user']->id === $userId);

        abort_if($salesperson === null, 404);

        $managePay->setPlan($salesperson['user'], $this->payPlans[$userId] ?? '', $this->payPlansFrom[$userId] ?? '', null, Auth::user());

        unset($this->payPlans[$userId], $this->payPlansFrom[$userId]);
        $this->payPlanUserId = null;
        $this->saved(__('Pay plan set for :name.', ['name' => $salesperson['user']->name]));
    }

    public function saveScorecard(ChangeScorecardSetup $changeScorecardSetup): void
    {
        $location = $this->authorizedLocation();

        $changeScorecardSetup->handle($location, $this->scorecardOwnerId === '' ? null : (int) $this->scorecardOwnerId, $this->scorecardNsliTarget, $this->scorecardCostPerCloseTarget, $this->scorecardNetProfitTarget, Auth::user());

        $this->saved(__('Scorecard saved.'));
    }

    /**
     * Switch a new Location on, once its required steps are done.
     */
    public function switchOn(SwitchOnLocation $switchOnLocation): void
    {
        $location = $this->authorizedLocation();

        $switchOnLocation->handle($location);

        $this->saved(__(':name is switched on.', ['name' => $location->name]));
    }

    /**
     * The Location, found and authorized again for each change.
     */
    private function authorizedLocation(): Location
    {
        $location = Location::query()->findOrFail($this->locationId);

        $this->authorize('update', $location);
        $this->resetValidation();

        return $location;
    }

    /**
     * The Location, for a change to its people: only by whoever manages
     * staff, as themselves.
     */
    private function peopleLocation(): Location
    {
        app(Impersonation::class)->refuseStaffAdministration();

        $location = $this->authorizedLocation();
        $this->authorize('viewAny', User::class);

        return $location;
    }

    private function today(): string
    {
        return now()->setTimezone((string) config('app.display_timezone'))->toDateString();
    }

    private function saved(string $message): void
    {
        unset($this->location, $this->steps, $this->company, $this->nextStep, $this->missing, $this->people, $this->grantCandidates, $this->salespeople, $this->scorecardCandidates);
        $this->fillForms();

        Flux::toast(variant: 'success', text: $message);
    }

    private function fillForms(): void
    {
        $location = $this->location;

        $this->timezone = $location->timezone;
        $this->postalCodes = $location->servicePostalCodes()->orderBy('postal_code')->pluck('postal_code')->implode("\n");
        $this->depositPercent = (string) $location->deposit_percent;
        $this->targetMarginPercent = (string) $location->target_margin_percent;
        $this->scorecardOwnerId = (string) $location->scorecard_owner_id;
        $this->scorecardNsliTarget = $location->nsli_target_cents === null ? '' : ltrim(Money::format($location->nsli_target_cents), '$');
        $this->scorecardCostPerCloseTarget = $location->cost_per_close_target_cents === null ? '' : ltrim(Money::format($location->cost_per_close_target_cents), '$');
        $this->scorecardNetProfitTarget = (string) $location->net_profit_target_percent;

        foreach ($this->salespeople as $salesperson) {
            $this->payPlansFrom[$salesperson['user']->id] ??= $this->today();
        }
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="location-setup">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <flux:link :href="route('settings.locations')" wire:navigate class="text-sm">{{ __('← Locations') }}</flux:link>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ __('Set up :name', ['name' => $this->location->name]) }}</flux:heading>
                @if ($this->location->is_active)
                    <flux:badge size="sm" color="green" icon="check-circle">{{ __('Switched on') }}</flux:badge>
                @elseif ($this->location->isBeingSetUp())
                    <flux:badge size="sm" color="amber" icon="wrench-screwdriver" data-test="setup-being-set-up">{{ __('Being set up') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc" icon="pause-circle">{{ __('Inactive') }}</flux:badge>
                @endif
            </div>
            <flux:subheading>{{ __('Its steps in order. Any can be done first, by whoever may. ✱ steps are needed before it is switched on; until then no lead is routed to it and nothing is booked there.') }}</flux:subheading>
        </div>

        @if ($this->nextStep !== null)
            <flux:button variant="primary" icon:trailing="arrow-right" :href="'#step-'.$this->nextStep->key" data-test="next-step">{{ __('Next step: :step', ['step' => $this->nextStep->label]) }}</flux:button>
        @endif
    </div>

    @php
        $quickBooksStatus = session('quickbooks_status');
    @endphp

    @if (is_array($quickBooksStatus))
        <flux:callout :color="['success' => 'green', 'warning' => 'amber'][$quickBooksStatus['variant']] ?? 'rose'" icon="information-circle" data-test="quickbooks-status">
            <flux:callout.text>{{ $quickBooksStatus['text'] }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->location->isBeingSetUp())
        <div class="flex flex-col gap-3 rounded-xl border p-5 {{ $this->missing === [] ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50' }}" data-test="switch-on-panel">
            @if ($this->missing === [])
                <flux:heading size="lg" level="2">{{ __('Ready to switch on') }}</flux:heading>
                <flux:text>{{ __('Every ✱ step is done. Switched on, new leads in its service area are routed here and appointments can be booked.') }}</flux:text>
            @else
                <flux:heading size="lg" level="2">{{ __('Not ready to switch on yet') }}</flux:heading>
                <flux:text data-test="still-missing">{{ __('Still to do: :steps.', ['steps' => collect($this->missing)->map(fn ($step) => mb_strtolower($step->label))->join(', ')]) }}</flux:text>
            @endif

            @error('switchOn')
                <flux:text class="text-red-700" role="alert">{{ $message }}</flux:text>
            @enderror

            <div>
                <flux:button variant="primary" wire:click="switchOn" :disabled="$this->missing !== []" data-test="switch-on">{{ __('Switch on :name', ['name' => $this->location->name]) }}</flux:button>
            </div>
        </div>
    @endif

    @if (collect($this->company)->contains(fn ($step) => ! $step->done))
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="company-steps">
            <div>
                <flux:heading size="lg" level="2">{{ __('Once, for the company') }}</flux:heading>
                <flux:subheading>{{ __('Shown until each is done.') }}</flux:subheading>
            </div>

            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->company as $step)
                    <li wire:key="company-{{ $step->key }}" class="flex flex-wrap items-center justify-between gap-3 py-3" data-test="company-step-{{ $step->key }}" data-done="{{ $step->done ? 'yes' : 'no' }}">
                        <div class="flex min-w-0 items-start gap-3">
                            @if ($step->done)
                                <flux:icon.check-circle variant="solid" class="mt-0.5 size-5 shrink-0 text-green-600" />
                            @else
                                <flux:icon.minus-circle class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                            @endif
                            <div class="min-w-0">
                                <div class="font-medium text-ink">{{ $step->label }}@if ($step->required) <span class="text-amber-700" title="{{ __('Required') }}">✱</span>@endif</div>
                                <flux:text class="text-sm">{{ $step->done ? __('Done.') : $step->detail }}</flux:text>
                            </div>
                        </div>
                        @if (! $step->done && $step->url !== null)
                            <flux:button size="sm" :href="$step->url" wire:navigate>{{ $step->action }}</flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <ol class="flex flex-col gap-3" data-test="location-steps">
        @foreach ($this->steps as $number => $step)
            <li wire:key="step-{{ $step->key }}" id="step-{{ $step->key }}" class="flex scroll-mt-24 flex-col gap-3 rounded-xl border bg-card p-5 {{ $step->done ? 'border-zinc-200' : ($step->required ? 'border-amber-300' : 'border-zinc-200') }}" data-test="step-{{ $step->key }}" data-done="{{ $step->done ? 'yes' : 'no' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums {{ $step->done ? 'bg-green-600 text-white' : 'bg-zinc-100 text-zinc-700' }}">
                            @if ($step->done)
                                <flux:icon.check variant="micro" />
                            @else
                                {{ $number + 1 }}
                            @endif
                        </span>
                        <div class="min-w-0">
                            <flux:heading size="lg" level="2">{{ $step->label }}@if ($step->required) <span class="text-amber-700" title="{{ __('Required before it is switched on') }}">✱</span>@endif</flux:heading>
                            <flux:text class="text-sm" data-test="step-detail-{{ $step->key }}">{{ $step->detail }}</flux:text>
                        </div>
                    </div>
                    @if ($step->url !== null)
                        <flux:button size="sm" :variant="$step->done ? 'ghost' : 'outline'" icon:trailing="arrow-top-right-on-square" :href="$step->url" wire:navigate class="h-auto! min-h-8 max-w-full py-1 text-start whitespace-normal!" data-test="step-link-{{ $step->key }}">{{ $step->action }}</flux:button>
                    @endif
                </div>

                @if ($step->key === 'name')
                    <form wire:submit="saveTimezone" class="flex flex-wrap items-end gap-3 sm:pl-10">
                        <flux:select wire:model="timezone" :label="__('Time zone')" class="max-w-xs" data-test="setup-timezone">
                            @foreach (LocationTimezones::options() as $identifier => $label)
                                <flux:select.option :value="$identifier">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>
                        <flux:error name="editingTimezone" />
                    </form>
                @elseif ($step->key === 'service_area')
                    <form wire:submit="saveServiceArea" class="flex flex-col gap-3 sm:pl-10">
                        <flux:textarea wire:model="postalCodes" :label="__('ZIP codes')" :description="__('One per line, or separated by commas or spaces.')" rows="4" class="tabular-nums" data-test="setup-postal-codes" />
                        <div>
                            <flux:button type="submit" size="sm" data-test="save-setup-postal-codes">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @elseif ($step->key === 'deposit_margin')
                    <form wire:submit="saveDepositAndMargin" class="flex flex-wrap items-start gap-3 sm:pl-10">
                        <flux:input wire:model="depositPercent" inputmode="numeric" :label="__('Deposit (%)')" class="max-w-32" data-test="setup-deposit" />
                        <flux:input wire:model="targetMarginPercent" inputmode="numeric" :label="__('Target margin (%)')" class="max-w-40" data-test="setup-margin" />
                        <div class="self-end">
                            <flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @elseif ($step->key === 'people')
                    <div class="flex flex-col gap-4 sm:pl-10" data-test="setup-people">
                        @if ($this->people->isNotEmpty())
                            <ul class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200">
                                @foreach ($this->people as $person)
                                    <li wire:key="setup-person-{{ $person['user']->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-test="setup-person-{{ $person['user']->id }}">
                                        <div class="min-w-0">
                                            <div class="font-medium text-ink">{{ $person['user']->name }}</div>
                                            <div class="text-sm text-zinc-500">{{ implode(', ', $person['roles']) }}</div>
                                        </div>
                                        @if ($person['user']->invitationIsPending())
                                            <flux:badge size="sm" color="amber">{{ __('Invitation not used yet') }}</flux:badge>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($this->mayManagePeople)
                            <form wire:submit="invitePerson" class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-4" data-test="setup-invite">
                                <div>
                                    <flux:heading size="sm" level="3">{{ __('Invite someone new') }}</flux:heading>
                                    <flux:text class="text-sm">{{ __('They get an email with a link to choose their own password, and have this role here.') }}</flux:text>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <flux:input wire:model="inviteName" :label="__('Full name')" autocomplete="off" data-test="setup-invite-name" />
                                    <flux:input wire:model="inviteEmail" type="email" :label="__('Email address')" autocomplete="off" data-test="setup-invite-email" />
                                    <flux:select wire:model="inviteRole" :label="__('Role here')" data-test="setup-invite-role">
                                        @foreach ($this->peopleRoles() as $value => $label)
                                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <div>
                                    <flux:button type="submit" size="sm" variant="primary" icon="envelope" data-test="setup-invite-send">{{ __('Send invitation') }}</flux:button>
                                </div>
                            </form>

                            @if ($this->grantCandidates->isNotEmpty())
                                <form wire:submit="grantPerson" class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-4" data-test="setup-grant">
                                    <flux:heading size="sm" level="3">{{ __('Or give someone already in Backoffice a role here') }}</flux:heading>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:select wire:model="grantUserId" :label="__('Person')" data-test="setup-grant-person">
                                            <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                                            @foreach ($this->grantCandidates as $candidate)
                                                <flux:select.option :value="$candidate->id" wire:key="setup-grant-candidate-{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:select wire:model="grantRole" :label="__('Role here')" data-test="setup-grant-role">
                                            @foreach ($this->peopleRoles() as $value => $label)
                                                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <flux:error name="role" />
                                    <flux:error name="locationId" />
                                    <div>
                                        <flux:button type="submit" size="sm" data-test="setup-grant-save">{{ __('Give access') }}</flux:button>
                                    </div>
                                </form>
                            @endif
                        @endif
                    </div>
                @elseif ($step->key === 'pay_plans' && $this->salespeople->isNotEmpty())
                    <ul class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 sm:ml-10" data-test="setup-pay-plans">
                        @foreach ($this->salespeople as $salesperson)
                            @php($person = $salesperson['user'])
                            <li wire:key="setup-pay-{{ $person->id }}" class="flex flex-col gap-2 px-3 py-3" data-test="setup-pay-{{ $person->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="font-medium text-ink">{{ $person->name }}</div>
                                    @if ($salesperson['plan'] !== null)
                                        <flux:text class="text-sm">{{ __($salesperson['plan']->label()) }}</flux:text>
                                    @elseif (! $this->mayManagePay)
                                        <flux:text class="text-sm">{{ __('No plan yet. Finance sets it.') }}</flux:text>
                                    @elseif ($person->is(auth()->user()))
                                        <flux:text class="text-sm">{{ __('No plan yet. Someone else sets your own.') }}</flux:text>
                                    @endif
                                </div>

                                @if ($salesperson['plan'] === null && $this->mayManagePay && ! $person->is(auth()->user()))
                                    <form wire:submit="savePayPlan({{ $person->id }})" class="flex flex-wrap items-end gap-3">
                                        <flux:select wire:model="payPlans.{{ $person->id }}" :label="__('Plan')" class="max-w-md" data-test="setup-pay-plan-{{ $person->id }}">
                                            <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                                            @foreach ($this->salesPlans() as $key => $label)
                                                <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:input wire:model="payPlansFrom.{{ $person->id }}" type="date" :label="__('Starts')" class="max-w-48" data-test="setup-pay-from-{{ $person->id }}" />
                                        <flux:button type="submit" size="sm" data-test="setup-pay-save-{{ $person->id }}">{{ __('Set') }}</flux:button>
                                    </form>
                                    @if ($payPlanUserId === $person->id)
                                        <flux:error name="plan" />
                                        <flux:error name="planEffectiveFrom" />
                                        <flux:error name="planReason" />
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @elseif ($step->key === 'scorecard')
                    <form wire:submit="saveScorecard" class="flex flex-col gap-3 sm:pl-10" data-test="setup-scorecard">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2 sm:max-w-sm">
                                <flux:select wire:model="scorecardOwnerId" :label="__('Owner')" :description="$this->scorecardCandidates->isEmpty() ? __('Its managers can be chosen once they have access here.') : null" data-test="setup-scorecard-owner">
                                    <flux:select.option value="">{{ __('The Owner') }}</flux:select.option>
                                    @foreach ($this->scorecardCandidates as $candidate)
                                        <flux:select.option :value="$candidate->id" wire:key="setup-scorecard-candidate-{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <flux:input wire:model="scorecardNsliTarget" inputmode="decimal" icon="currency-dollar" :label="__('NSLI target')" :description="__('Net sales per Lead issued.')" data-test="setup-scorecard-nsli" />
                            <flux:input wire:model="scorecardCostPerCloseTarget" inputmode="decimal" icon="currency-dollar" :label="__('Cost per close target')" :description="__('Marketing spend for each sale, at most.')" data-test="setup-scorecard-cost-per-close" />
                            <flux:input wire:model="scorecardNetProfitTarget" inputmode="numeric" icon:trailing="percent-badge" :label="__('Net Profit % target')" :description="__('Net income over income, as its QuickBooks reports it.')" data-test="setup-scorecard-net-profit" />
                        </div>
                        <div>
                            <flux:button type="submit" size="sm" data-test="setup-scorecard-save">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @elseif ($step->key === 'quickbooks' && ! $step->done)
                    <div class="flex flex-col gap-2 sm:pl-10">
                        @if (QuickBooksClient::isConfigured())
                            <flux:text class="text-sm">{{ __('Sign in with this Location\'s own QuickBooks login and approve Backoffice; you come back here.') }}</flux:text>
                            <div>
                                <flux:button size="sm" variant="primary" icon="link" :href="route('quickbooks.connect', ['location' => $this->location, 'setup' => 1])" data-test="setup-quickbooks-connect">{{ __('Connect QuickBooks') }}</flux:button>
                            </div>
                        @else
                            <flux:text class="text-sm">{{ __('QuickBooks is not set up on this server yet.') }}</flux:text>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
