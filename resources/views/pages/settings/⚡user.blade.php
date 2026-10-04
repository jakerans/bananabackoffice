<?php

use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\GrantAccess;
use App\Actions\Staff\ReactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Actions\Staff\SendStaffInvitation;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new #[Title('Manage access')] class extends Component {
    public User $staff;

    public string $role = '';

    public string $scope = GrantScope::Location->value;

    public ?int $locationId = null;

    /**
     * Mount the component.
     */
    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->staff = $user;
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()->active()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, AccessGrant>
     */
    #[Computed]
    public function grants(): Collection
    {
        return $this->staff->accessGrants()->with('location')->oldest()->orderBy('id')->get();
    }

    /**
     * The Owner role is always company-wide, so the scope follows the role.
     */
    public function updatedRole(): void
    {
        if (Role::tryFrom($this->role)?->requiresAllLocations()) {
            $this->scope = GrantScope::AllLocations->value;
        }
    }

    /**
     * Give the staff member a role at one location or across all locations.
     */
    public function grant(GrantAccess $grantAccess): void
    {
        $this->authorize('update', $this->staff);

        $validated = $this->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'scope' => ['required', Rule::enum(GrantScope::class)],
            'locationId' => [
                Rule::requiredIf($this->scope === GrantScope::Location->value),
                'nullable',
                'integer',
                Rule::exists(Location::class, 'id')->where('is_active', true),
            ],
        ], attributes: ['locationId' => 'location']);

        $scope = GrantScope::from($validated['scope']);

        $grantAccess->handle(
            grantedBy: Auth::user(),
            staff: $this->staff,
            role: Role::from($validated['role']),
            scope: $scope,
            location: $scope === GrantScope::Location ? Location::query()->find($validated['locationId']) : null,
        );

        $this->reset('role', 'scope', 'locationId');
        $this->accessChanged();

        Flux::toast(variant: 'success', text: __('Access granted.'));
    }

    /**
     * Remove one of this staff member's access grants.
     */
    public function revoke(int $grantId, RevokeAccess $revokeAccess): void
    {
        $this->authorize('update', $this->staff);

        // Looked up through this staff member, so a grant belonging to someone else is never touched.
        $revokeAccess->handle($this->staff->accessGrants()->findOrFail($grantId));

        $this->accessChanged();

        Flux::toast(variant: 'success', text: __('Access removed.'));
    }

    /**
     * Stop the staff member signing in.
     */
    public function deactivate(DeactivateStaffMember $deactivateStaffMember): void
    {
        $this->authorize('deactivate', $this->staff);

        $deactivateStaffMember->handle($this->staff);

        Flux::toast(variant: 'success', text: __('Account deactivated.'));
    }

    /**
     * Let the staff member sign in again.
     */
    public function reactivate(ReactivateStaffMember $reactivateStaffMember): void
    {
        $this->authorize('deactivate', $this->staff);

        $reactivateStaffMember->handle($this->staff);

        Flux::toast(variant: 'success', text: __('Account reactivated.'));
    }

    /**
     * Email a fresh invitation link, which replaces any earlier one.
     */
    public function resendInvitation(SendStaffInvitation $sendStaffInvitation): void
    {
        $this->authorize('update', $this->staff);

        abort_unless($this->staff->isActive() && $this->staff->invitationIsPending(), 403);

        try {
            $sendStaffInvitation->handle($this->staff);
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            Flux::toast(variant: 'danger', duration: 0, text: __('The invitation email could not be sent. Check the mail settings and try again.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $this->staff->email]));
    }

    /**
     * When someone changes their own access, the shell must reflect it straight away.
     */
    private function accessChanged(): void
    {
        unset($this->grants);

        if ($this->staff->is(Auth::user())) {
            Auth::user()->unsetRelation('accessGrants');

            app(CurrentLocation::class)->forget();
        }
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <div>
        <flux:link :href="route('settings.users')" wire:navigate class="text-sm">&larr; {{ __('Users and Access') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-1">
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1">{{ $staff->name }}</flux:heading>
                <x-staff-status :staff="$staff" />
            </div>
            <flux:subheading>{{ $staff->email }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($staff->isActive() && $staff->invitationIsPending())
                <flux:button wire:click="resendInvitation" icon="envelope">{{ __('Resend invitation') }}</flux:button>
            @endif

            @can('deactivate', $staff)
                @if ($staff->isActive())
                    <flux:button
                        wire:click="deactivate"
                        wire:confirm="{{ __('Deactivate :name? They will be signed out and will not be able to sign in again until reactivated.', ['name' => $staff->name]) }}"
                    >
                        {{ __('Deactivate account') }}
                    </flux:button>
                @else
                    <flux:button wire:click="reactivate">{{ __('Reactivate account') }}</flux:button>
                @endif
            @endcan
        </div>
    </div>

    <flux:error name="staff" />

    <div class="flex flex-col gap-3">
        <flux:heading size="lg" level="2">{{ __('Access') }}</flux:heading>

        <flux:error name="grant" />

        @if ($this->grants->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 bg-card px-4 py-6 text-sm text-zinc-600">
                {{ __('No access assigned yet. Until a role is granted below, this person can sign in but cannot open any center.') }}
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-card px-4">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Role') }}</flux:table.column>
                        <flux:table.column>{{ __('Where') }}</flux:table.column>
                        <flux:table.column>{{ __('Granted') }}</flux:table.column>
                        <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->grants as $grant)
                            <flux:table.row :key="$grant->id">
                                <flux:table.cell variant="strong">{{ $grant->role->label() }}</flux:table.cell>

                                <flux:table.cell>
                                    @if ($grant->isCompanyWide())
                                        {{ __('All locations') }}
                                    @else
                                        {{ $grant->location?->name }}
                                        @if ($grant->location && ! $grant->location->is_active)
                                            <span class="text-zinc-500">({{ __('inactive') }})</span>
                                        @endif
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell class="tabular-nums">{{ $grant->created_at?->toFormattedDateString() }}</flux:table.cell>

                                <flux:table.cell align="end">
                                    <flux:button
                                        size="sm"
                                        wire:click="revoke({{ $grant->id }})"
                                        wire:confirm="{{ __('Remove this access?') }}"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>

    <form wire:submit="grant" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
        <div>
            <flux:heading size="lg" level="2">{{ __('Grant access') }}</flux:heading>
            <flux:subheading>{{ __('A role says what this person can do. The location says where. Each grant stands on its own.') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select wire:model.live="role" :label="__('Role')" :placeholder="__('Choose a role')">
                @foreach (Role::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="scope" :label="__('Where')">
                @foreach (GrantScope::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($scope === GrantScope::Location->value)
                <flux:select wire:model="locationId" :label="__('Location')" :placeholder="__('Choose a location')">
                    @foreach ($this->locations as $location)
                        <flux:select.option :value="$location->id">{{ $location->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Grant access') }}</flux:button>
        </div>
    </form>
</section>
