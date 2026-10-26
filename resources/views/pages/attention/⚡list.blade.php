<?php

use App\Enums\Permission;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Support\Attention\Rules;
use App\Support\Attention\Visibility;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Needs Attention (docs/slice-26-needs-attention-plan.md, step 26b; decision
 * 185, questions 4 and 5; product-architecture.md, 18 and 19): what the
 * exception engine found, the most serious first.
 *
 * - "mine": the person's own items, on Home; each can be marked useful, not
 *   useful, or dismissed with an optional reason.
 * - "locations": every item at the Locations a manager manages, with its
 *   owners.
 * - "company": every item, for the Owner and Leadership.
 *
 * Never an item about a record the person may not open.
 */
new class extends Component {
    #[Locked]
    public string $scope = 'mine';

    #[Locked]
    public ?int $dismissingId = null;

    public string $dismissReason = '';

    public function mount(string $scope = 'mine'): void
    {
        abort_unless(in_array($scope, ['mine', 'locations', 'company'], true), 404);

        if ($scope === 'company') {
            $this->authorize(Permission::CompanyOverviewAccess);
        }

        $this->scope = $scope;
    }

    /**
     * The items to show, grouped by what they are about, each with its owners.
     *
     * @return Collection<int, array{item: AttentionItem, owners: list<string>}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $user = Auth::user();
        $query = AttentionItem::query()->whereNull('closed_at')->with(['user', 'location']);

        if ($this->scope === 'mine') {
            $query->where('user_id', $user->id)->where(fn ($open) => $open->whereNull('feedback')->orWhere('feedback', '!=', AttentionItem::DISMISSED));
        } elseif ($this->scope === 'locations') {
            $query->whereIn('location_id', $user->locationsWhere(Permission::ManageProjects)->modelKeys());
        }

        $locations = Location::query()->get();

        return $query->get()
            ->filter(fn (AttentionItem $item): bool => Visibility::allows($user, $item, $locations))
            ->groupBy(fn (AttentionItem $item): string => $item->rule.'|'.$item->subject_key)
            ->map(fn (Collection $items): array => [
                'item' => $items->firstWhere('user_id', $user->id) ?? $items->first(),
                'owners' => $items->map(fn (AttentionItem $item): string => $item->user->name)->unique()->sort()->values()->all(),
            ])
            ->sortBy([
                fn (array $a, array $b): int => $a['item']->severityRank() <=> $b['item']->severityRank(),
                fn (array $a, array $b): int => $a['item']->opened_at <=> $b['item']->opened_at,
            ])
            ->values();
    }

    public function mark(int $itemId, string $feedback): void
    {
        abort_unless(in_array($feedback, [AttentionItem::USEFUL, AttentionItem::NOT_USEFUL], true), 404);

        $this->own($itemId)->forceFill(['feedback' => $feedback, 'feedback_at' => now()])->save();

        unset($this->rows);
        Flux::toast(variant: 'success', text: __('Thanks, noted.'));
    }

    public function openDismiss(int $itemId): void
    {
        $this->dismissingId = $this->own($itemId)->id;
        $this->dismissReason = '';
        $this->resetValidation();

        Flux::modal('attention-dismiss')->show();
    }

    public function dismiss(): void
    {
        $this->resetValidation();
        $reason = trim($this->dismissReason);

        if (mb_strlen($reason) > AttentionItem::REASON_LENGTH) {
            $this->addError('dismissReason', __('Keep this to :max characters or fewer.', ['max' => AttentionItem::REASON_LENGTH]));

            return;
        }

        $this->own((int) $this->dismissingId)->forceFill(['feedback' => AttentionItem::DISMISSED, 'feedback_reason' => $reason === '' ? null : $reason, 'feedback_at' => now()])->save();

        unset($this->rows);
        Flux::modal('attention-dismiss')->close();
        Flux::toast(variant: 'success', text: __('Dismissed. It comes back if it clears and happens again.'));
    }

    private function own(int $itemId): AttentionItem
    {
        return AttentionItem::query()->where('user_id', Auth::id())->whereNull('closed_at')->findOrFail($itemId);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="attention-{{ $scope }}" id="{{ $scope === 'mine' ? 'needs-attention' : 'needs-attention-'.$scope }}">
    @php
        $colors = [AttentionItem::URGENT => 'rose', AttentionItem::ATTENTION => 'amber', AttentionItem::FYI => 'zinc'];
    @endphp

    <div>
        <flux:heading size="lg" level="2">
            {{ match ($scope) { 'mine' => __('Needs your attention'), 'locations' => __('Needs attention at your Locations'), default => __('Needs attention, company-wide') } }}
        </flux:heading>
        <flux:subheading>{{ __('Found by Backoffice from your records every 15 minutes. Each one closes by itself once it is fixed.') }}</flux:subheading>
    </div>

    @if ($this->rows->isEmpty())
        <flux:text class="text-sm" data-test="attention-none">{{ __('Nothing needs attention.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($this->rows as $row)
                @php
                    $item = $row['item'];
                @endphp
                <li wire:key="attention-{{ $scope }}-{{ $item->id }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-3" data-test="attention-item">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$colors[$item->severity] ?? 'zinc'">{{ __($item->severityLabel()) }}</flux:badge>
                            <span class="text-xs text-zinc-500">{{ __(Rules::NAMES[$item->rule] ?? $item->rule) }}@if ($item->location !== null) &middot; {{ $item->location->name }}@endif &middot; {{ __('since') }} <x-local-time :at="$item->opened_at" /></span>
                        </span>
                        <a href="{{ $item->url }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $item->title }}</a>
                        @if ($scope !== 'mine')
                            <span class="text-sm text-zinc-500">{{ __('For :names', ['names' => implode(', ', $row['owners'])]) }}</span>
                        @endif
                    </div>

                    @if ($scope === 'mine')
                        <div class="-ms-2 flex flex-wrap items-center gap-1 sm:ms-0 sm:shrink-0">
                            @if ($item->feedback === null)
                                <flux:button size="xs" variant="ghost" icon="hand-thumb-up" wire:click="mark({{ $item->id }}, 'useful')" data-test="attention-useful">{{ __('Useful') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="hand-thumb-down" wire:click="mark({{ $item->id }}, 'not_useful')" data-test="attention-not-useful">{{ __('Not useful') }}</flux:button>
                            @else
                                <span class="px-2 text-xs text-zinc-500">{{ __(AttentionItem::FEEDBACK[$item->feedback] ?? '') }}</span>
                            @endif
                            <flux:button size="xs" variant="ghost" wire:click="openDismiss({{ $item->id }})" data-test="attention-dismiss">{{ __('Dismiss') }}</flux:button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($scope === 'mine')
        <flux:modal name="attention-dismiss" class="w-full max-w-md">
            <form wire:submit="dismiss" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Dismiss this?') }}</flux:heading>
                    <flux:subheading>{{ __('It leaves your list until it clears and happens again. Saying why helps tune the rules.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="dismissReason" rows="2" :label="__('Why (optional)')" data-test="attention-dismiss-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-attention-dismiss">{{ __('Dismiss') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
