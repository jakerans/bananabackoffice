<?php

use App\Actions\Agreements\EmailAgreementCopy;
use App\Enums\AgreementEmailOutcome;
use App\Models\Agreement;
use App\Models\Lead;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
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
 *
 * Whether the customer was emailed their copy, and "Email again" for whoever
 * may sign for the Lead (slice 36, step 36a; decision 214).
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
        return Agreement::query()->with(['wording', 'proposal.project', 'lead', 'latestEmail'])->where('lead_id', $this->leadId)->findOrFail($this->agreementId);
    }

    public function confirmEmailAgain(): void
    {
        $this->resetValidation();
        Flux::modal('email-again-confirmation')->show();
    }

    public function emailAgain(EmailAgreementCopy $emailAgreementCopy): void
    {
        $email = $emailAgreementCopy->again($this->agreement, Auth::user());

        unset($this->agreement);
        Flux::modal('email-again-confirmation')->close();

        if ($email->wasSent()) {
            Flux::toast(variant: 'success', text: __('Emailed to :address.', ['address' => $email->to_address]));
        } else {
            Flux::toast(variant: 'warning', text: __('Not sent: :reason.', ['reason' => $email->outcome->notSentReason()]));
        }
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

        @php($email = $agreement->latestEmail)
        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-card p-5 sm:flex-row sm:items-center sm:justify-between print:hidden" data-test="agreement-copy">
            <div class="min-w-0">
                <flux:heading level="2">{{ __('Customer\'s copy') }}</flux:heading>
                <flux:text class="mt-1 break-words" data-test="agreement-copy-status">
                    @if ($email === null)
                        {{ __('Not emailed yet.') }}
                    @elseif ($email->wasSent())
                        {{ __('Emailed to :address on :when.', ['address' => $email->to_address, 'when' => $email->created_at->copy()->timezone(config('app.display_timezone'))->format('M j, Y \a\t g:i A')]) }}
                    @else
                        {{ __('Not sent on :when: :reason.', ['when' => $email->created_at->copy()->timezone(config('app.display_timezone'))->format('M j, Y \a\t g:i A'), 'reason' => $email->outcome->notSentReason()]) }}
                    @endif
                </flux:text>
            </div>
            @if (! $agreement->isVoid())
                @can('manageProposals', $agreement->lead)
                    <div class="flex shrink-0 flex-col gap-1 sm:items-end">
                        <flux:button size="sm" icon="envelope" wire:click="confirmEmailAgain" :disabled="$agreement->lead->inquiry_email === null" data-test="email-again">
                            {{ $email?->wasSent() ? __('Email again') : __('Email a copy') }}
                        </flux:button>
                        @if ($agreement->lead->inquiry_email === null)
                            <flux:text class="text-sm">{{ __('Add the customer\'s email address on the lead first.') }}</flux:text>
                        @endif
                    </div>
                @endcan
            @endif
        </section>

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

    @if (! $agreement->isVoid() && $agreement->lead->inquiry_email !== null)
        @can('manageProposals', $agreement->lead)
            <x-confirmation-modal
                name="email-again-confirmation"
                :title="__('Email the customer a copy?')"
                :description="__('It goes to :address, the email address on the lead.', ['address' => $agreement->lead->inquiry_email])"
                action="emailAgain"
                :confirm-label="__('Send the copy')"
                confirm-variant="primary"
                error="email"
            />
        @endcan
    @endif
</div>
