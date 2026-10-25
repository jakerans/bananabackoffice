@inject('impersonation', \App\Support\Access\Impersonation::class)

<flux:dropdown position="bottom" align="end">
    <flux:profile
        :initials="auth()->user()->initials()"
        data-test="user-menu-button"
    />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            {{-- A person's own account settings are closed while someone else is viewing Backoffice as them. --}}
            @unless ($impersonation->isActive())
                <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>
                    {{ __('My account') }}
                </flux:menu.item>
            @endunless
            {{-- A person's own pay plans and ledger, once they have any (slice 20). --}}
            @if (auth()->user()->hasPay())
                <flux:menu.item :href="route('pay')" icon="banknotes" wire:navigate data-test="my-pay">
                    {{ __('My pay') }}
                </flux:menu.item>
            @endif
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
