<?php

use App\Actions\Agreements\SignAgreement;
use App\Models\AgreementWording;
use App\Models\Appointment;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Models\User;
use App\Support\Agreements\AgreementDetails;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The customer signs the agreement for the option they chose
 * (docs/slice-33-agreement-plan.md, step 33b; decisions 210 and 211), on the
 * salesperson's tablet, from "Accept this option" in the presentation: what
 * they are buying, the price and the deposit due, the wording with its
 * sections, then their name, a tick and their signature.
 *
 * Only at the Lead's appointment going on that day, by its salesperson or a
 * manager. Signing sells the estimate for that option; SignAgreement checks
 * all of it again as it saves.
 */
new #[Title('Customer agreement')] #[Layout('layouts::presentation')] class extends Component {
    #[Locked]
    public int $leadId;

    #[Locked]
    public int $estimateId;

    #[Locked]
    public int $optionId;

    /** The Lead's lifecycle revision when the page was opened, so it refuses a Lead that has changed since. */
    #[Locked]
    public int $knownLifecycleVersion = 0;

    public string $signerName = '';

    public bool $agreed = false;

    public function mount(Lead $lead, Estimate $estimate, EstimateOption $option): void
    {
        $this->leadId = $lead->id;
        $this->estimateId = $estimate->id;
        $this->optionId = $option->id;
        $this->knownLifecycleVersion = (int) $lead->lifecycle_version;
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with(['location', 'property'])->findOrFail($this->leadId);
    }

    #[Computed]
    public function estimate(): Estimate
    {
        return Estimate::query()->with(['discounts', 'proposal.property'])->where('lead_id', $this->leadId)->findOrFail($this->estimateId);
    }

    #[Computed]
    public function option(): EstimateOption
    {
        return EstimateOption::query()->where('estimate_id', $this->estimateId)->findOrFail($this->optionId);
    }

    #[Computed]
    public function wording(): ?AgreementWording
    {
        return AgreementWording::current();
    }

    #[Computed]
    public function appointment(): ?Appointment
    {
        return SignAgreement::appointmentFor($this->lead, $this->user());
    }

    /**
     * Why the customer cannot sign here now, if they cannot.
     */
    #[Computed]
    public function unavailable(): ?string
    {
        return match (true) {
            $this->estimate->isSettled() => __('This estimate is already :result.', ['result' => mb_strtolower($this->estimate->proposal->result->label())]),
            $this->wording === null => __('There is no agreement wording yet. The Owner adds it in Pricing Settings.'),
            $this->appointment === null => __('The customer signs during the appointment, on its day. Otherwise mark the sale on the Lead\'s Estimates card.'),
            $this->option->total_cents <= 0 => __('This option has no price yet.'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function details(): array
    {
        $property = $this->estimate->proposal?->property?->address ?? $this->lead->property?->address;

        return app(AgreementDetails::class)->for($this->lead, $this->estimate, $this->option, $property);
    }

    /**
     * @param  mixed  $signature  The lines drawn in the box.
     */
    public function sign(mixed $signature, SignAgreement $signAgreement): void
    {
        $this->resetValidation();

        $agreement = $signAgreement->handle(
            $this->lead,
            $this->estimate,
            $this->option,
            $this->signerName,
            $this->agreed,
            $signature,
            $this->user(),
            request()->ip(),
            request()->userAgent(),
            $this->knownLifecycleVersion,
        );

        Flux::toast(variant: 'success', text: __('Signed. The estimate is sold for :option, and the Project is made.', ['option' => $this->option->name]));

        $this->redirectRoute('leads.agreement', [$this->leadId, $agreement->id], navigate: true);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

<div class="flex flex-col" data-test="agreement-signing">
    <main class="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-8">
        <header class="flex flex-col items-start gap-3">
            <flux:button
                :href="route('leads.estimate.present', [$leadId, $estimateId])"
                wire:navigate
                variant="ghost"
                size="sm"
                icon="arrow-left"
                class="-ms-3"
                data-test="back-to-presentation"
            >{{ __('Back to the options') }}</flux:button>
            <flux:heading size="xl" level="1">{{ __('Customer agreement') }}</flux:heading>
        </header>

        @if ($this->unavailable !== null)
            <flux:callout icon="information-circle" color="amber" data-test="sign-unavailable">
                <flux:callout.text>{{ $this->unavailable }}</flux:callout.text>
            </flux:callout>
        @endif

        <x-agreement.summary :details="$this->details" />

        @if ($this->wording !== null)
            <x-agreement.wording :wording="$this->wording" class="rounded-2xl border border-zinc-200 bg-card p-6 sm:p-8" />
        @endif

        @if ($this->unavailable === null)
            <section
                class="flex flex-col gap-5 rounded-2xl border border-zinc-200 bg-card p-6 sm:p-8"
                data-test="signing"
                x-data="{
                    lines: [],
                    drawing: false,
                    watcher: null,
                    init() {
                        this.size();
                        this.watcher = new ResizeObserver(() => this.size());
                        this.watcher.observe(this.$refs.pad);
                    },
                    destroy() {
                        this.watcher?.disconnect();
                    },
                    size() {
                        const pad = this.$refs.pad;
                        if (! pad) return;
                        const box = pad.getBoundingClientRect();
                        const ratio = window.devicePixelRatio || 1;
                        pad.width = Math.round(box.width * ratio);
                        pad.height = Math.round(box.height * ratio);
                        this.draw();
                    },
                    at(event) {
                        const box = this.$refs.pad.getBoundingClientRect();
                        const clamp = (value) => Math.min(1, Math.max(0, value));

                        return [clamp((event.clientX - box.left) / box.width), clamp((event.clientY - box.top) / box.height)];
                    },
                    start(event) {
                        this.$refs.pad.setPointerCapture(event.pointerId);
                        this.drawing = true;
                        this.lines.push([this.at(event)]);
                        this.draw();
                    },
                    move(event) {
                        if (! this.drawing) return;
                        this.lines[this.lines.length - 1].push(this.at(event));
                        this.draw();
                    },
                    stop() {
                        this.drawing = false;
                    },
                    clear() {
                        this.lines = [];
                        this.draw();
                    },
                    draw() {
                        const pad = this.$refs.pad;
                        if (! pad) return;
                        const context = pad.getContext('2d');
                        context.clearRect(0, 0, pad.width, pad.height);
                        context.lineWidth = 3 * (window.devicePixelRatio || 1);
                        context.lineCap = 'round';
                        context.lineJoin = 'round';
                        context.strokeStyle = getComputedStyle(pad).color;

                        for (const line of this.lines) {
                            context.beginPath();
                            line.forEach(([across, down], index) => context[index === 0 ? 'moveTo' : 'lineTo'](across * pad.width, down * pad.height));
                            if (line.length === 1) context.lineTo(line[0][0] * pad.width + 0.5, line[0][1] * pad.height);
                            context.stroke();
                        }
                    },
                }"
            >
                <flux:heading size="lg" level="2">{{ __('Sign to accept') }}</flux:heading>

                <flux:input wire:model="signerName" :label="__('Your full name')" autocomplete="off" class="max-w-md" data-test="signer-name" />

                <flux:checkbox wire:model="agreed" :label="__('I have read and agree to the :title, and accept this option at this price.', ['title' => $this->wording->title])" data-test="signer-agrees" />

                <div class="flex flex-col gap-2">
                    <div class="flex items-end justify-between gap-3">
                        <flux:label>{{ __('Sign here') }}</flux:label>
                        <flux:button size="sm" variant="ghost" x-on:click="clear()" data-test="clear-signature">{{ __('Clear') }}</flux:button>
                    </div>
                    <canvas
                        x-ref="pad"
                        x-on:pointerdown.prevent="start($event)"
                        x-on:pointermove.prevent="move($event)"
                        x-on:pointerup="stop()"
                        x-on:pointercancel="stop()"
                        class="block w-full touch-none rounded-xl border-2 border-dashed border-zinc-300 bg-white text-ink"
                        style="aspect-ratio: {{ \App\Models\Agreement::SIGNATURE_WIDTH }} / {{ \App\Models\Agreement::SIGNATURE_HEIGHT }}"
                        role="img"
                        aria-label="{{ __('Signature box: sign with a finger or a stylus') }}"
                        data-test="signature-pad"
                    ></canvas>
                </div>

                {{-- The name and the tick show their own; everything else signing refused is listed here. --}}
                @php($refusals = collect($errors->getMessages())->except(['signerName', 'agreed'])->flatten())
                @if ($refusals->isNotEmpty())
                    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-ink" data-test="sign-refused">
                        <ul class="flex flex-col gap-1">
                            @foreach ($refusals as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="primary" x-on:click="$wire.sign(JSON.parse(JSON.stringify(lines)))" data-test="sign-agreement">
                        {{ __('Sign and accept :amount', ['amount' => \App\Support\Money::format($this->details['total_cents'])]) }}
                    </flux:button>
                </div>
            </section>
        @endif
    </main>
</div>
