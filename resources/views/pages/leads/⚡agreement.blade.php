<?php

use App\Models\Agreement;
use App\Models\Lead;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A signed agreement, as the customer signed it
 * (docs/slice-33-agreement-plan.md, step 33b; decisions 210 and 211): what
 * they bought, the price and deposit due, the wording of the version they
 * signed, their name and signature, when, and who presented it. A page that
 * prints. Void ones say so, with the reason.
 *
 * Whoever may see the Lead, or the Project made from the sale, may read it.
 */
new #[Title('Signed agreement')] #[Layout('layouts::presentation')] class extends Component {
    #[Locked]
    public int $leadId;

    #[Locked]
    public int $agreementId;

    public function mount(Lead $lead, Agreement $agreement): void
    {
        $project = $agreement->proposal->project;

        abort_unless(Gate::allows('view', $lead) || ($project !== null && Gate::allows('view', $project)), 403);

        $this->leadId = $lead->id;
        $this->agreementId = $agreement->id;
    }

    #[Computed]
    public function agreement(): Agreement
    {
        return Agreement::query()->with(['wording', 'proposal.project'])->where('lead_id', $this->leadId)->findOrFail($this->agreementId);
    }
}; ?>

<div class="flex flex-col" data-test="signed-agreement">
    @php($agreement = $this->agreement)

    <main class="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-8">
        <header class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                @can('view', $agreement->lead)
                    <flux:button :href="route('leads.show', $leadId)" wire:navigate variant="ghost" size="sm" icon="arrow-left" class="-ms-3" data-test="back-to-lead">{{ __('Back to the lead') }}</flux:button>
                @elseif ($agreement->proposal->project !== null)
                    <flux:button :href="route('projects.show', $agreement->proposal->project)" wire:navigate variant="ghost" size="sm" icon="arrow-left" class="-ms-3">{{ __('Back to the project') }}</flux:button>
                @endcan
                <flux:button size="sm" icon="printer" x-on:click="window.print()" data-test="print-agreement">{{ __('Print') }}</flux:button>
            </div>
            <flux:heading size="xl" level="1">{{ $agreement->wording->title }}</flux:heading>
            <flux:text class="text-base" data-test="agreement-signed-line">
                {{ __('Signed by :name on :when. Presented by :salesperson.', ['name' => $agreement->signer_name, 'when' => $agreement->signed_at->copy()->timezone(config('app.display_timezone'))->format('M j, Y \a\t g:i A T'), 'salesperson' => $agreement->signed_by_name]) }}
            </flux:text>
        </header>

        @if ($agreement->isVoid())
            <flux:callout icon="x-circle" color="red" data-test="agreement-void">
                <flux:callout.heading>{{ __('Void') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __(':name corrected the sale on :when: ":reason". This agreement no longer applies; it is kept as it was signed.', ['name' => $agreement->voided_by_name, 'when' => $agreement->voided_at->copy()->timezone(config('app.display_timezone'))->format('M j, Y'), 'reason' => $agreement->void_reason]) }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <x-agreement.summary :details="$agreement->details" />

        <x-agreement.wording :wording="$agreement->wording" :toc="false" class="rounded-2xl border border-zinc-200 bg-card p-6 sm:p-8" />

        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-card p-6 sm:p-8" data-test="agreement-signature">
            <flux:heading size="lg" level="2">{{ __('Accepted and signed') }}</flux:heading>
            <svg viewBox="0 0 {{ Agreement::SIGNATURE_WIDTH }} {{ Agreement::SIGNATURE_HEIGHT }}" class="w-full max-w-xl border-b-2 border-zinc-300 text-ink" role="img" aria-label="{{ __('Signature of :name', ['name' => $agreement->signer_name]) }}">
                <path d="{{ $agreement->signaturePath() }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <flux:text class="text-base text-ink">{{ $agreement->signer_name }}</flux:text>
            <flux:text class="text-sm">
                {{ __('Signed on :when, version :version of the wording.', ['when' => $agreement->signed_at->copy()->timezone(config('app.display_timezone'))->format('M j, Y \a\t g:i A T'), 'version' => $agreement->wording->version]) }}
            </flux:text>
        </section>
    </main>
</div>
