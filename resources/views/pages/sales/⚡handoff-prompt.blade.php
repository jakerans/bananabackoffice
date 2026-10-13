<?php

use App\Enums\Permission;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The prompt under the header (decision 132): the jobs the signed-in person
 * sold whose handoff questions are not answered yet. Production cannot take
 * one until they are.
 *
 * It is on every page until each is done. It names no customer; the link
 * opens the handoff of the oldest one.
 */
new class extends Component {
    /**
     * @return Builder<Project>
     */
    private function waiting(): Builder
    {
        return Project::query()
            ->standing()
            ->whereDoesntHave('handoff')
            ->where('salesperson_id', Auth::id())
            // Only where they may still open it: Location stays the boundary.
            ->whereIn('location_id', Auth::user()->locationsWhere(Permission::ViewProjects)->modelKeys());
    }

    #[Computed]
    public function count(): int
    {
        return $this->waiting()->count();
    }

    /**
     * The one to answer first: the one sold longest ago.
     */
    #[Computed]
    public function oldest(): ?Project
    {
        return $this->waiting()->orderBy('sold_at')->orderBy('id')->first();
    }
}; ?>

<div>
    @if ($this->count > 0 && $this->oldest !== null)
        <div role="status" class="border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-ink lg:px-8" data-test="handoff-prompt">
            <span class="font-semibold">
                {{ trans_choice('{1} You have 1 sold job waiting for your handoff.|[2,*] You have :count sold jobs waiting for your handoff.', $this->count, ['count' => $this->count]) }}
            </span>
            {{ __('Production cannot take it until you answer the handoff questions.') }}
            <a href="{{ route('projects.handoff', $this->oldest) }}" wire:navigate class="font-semibold underline" data-test="handoff-prompt-link">{{ __('Complete it now') }}</a>
        </div>
    @endif
</div>
