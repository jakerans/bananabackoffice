<?php

use App\Actions\Agreements\ImportPaintScoutAgreement;
use App\Actions\Pricing\ImportPaintScoutSettings;
use App\Enums\Permission;
use App\Models\AgreementWording;
use App\Models\PriceBook;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/*
 * Import from PaintScout, on Settings > Pricing Settings
 * (docs/slice-37-location-setup-plan.md, step 37a; decision 220): the
 * saved PaintScout settings file is uploaded here instead of being put on the
 * server, and fills the default price book, the agreement's wording, or both,
 * through the same actions as backoffice:pricing-import and
 * backoffice:agreement-import. The file is read and not kept. Only those who
 * may change prices see it. Prices are imported once here; replacing them
 * stays a command.
 */
new class extends Component {
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public bool $prices = true;

    public bool $wording = true;

    /** The name of the terms in PaintScout. */
    public string $label = '2026 CONTRACT';

    public function mount(): void
    {
        $this->authorize(Permission::ManagePricing);

        $this->prices = ! $this->hasPrices;
        $this->wording = $this->currentWording === null;
    }

    #[Computed]
    public function hasPrices(): bool
    {
        return PriceBook::companyDefault()?->rates()->exists() ?? false;
    }

    #[Computed]
    public function currentWording(): ?AgreementWording
    {
        return AgreementWording::current();
    }

    public function import(ImportPaintScoutSettings $importSettings, ImportPaintScoutAgreement $importAgreement): void
    {
        $this->authorize(Permission::ManagePricing);

        $this->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:json'],
            'prices' => ['boolean'],
            'wording' => ['boolean'],
            'label' => ['required_if:wording,true', 'string', 'max:120'],
        ], [
            'file.required' => __('Choose the saved PaintScout settings file.'),
            'file.extensions' => __('Choose the saved PaintScout settings file: it ends in .json.'),
        ]);

        $importsPrices = $this->prices && ! $this->hasPrices;

        if (! $importsPrices && ! $this->wording) {
            $this->addError('file', __('Choose what to import from it.'));

            return;
        }

        try {
            $settings = json_decode((string) file_get_contents($this->file->getRealPath()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->addError('file', __('That file is not a saved PaintScout settings file: it is not JSON.'));
            $this->forget();

            return;
        }

        $settings = is_array($settings) ? $settings : [];

        /** @var User $user */
        $user = Auth::user();
        $done = [];

        try {
            if ($importsPrices) {
                $imported = $importSettings->handle($settings, $user);
                $done[] = trans_choice('{1} prices: 1 production rate|[2,*] prices: :count production rates', $imported['rates'], ['count' => $imported['rates']]);
            }

            if ($this->wording) {
                $version = $importAgreement->handle($settings, trim($this->label));
                $done[] = __('the agreement\'s wording, version :version', ['version' => $version->version]);
            }
        } catch (RuntimeException $refused) {
            $this->addError('file', $done === [] ? $refused->getMessage() : __('Imported :done. Then: :refused', ['done' => implode(' and ', $done), 'refused' => $refused->getMessage()]));
            $this->forget();

            return;
        }

        $this->forget();
        unset($this->hasPrices, $this->currentWording);
        $this->prices = false;
        $this->wording = false;

        Flux::toast(variant: 'success', text: __('Imported from PaintScout: :done.', ['done' => implode(' and ', $done)]));
        $this->dispatch('paintscout-imported');
    }

    /**
     * The file is read once and not kept.
     */
    private function forget(): void
    {
        $this->file?->delete();
        $this->file = null;
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="paintscout-import">
    <div>
        <flux:heading size="lg" level="2">{{ __('Import from PaintScout') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Upload the settings file saved from PaintScout to fill the default price book and the agreement\'s wording. The file is read and not kept.') }}</flux:text>
    </div>

    <div class="flex flex-col gap-1 text-sm text-zinc-700" data-test="paintscout-status">
        <span>{{ $this->hasPrices ? __('Default price book: has its prices.') : __('Default price book: no prices yet.') }}</span>
        <span>{{ $this->currentWording === null ? __('Agreement wording: none yet.') : __('Agreement wording: version :version.', ['version' => $this->currentWording->version]) }}</span>
    </div>

    <form wire:submit="import" class="flex flex-col gap-4">
        <flux:input type="file" wire:model="file" accept=".json,application/json" :label="__('PaintScout settings file')" data-test="paintscout-file" />

        <div class="flex flex-col gap-2">
            @if ($this->hasPrices)
                <flux:text class="text-sm" data-test="paintscout-prices-done">{{ __('Prices are imported once. To replace them, ask for the command on the server.') }}</flux:text>
            @else
                <flux:checkbox wire:model="prices" :label="__('The prices, into the default price book')" data-test="paintscout-prices" />
            @endif
            <flux:checkbox wire:model.live="wording" :label="$this->currentWording === null ? __('The agreement\'s wording') : __('The agreement\'s wording, as a new version')" data-test="paintscout-wording" />
            @if ($wording)
                <flux:input wire:model="label" :label="__('Name of the terms in PaintScout')" class="max-w-xs" data-test="paintscout-label" />
            @endif
        </div>

        <div>
            <flux:button type="submit" variant="primary" icon="arrow-up-tray" data-test="paintscout-import-submit">{{ __('Import') }}</flux:button>
        </div>
    </form>
</div>
