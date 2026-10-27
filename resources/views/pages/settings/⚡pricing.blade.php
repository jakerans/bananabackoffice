<?php

use App\Actions\Pricing\ChangeHourlyRates;
use App\Actions\Pricing\CustomizePriceBook;
use App\Actions\Pricing\SavePaintProduct;
use App\Actions\Pricing\SaveProductionRate;
use App\Actions\Pricing\SaveRateCategory;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\User;
use App\Support\Money;
use App\Support\Pricing\Decimal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Settings > Pricing Settings (docs/slice-31-estimating-plan.md, step 31a;
 * decisions 196 and 197): the default price book and each Location's,
 * production rates and everything else estimating charges for. Owner and
 * Leadership change them; Location Managers and salespeople see them.
 */
new #[Title('Pricing Settings')] class extends Component {
    /** The book shown: "default", or a Location's id. */
    #[Url(except: 'default')]
    public string $book = 'default';

    #[Url(except: 'rates')]
    public string $tab = 'rates';

    public ?int $rateId = null;

    /** @var array<string, mixed> */
    public array $rateForm = [];

    public ?int $categoryId = null;

    /** @var array<string, mixed> */
    public array $categoryForm = [];

    public ?int $paintId = null;

    /** @var array<string, mixed> */
    public array $paintForm = [];

    public string $hourlyRate = '';

    /** @var array<string, string> */
    public array $typeRates = [];

    public string $taxPercent = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        abort_unless(Gate::any([Permission::ViewPricing, Permission::ManagePricing]), 403);

        if (! in_array($this->tab, ['rates', 'paints', 'areas', 'line-items', 'hourly'], true)) {
            $this->tab = 'rates';
        }

        $this->fillHourlyForm();
    }

    public function updatedBook(): void
    {
        $this->fillHourlyForm();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows(Permission::ManagePricing);
    }

    /**
     * The Locations whose prices this person may see.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        if ($this->canManage) {
            return Location::query()->orderBy('name')->get();
        }

        /** @var User $user */
        $user = Auth::user();

        return $user->locationsWhere(Permission::ViewPricing);
    }

    #[Computed]
    public function location(): ?Location
    {
        return ctype_digit($this->book) ? $this->locations->firstWhere('id', (int) $this->book) : null;
    }

    /**
     * The book shown: the Location's own, the default it uses until customized, or the default itself.
     */
    #[Computed]
    public function priceBook(): ?PriceBook
    {
        return $this->location === null ? PriceBook::companyDefault() : PriceBook::forLocation($this->location);
    }

    /**
     * Whether the Location shown estimates with the default, so its prices are not its own to change here.
     */
    #[Computed]
    public function usesDefault(): bool
    {
        return $this->location !== null && $this->priceBook !== null && $this->priceBook->isDefault();
    }

    #[Computed]
    public function editable(): bool
    {
        return $this->canManage && $this->priceBook !== null && ! $this->usesDefault;
    }

    /**
     * @return Collection<int, RateCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->priceBook?->categories()->with(['rates.paint'])->get() ?? new Collection;
    }

    /**
     * Rates that came from PaintScout without a category, kept switched off.
     *
     * @return Collection<int, ProductionRate>
     */
    #[Computed]
    public function uncategorized(): Collection
    {
        return $this->priceBook?->rates()->whereNull('rate_category_id')->with('paint')->get() ?? new Collection;
    }

    /**
     * @return Collection<int, PaintProduct>
     */
    #[Computed]
    public function paints(): Collection
    {
        return $this->priceBook?->paints()->get() ?? new Collection;
    }

    public function customize(CustomizePriceBook $customizePriceBook): void
    {
        abort_if($this->location === null, 404);

        try {
            $customizePriceBook->handle($this->location, $this->user());
        } catch (ValidationException $refused) {
            Flux::modal('customize-price-book')->close();

            throw $refused;
        }

        $this->forget();
        Flux::modal('customize-price-book')->close();
        Flux::toast(variant: 'success', text: __(':location now has its own price book, copied from the default.', ['location' => $this->location->name]));
    }

    public function newRate(?int $categoryId = null): void
    {
        $this->authorizeEditing();

        $this->rateId = null;
        $this->rateForm = [
            'name' => '', 'customer_label' => '', 'rate_category_id' => (string) ($categoryId ?? $this->categories->first()?->id ?? ''),
            'measure' => RateMeasure::WallArea->value, 'calculation' => RateCalculation::UnitsPerHour->value,
            'value_1' => '', 'value_2' => '', 'value_3' => '', 'value_4' => '',
            'default_coats' => '2', 'uses_coats' => true, 'prep_hours' => '', 'hourly_rate' => '', 'paint_product_id' => '',
            'estimate_types' => [EstimateType::ResidentialInterior->value, EstimateType::ResidentialExterior->value],
            'excluded_from_discount' => false, 'excluded_from_tax' => false, 'is_active' => true,
        ];
        $this->resetValidation();

        Flux::modal('production-rate')->show();
    }

    public function editRate(int $rateId): void
    {
        $this->authorizeEditing();

        $rate = $this->priceBook->rates()->findOrFail($rateId);

        $this->rateId = $rate->id;
        $this->rateForm = [
            'name' => $rate->name, 'customer_label' => (string) $rate->customer_label, 'rate_category_id' => (string) ($rate->rate_category_id ?? ''),
            'measure' => $rate->measure->value, 'calculation' => $rate->calculation->value,
            'value_1' => (string) Decimal::plain($rate->value_1), 'value_2' => (string) Decimal::plain($rate->value_2),
            'value_3' => (string) Decimal::plain($rate->value_3), 'value_4' => (string) Decimal::plain($rate->value_4),
            'default_coats' => (string) $rate->default_coats, 'uses_coats' => $rate->uses_coats, 'prep_hours' => (string) Decimal::plain($rate->prep_hours),
            'hourly_rate' => $rate->hourly_rate_cents === null ? '' : number_format($rate->hourly_rate_cents / 100, 2, '.', ''),
            'paint_product_id' => (string) ($rate->paint_product_id ?? ''), 'estimate_types' => $rate->estimate_types,
            'excluded_from_discount' => $rate->excluded_from_discount, 'excluded_from_tax' => $rate->excluded_from_tax, 'is_active' => $rate->is_active,
        ];
        $this->resetValidation();

        Flux::modal('production-rate')->show();
    }

    public function saveRate(SaveProductionRate $saveProductionRate): void
    {
        $this->authorizeEditing();

        $rate = $this->rateId === null ? null : $this->priceBook->rates()->findOrFail($this->rateId);

        $this->asForm('rateForm', fn () => $saveProductionRate->handle($this->priceBook, $rate, $this->rateForm, $this->user()));

        $this->forget();
        Flux::modal('production-rate')->close();
        Flux::toast(variant: 'success', text: $rate === null ? __('Rate added.') : __('Rate saved.'));
    }

    public function newCategory(): void
    {
        $this->authorizeEditing();

        $this->categoryId = null;
        $this->categoryForm = ['name' => '', 'estimate_types' => [EstimateType::ResidentialInterior->value], 'is_active' => true];
        $this->resetValidation();

        Flux::modal('rate-category')->show();
    }

    public function editCategory(int $categoryId): void
    {
        $this->authorizeEditing();

        $category = $this->priceBook->categories()->findOrFail($categoryId);

        $this->categoryId = $category->id;
        $this->categoryForm = ['name' => $category->name, 'estimate_types' => $category->estimate_types, 'is_active' => $category->is_active];
        $this->resetValidation();

        Flux::modal('rate-category')->show();
    }

    public function saveCategory(SaveRateCategory $saveRateCategory): void
    {
        $this->authorizeEditing();

        $category = $this->categoryId === null ? null : $this->priceBook->categories()->findOrFail($this->categoryId);

        $this->asForm('categoryForm', fn () => $saveRateCategory->handle($this->priceBook, $category, $this->categoryForm, $this->user()));

        $this->forget();
        Flux::modal('rate-category')->close();
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function newPaint(): void
    {
        $this->authorizeEditing();

        $this->paintId = null;
        $this->paintForm = ['name' => '', 'unit_label' => 'gal', 'price' => '', 'coverage_area' => '', 'coverage_length' => '', 'coverage_each' => '', 'estimate_types' => EstimateType::values(), 'is_active' => true];
        $this->resetValidation();

        Flux::modal('paint-product')->show();
    }

    public function editPaint(int $paintId): void
    {
        $this->authorizeEditing();

        $paint = $this->priceBook->paints()->findOrFail($paintId);

        $this->paintId = $paint->id;
        $this->paintForm = [
            'name' => $paint->name, 'unit_label' => $paint->unit_label, 'price' => number_format($paint->price_cents / 100, 2, '.', ''),
            'coverage_area' => (string) Decimal::plain($paint->coverage_area), 'coverage_length' => (string) Decimal::plain($paint->coverage_length),
            'coverage_each' => (string) Decimal::plain($paint->coverage_each), 'estimate_types' => $paint->estimate_types, 'is_active' => $paint->is_active,
        ];
        $this->resetValidation();

        Flux::modal('paint-product')->show();
    }

    public function savePaint(SavePaintProduct $savePaintProduct): void
    {
        $this->authorizeEditing();

        $paint = $this->paintId === null ? null : $this->priceBook->paints()->findOrFail($this->paintId);

        $this->asForm('paintForm', fn () => $savePaintProduct->handle($this->priceBook, $paint, $this->paintForm, $this->user()));

        $this->forget();
        Flux::modal('paint-product')->close();
        Flux::toast(variant: 'success', text: $paint === null ? __('Paint added.') : __('Paint saved.'));
    }

    public function saveHourlyRates(ChangeHourlyRates $changeHourlyRates): void
    {
        $this->authorizeEditing();

        $changeHourlyRates->handle($this->priceBook, $this->hourlyRate, $this->typeRates, $this->taxPercent, $this->user());

        $this->forget();
        $this->fillHourlyForm();
        Flux::toast(variant: 'success', text: __('Hourly rates saved.'));
    }

    private function fillHourlyForm(): void
    {
        $this->forget();
        $book = $this->priceBook;

        $this->hourlyRate = $book === null ? '' : number_format($book->hourly_rate_cents / 100, 2, '.', '');
        $this->typeRates = [];
        foreach (EstimateType::cases() as $type) {
            $own = $book?->estimate_type_hourly_rates[$type->value] ?? null;
            $this->typeRates[$type->value] = is_int($own) ? number_format($own / 100, 2, '.', '') : '';
        }
        $this->taxPercent = $book === null ? '0' : rtrim(rtrim(number_format($book->tax_rate_basis_points / 100, 2, '.', ''), '0'), '.');
    }

    /**
     * Run a save, showing its refusals beside the dialog's own fields.
     */
    private function asForm(string $form, Closure $save): void
    {
        $this->resetValidation();

        try {
            $save();
        } catch (ValidationException $refused) {
            $messages = [];
            foreach ($refused->errors() as $field => $errors) {
                $messages[$form.'.'.$field] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    private function authorizeEditing(): void
    {
        abort_unless($this->editable, 403);
    }

    private function forget(): void
    {
        unset($this->location, $this->priceBook, $this->usesDefault, $this->editable, $this->categories, $this->uncategorized, $this->paints);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pricing Settings') }}</flux:heading>
            <flux:subheading>{{ __('Production rates, paints and hourly rates that estimates are priced with. Each Location starts from the default price book and can be customized from there.') }}</flux:subheading>
        </div>

        <flux:select wire:model.live="book" :label="__('Price book')" class="min-w-64" data-test="price-book-select">
            <flux:select.option value="default">{{ __('Default price book') }}</flux:select.option>
            @foreach ($this->locations as $choice)
                <flux:select.option :value="(string) $choice->id">{{ $choice->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @error('book')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="price-book-refused">{{ $message }}</div>
    @enderror

    @if ($this->priceBook === null)
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center" data-test="no-price-book">
            <flux:heading size="lg">{{ __('No price book yet') }}</flux:heading>
            <flux:text class="max-w-md">{{ __('The default price book is filled once, from Banana\'s PaintScout settings, on the server.') }}</flux:text>
        </div>
    @else
        @if ($this->usesDefault)
            <flux:callout icon="information-circle" data-test="uses-default">
                <flux:callout.heading>{{ __(':location uses the default price book', ['location' => $this->location->name]) }}</flux:callout.heading>
                <flux:callout.text>{{ __('Its estimates are priced with the default. Customize it to give it prices of its own; changes to the default then no longer reach it.') }}</flux:callout.text>
                @if ($this->canManage)
                    <x-slot name="actions">
                        <flux:modal.trigger name="customize-price-book">
                            <flux:button size="sm" data-test="customize-price-book">{{ __('Customize for this Location') }}</flux:button>
                        </flux:modal.trigger>
                    </x-slot>
                @endif
            </flux:callout>
        @elseif ($this->location !== null)
            <flux:text data-test="own-price-book">{{ __(':location has its own price book. Changes to the default do not reach it.', ['location' => $this->location->name]) }}</flux:text>
        @endif

        @unless ($this->canManage)
            <flux:text class="text-sm text-zinc-600" data-test="view-only">{{ __('You can see these prices. The Owner and Leadership change them.') }}</flux:text>
        @endunless

        <div class="overflow-x-auto">
        <flux:navbar class="-mb-2 border-b border-zinc-200" data-test="pricing-tabs">
            <flux:navbar.item wire:click="$set('tab', 'rates')" :current="$tab === 'rates'">{{ __('Production rates') }}</flux:navbar.item>
            <flux:navbar.item wire:click="$set('tab', 'paints')" :current="$tab === 'paints'">{{ __('Paints') }}</flux:navbar.item>
            <flux:navbar.item wire:click="$set('tab', 'areas')" :current="$tab === 'areas'">{{ __('Areas') }}</flux:navbar.item>
            <flux:navbar.item wire:click="$set('tab', 'line-items')" :current="$tab === 'line-items'">{{ __('Line items') }}</flux:navbar.item>
            <flux:navbar.item wire:click="$set('tab', 'hourly')" :current="$tab === 'hourly'">{{ __('Hourly rates and tax') }}</flux:navbar.item>
        </flux:navbar>
        </div>

        @if ($tab === 'rates')
            @if ($this->editable)
                <div class="flex justify-end">
                    <flux:button icon="plus" wire:click="newCategory" data-test="add-category">{{ __('Add category') }}</flux:button>
                </div>
            @endif

            @foreach ($this->categories as $category)
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" wire:key="category-{{ $category->id }}" data-test="rate-category-{{ $category->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg" level="2">{{ $category->name }}</flux:heading>
                            @foreach ($category->estimate_types as $type)
                                <flux:badge size="sm" color="zinc">{{ EstimateType::from($type)->label() }}</flux:badge>
                            @endforeach
                            @unless ($category->is_active)
                                <flux:badge size="sm" color="amber">{{ __('Switched off') }}</flux:badge>
                            @endunless
                        </div>
                        @if ($this->editable)
                            <div class="flex gap-2">
                                <flux:button size="sm" wire:click="editCategory({{ $category->id }})">{{ __('Edit category') }}</flux:button>
                                <flux:button size="sm" icon="plus" wire:click="newRate({{ $category->id }})" data-test="add-rate-{{ $category->id }}">{{ __('Add rate') }}</flux:button>
                            </div>
                        @endif
                    </div>

                    @include('partials.pricing.rates-table', ['rates' => $category->rates, 'editable' => $this->editable])
                </div>
            @endforeach

            @if ($this->uncategorized->isNotEmpty())
                <div class="flex flex-col gap-3 rounded-xl border border-dashed border-zinc-300 bg-card p-4" data-test="uncategorized-rates">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Not in a category') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('Rates PaintScout kept without a category. They are switched off; give one a category to use it.') }}</flux:text>
                    </div>
                    @include('partials.pricing.rates-table', ['rates' => $this->uncategorized, 'editable' => $this->editable])
                </div>
            @endif
        @elseif ($tab === 'paints')
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" data-test="paints">
                @if ($this->editable)
                    <div class="flex justify-end">
                        <flux:button size="sm" icon="plus" wire:click="newPaint" data-test="add-paint">{{ __('Add paint') }}</flux:button>
                    </div>
                @endif
                <div class="-mx-4 overflow-x-auto px-4">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Paint') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Covers') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Trim per unit') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Used per item') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        @if ($this->editable)
                            <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                        @endif
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->paints as $paint)
                            <flux:table.row :key="'paint-'.$paint->id" data-test="paint-{{ $paint->id }}">
                                <flux:table.cell variant="strong">{{ $paint->name }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($paint->price_cents) }} / {{ $paint->unit_label }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $paint->coverage_area === null ? '—' : Decimal::plain($paint->coverage_area).' sq ft' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $paint->coverage_length === null ? '—' : Decimal::plain($paint->coverage_length).' ft' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $paint->coverage_each === null ? '—' : Decimal::plain($paint->coverage_each).' '.$paint->unit_label }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($paint->is_active)
                                        <flux:badge size="sm" color="green">{{ __('Offered') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('Switched off') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                @if ($this->editable)
                                    <flux:table.cell align="end"><flux:button size="sm" wire:click="editPaint({{ $paint->id }})" data-test="edit-paint-{{ $paint->id }}">{{ __('Edit') }}</flux:button></flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                </div>
            </div>
        @elseif ($tab === 'areas')
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" data-test="area-templates">
                <flux:text class="text-sm">{{ __('The areas an estimate starts from. They are changed with the estimate itself, in a later step.') }}</flux:text>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Area') }}</flux:table.column>
                        <flux:table.column>{{ __('Inside or out') }}</flux:table.column>
                        <flux:table.column>{{ __('Estimate types') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->priceBook->areaTemplates as $area)
                            <flux:table.row :key="'area-'.$area->id">
                                <flux:table.cell variant="strong">{{ $area->name }}</flux:table.cell>
                                <flux:table.cell>{{ match ($area->side) { 'interior' => __('Inside'), 'exterior' => __('Outside'), default => __('Either') } }}</flux:table.cell>
                                <flux:table.cell class="text-sm">{{ implode(', ', array_map(fn (string $type): string => EstimateType::from($type)->label(), $area->estimate_types)) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @elseif ($tab === 'line-items')
            <div class="flex flex-col gap-3" data-test="line-items">
                <flux:text class="text-sm">{{ __('Sections and priced items an estimate can carry. They are changed with the estimate itself, in a later step.') }}</flux:text>
                @foreach ($this->priceBook->lineItems as $item)
                    <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-card p-4" wire:key="line-item-{{ $item->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <flux:heading level="3">{{ $item->name }}</flux:heading>
                            <span class="text-sm tabular-nums text-zinc-600">
                                @if ($item->price_cents !== null)
                                    {{ Money::format($item->price_cents) }}
                                @endif
                                @if ($item->hours !== null)
                                    · {{ __(':hours hours', ['hours' => Decimal::plain($item->hours)]) }}
                                @endif
                            </span>
                        </div>
                        @if ($item->plainDescription() !== '')
                            <flux:text class="whitespace-pre-line text-sm">{{ $item->plainDescription() }}</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <form wire:submit="saveHourlyRates" class="flex max-w-2xl flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="hourly-rates">
                <flux:input wire:model="hourlyRate" :label="__('Hourly rate ($)')" inputmode="decimal" :disabled="! $this->editable" data-test="hourly-rate" />
                <div class="flex flex-col gap-3">
                    <flux:heading level="3">{{ __('By estimate type') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('Only where one differs. Leave empty to use the hourly rate above.') }}</flux:text>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (EstimateType::cases() as $type)
                            <flux:input wire:model="typeRates.{{ $type->value }}" :label="$type->label().' ($)'" inputmode="decimal" :disabled="! $this->editable" />
                        @endforeach
                    </div>
                </div>
                <flux:input wire:model="taxPercent" :label="__('Tax (%)')" inputmode="decimal" class="max-w-40" :disabled="! $this->editable" data-test="tax-percent" />
                @if ($this->editable)
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" data-test="save-hourly-rates">{{ __('Save') }}</flux:button>
                    </div>
                @endif
            </form>
        @endif
    @endif

    <flux:modal name="customize-price-book" class="w-full max-w-md">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Customize for :location?', ['location' => $this->location?->name]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('The default price book is copied for this Location. From then on its prices are changed here, and changes to the default no longer reach it.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="customize" data-test="confirm-customize">{{ __('Customize') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="production-rate" class="w-full max-w-2xl">
        <form wire:submit="saveRate" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $rateId === null ? __('Add rate') : __('Edit rate') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="rateForm.name" :label="__('Name')" required />
                <flux:input wire:model="rateForm.customer_label" :label="__('Shown to the customer as (optional)')" />
                <flux:select wire:model="rateForm.rate_category_id" :label="__('Category')">
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="(string) $category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="rateForm.paint_product_id" :label="__('Default paint')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->paints as $paint)
                        <flux:select.option :value="(string) $paint->id">{{ $paint->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="rateForm.measure" :label="__('Measures')">
                    @foreach (RateMeasure::cases() as $measure)
                        <flux:select.option :value="$measure->value">{{ $measure->label() }} ({{ $measure->unit() }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="rateForm.calculation" :label="__('Calculated as')">
                    @foreach (RateCalculation::cases() as $calculation)
                        <flux:select.option :value="$calculation->value">{{ $calculation->label(RateMeasure::from($rateForm['measure'] ?? RateMeasure::WallArea->value)) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex flex-col gap-2">
                <flux:heading level="3">{{ __('Values for 1, 2, 3 and 4 coats') }}</flux:heading>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([1, 2, 3, 4] as $number)
                        <flux:input wire:model="rateForm.value_{{ $number }}" :label="trans_choice(':count coat|:count coats', $number)" inputmode="decimal" />
                    @endforeach
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="rateForm.default_coats" :label="__('Default coats')" type="number" min="1" max="9" />
                <flux:input wire:model="rateForm.prep_hours" :label="__('Prep hours')" inputmode="decimal" />
                <flux:input wire:model="rateForm.hourly_rate" :label="__('Own hourly rate ($)')" inputmode="decimal" :placeholder="__('The book\'s')" />
            </div>

            <div class="flex flex-col gap-2">
                <flux:heading level="3">{{ __('Offered on') }}</flux:heading>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (EstimateType::cases() as $type)
                        <flux:checkbox wire:model="rateForm.estimate_types" :value="$type->value" :label="$type->label()" />
                    @endforeach
                </div>
                @error('rateForm.estimate_types') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div class="grid gap-2 sm:grid-cols-2">
                <flux:checkbox wire:model="rateForm.uses_coats" :label="__('Coats multiply its time')" />
                <flux:checkbox wire:model="rateForm.is_active" :label="__('Offered on new estimates')" />
                <flux:checkbox wire:model="rateForm.excluded_from_discount" :label="__('Never discounted')" />
                <flux:checkbox wire:model="rateForm.excluded_from_tax" :label="__('Never taxed')" />
            </div>

            <flux:text class="text-sm">{{ __('Estimates already priced keep the rates they were priced with.') }}</flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-rate">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="rate-category" class="w-full max-w-md">
        <form wire:submit="saveCategory" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $categoryId === null ? __('Add category') : __('Edit category') }}</flux:heading>
            <flux:input wire:model="categoryForm.name" :label="__('Name')" required />
            <div class="flex flex-col gap-2">
                <flux:heading level="3">{{ __('Offered on') }}</flux:heading>
                @foreach (EstimateType::cases() as $type)
                    <flux:checkbox wire:model="categoryForm.estimate_types" :value="$type->value" :label="$type->label()" />
                @endforeach
                @error('categoryForm.estimate_types') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>
            <flux:checkbox wire:model="categoryForm.is_active" :label="__('Offered on new estimates')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-category">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="paint-product" class="w-full max-w-lg">
        <form wire:submit="savePaint" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $paintId === null ? __('Add paint') : __('Edit paint') }}</flux:heading>
            <flux:input wire:model="paintForm.name" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="paintForm.price" :label="__('Price ($)')" inputmode="decimal" required />
                <flux:input wire:model="paintForm.unit_label" :label="__('Per')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="paintForm.coverage_area" :label="__('Covers (sq ft)')" inputmode="decimal" />
                <flux:input wire:model="paintForm.coverage_length" :label="__('Trim (ft)')" inputmode="decimal" />
                <flux:input wire:model="paintForm.coverage_each" :label="__('Per item')" inputmode="decimal" />
            </div>
            <div class="flex flex-col gap-2">
                <flux:heading level="3">{{ __('Offered on') }}</flux:heading>
                @foreach (EstimateType::cases() as $type)
                    <flux:checkbox wire:model="paintForm.estimate_types" :value="$type->value" :label="$type->label()" />
                @endforeach
            </div>
            <flux:checkbox wire:model="paintForm.is_active" :label="__('Offered on new estimates')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-paint">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
