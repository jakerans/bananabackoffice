<?php

use App\Enums\LeadStatus;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The prompt under the header (decision 126): the signed-in person's own
 * appointments that are over and that nobody has yet marked run or no-show.
 *
 * It is on every page until they are answered, because the habit wanted is to
 * close an appointment out straight after it. It names no customer; the link
 * opens the oldest one's Lead, which is the one to answer first.
 */
new class extends Component {
    /**
     * @return Builder<Appointment>
     */
    private function waiting(): Builder
    {
        return Appointment::query()
            ->overdueForCloseOutAt(now())
            ->where('salesperson_id', Auth::id())
            ->whereHas('lead', fn (Builder $leads) => $leads->where('status', LeadStatus::AppointmentBooked));
    }

    #[Computed]
    public function count(): int
    {
        return $this->waiting()->count();
    }

    #[Computed]
    public function oldestLeadId(): ?int
    {
        return $this->waiting()->orderBy('starts_at')->orderBy('id')->value('lead_id');
    }

    /**
     * An appointment on this page was just answered or changed: count again.
     */
    #[On('lead-appointment-changed')]
    public function appointmentChanged(): void
    {
        unset($this->count, $this->oldestLeadId);
    }
}; ?>

<div>
    @if ($this->count > 0)
        <div role="status" class="border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-ink lg:px-8" data-test="close-out-prompt">
            <span class="font-semibold">
                {{ trans_choice('{1} You have 1 appointment to close out.|[2,*] You have :count appointments to close out.', $this->count, ['count' => $this->count]) }}
            </span>
            {{ __('Say whether it was run or was a no-show before anything else.') }}
            <a href="{{ route('leads.show', $this->oldestLeadId) }}" wire:navigate class="font-semibold underline" data-test="close-out-prompt-link">{{ __('Close out now') }}</a>
        </div>
    @endif
</div>
