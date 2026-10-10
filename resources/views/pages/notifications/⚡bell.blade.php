<?php

use App\Support\Access\Impersonation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The bell in the header (decision 91): how many notices the signed-in person
 * has not seen, and a short list of their latest, each a link.
 *
 * A notice belongs to one person and only that person's are ever read here.
 * Opening the list marks them seen, except while an Owner is viewing
 * Backoffice as that person: looking on their behalf must not use up their
 * notices.
 */
new class extends Component {
    /** How many of the latest notices the list shows. */
    public const int SHOWN = 10;

    /**
     * The notices that were unseen when the list was last opened, so they can
     * still be marked "New" in the list that marks them seen.
     *
     * @var list<string>
     */
    #[Locked]
    public array $newIds = [];

    #[Computed]
    public function unseen(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notices(): Collection
    {
        return Auth::user()->notifications()->limit(self::SHOWN)->get();
    }

    /**
     * The list was opened: remember which notices were new, then mark them seen.
     */
    public function open(Impersonation $impersonation): void
    {
        $user = Auth::user();

        $this->newIds = $user->unreadNotifications()->limit(self::SHOWN)->pluck('id')->all();

        if (! $impersonation->isActive()) {
            $user->unreadNotifications()->update(['read_at' => now()]);
        }

        unset($this->unseen, $this->notices);
    }
}; ?>

<div data-test="notification-bell">
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" size="sm" icon="bell" wire:click="open" :aria-label="$this->unseen > 0 ? trans_choice('{1} Notices, 1 new|[2,*] Notices, :count new', $this->unseen, ['count' => $this->unseen]) : __('Notices')" data-test="notification-bell-button">
            @if ($this->unseen > 0)
                <span class="rounded-full bg-banana px-1.5 text-xs font-semibold tabular-nums text-ink" data-test="notification-count">{{ $this->unseen > 99 ? '99+' : $this->unseen }}</span>
            @endif
        </flux:button>

        <flux:menu class="w-80 max-w-[90vw]">
            <div class="px-2 py-1.5">
                <flux:heading>{{ __('Notices') }}</flux:heading>
            </div>

            <flux:menu.separator />

            @forelse ($this->notices as $notice)
                <flux:menu.item
                    wire:key="notice-{{ $notice->id }}"
                    :href="$notice->data['url'] ?? route('dashboard')"
                    wire:navigate
                    class="!h-auto items-start whitespace-normal py-2"
                    data-test="notice"
                >
                    <span class="flex flex-col gap-0.5 text-start">
                        <span>
                            @if (in_array($notice->id, $newIds, true))
                                <span class="font-semibold" data-test="notice-new">{{ __('New') }}:</span>
                            @endif
                            {{ $notice->data['message'] ?? __('You have a notice.') }}
                        </span>
                        <span class="text-xs opacity-70">{{ $notice->created_at?->diffForHumans() }}</span>
                    </span>
                </flux:menu.item>
            @empty
                <div class="px-2 py-3 text-sm" data-test="notice-empty">{{ __('Nothing yet.') }}</div>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
