<?php

use App\Actions\Staff\InviteStaffMember;
use App\Concerns\ProfileValidationRules;
use App\Exceptions\InvitationNotSent;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users and Access')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return User::query()
            ->with('accessGrants.location')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create a staff account and email an invitation to choose a password.
     */
    public function invite(InviteStaffMember $inviteStaffMember): void
    {
        $this->authorize('create', User::class);

        $this->name = trim($this->name);

        // Compare and store the address in the same form sign-in uses.
        $this->email = User::normalizeEmail($this->email);

        $validated = $this->validate($this->profileRules());

        try {
            $staff = $inviteStaffMember->handle($validated['name'], $validated['email']);
        } catch (InvitationNotSent) {
            // Inviting is all or nothing, so there is no half-made account to tidy up.
            $this->addError('email', __('The invitation email could not be sent, so no account was created. Check the address and try again. If it keeps happening, the mail settings need attention.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $staff->email]));

        $this->redirectRoute('settings.users.show', $staff, navigate: true);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users and Access') }}</flux:heading>
            <flux:subheading>{{ __('Staff accounts, and what each person can do at which locations.') }}</flux:subheading>
        </div>

        @can('create', User::class)
            <flux:modal.trigger name="invite-staff">
                <flux:button variant="primary" icon="envelope" data-test="invite-staff-button">{{ __('Invite staff member') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-card px-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Access') }}</flux:table.column>
                <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->staff as $member)
                    <flux:table.row :key="$member->id">
                        <flux:table.cell>
                            <div class="font-medium text-ink">{{ $member->name }}</div>
                            <div class="text-sm text-zinc-500">{{ $member->email }}</div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <x-staff-status :staff="$member" />
                        </flux:table.cell>

                        <flux:table.cell>
                            @forelse ($member->accessGrants as $grant)
                                <div class="text-sm">
                                    <span class="font-medium text-ink">{{ $grant->role->label() }}</span>
                                    <span class="text-zinc-500">&middot; {{ $grant->isCompanyWide() ? __('All locations') : $grant->location?->name }}</span>
                                </div>
                            @empty
                                <span class="text-sm text-zinc-500">{{ __('No access assigned') }}</span>
                            @endforelse
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <flux:button size="sm" :href="route('settings.users.show', $member)" wire:navigate>{{ __('Manage') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="invite-staff" class="w-full max-w-md">
        <form wire:submit="invite" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Invite staff member') }}</flux:heading>
                <flux:subheading>{{ __('They will receive an email with a link to choose their own password. You grant their access on the next screen.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Full name')" type="text" required autocomplete="off" />
            <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Send invitation') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
