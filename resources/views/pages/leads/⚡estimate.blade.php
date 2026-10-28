<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\ChangeEstimateHourlyRate;
use App\Actions\Estimates\RemoveFromEstimate;
use App\Actions\Estimates\RepriceEstimate;
use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Enums\AreaShape;
use App\Enums\EstimateType;
use App\Enums\LineItemPricing;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\AreaTemplate;
use App\Models\Discount;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\Lead;
use App\Models\LineItemTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Money;
use App\Support\Pricing\Decimal;
use App\Support\Pricing\EstimatePricing;
use App\Support\Pricing\PricedEstimate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * An estimate in the Sales Workspace (docs/slice-31-estimating-plan.md, step
 * 31c; decision 204), laid out for a tablet first. The salesperson builds
 * each option from the Location's price book: areas with their
 * measurements, surfaces from the production rates with their coats, paint
 * and prep, and line items. Every figure is worked out by the pricing engine
 * as it is changed. More options start as copies of one. The discounts, the
 * tax and the hourly rate are the estimate's and apply to every option.
 *
 * Anyone who may see the Lead sees the estimate, read-only. The salesperson
 * the Lead is with, or a manager, builds it; only a manager changes its
 * hourly rate.
 */
new #[Title('Estimate')] class extends Component {
    private const array NO_AREA = ['id' => null, 'area_template_id' => '', 'name' => '', 'shape' => 'room', 'width' => '', 'length' => '', 'height' => ''];

    private const array NO_SURFACE = ['id' => null, 'area_id' => null, 'category_id' => '', 'production_rate_id' => '', 'coats' => '1', 'quantity' => '', 'paint_product_id' => '', 'prep_hours' => ''];

    private const array NO_LINE_ITEM = ['id' => null, 'line_item_template_id' => '', 'name' => '', 'description' => '', 'pricing' => 'hours', 'hours' => '', 'price' => '', 'hourly_rate' => '', 'quantity' => '', 'hours_each' => '', 'price_each' => ''];

    #[Locked]
    public int $leadId;

    #[Locked]
    public int $estimateId;

    /** The option being built. */
    #[Url(as: 'option')]
    public ?int $optionId = null;

    /** @var array<string, mixed> */
    public array $areaForm = self::NO_AREA;

    /** @var array<string, mixed> */
    public array $surfaceForm = self::NO_SURFACE;

    /** @var array<string, mixed> */
    public array $lineItemForm = self::NO_LINE_ITEM;

    /** @var array{name: string, description: string} */
    public array $optionForm = ['name' => '', 'description' => ''];

    /** @var list<int|string> */
    public array $discountIds = [];

    public string $taxRateId = '';

    public string $hourlyRate = '';

    /** @var array{kind: string, id: int}|null What the open remove dialog is about. */
    #[Locked]
    public ?array $removing = null;

    public function mount(Lead $lead, Estimate $estimate): void
    {
        $this->leadId = $lead->id;
        $this->estimateId = $estimate->id;

        if ($this->optionId === null || ! $estimate->options()->whereKey($this->optionId)->exists()) {
            $this->optionId = $estimate->options()->value('id');
        }

        $this->fillTerms();
    }

    /**
     * Any answer that changes clears the refusals, so one put right is not left looking wrong.
     */
    public function updated(): void
    {
        $this->resetValidation();
    }

    #[Computed]
    public function estimate(): Estimate
    {
        return Estimate::query()->with(['lead.location', 'location', 'priceBook.location', 'discounts'])->where('lead_id', $this->leadId)->findOrFail($this->estimateId);
    }

    /**
     * @return Collection<int, EstimateOption>
     */
    #[Computed]
    public function options(): Collection
    {
        return $this->estimate->options()->get();
    }

    #[Computed]
    public function option(): EstimateOption
    {
        return $this->options->firstWhere('id', $this->optionId) ?? $this->options->firstOrFail();
    }

    #[Computed]
    public function priced(): PricedEstimate
    {
        $option = $this->option->load(['areas.surfaces', 'lineItems']);

        return app(EstimatePricing::class)->price($this->estimate, $option);
    }

    #[Computed]
    public function editable(): bool
    {
        return Gate::allows('update', $this->estimate);
    }

    #[Computed]
    public function rateEditable(): bool
    {
        return Gate::allows('changeHourlyRate', $this->estimate);
    }

    /**
     * The rate categories offered for this estimate's type, with their rates.
     *
     * @return Collection<int, RateCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        $type = $this->estimate->estimate_type->value;

        return RateCategory::query()
            ->where('price_book_id', $this->estimate->price_book_id)
            ->where('is_active', true)
            ->with(['rates' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (RateCategory $category): bool => in_array($type, $category->estimate_types, true))
            ->each(fn (RateCategory $category) => $category->setRelation('rates', $category->rates->filter(fn (ProductionRate $rate): bool => in_array($type, $rate->estimate_types, true))->values()))
            ->filter(fn (RateCategory $category): bool => $category->rates->isNotEmpty())
            ->values();
    }

    #[Computed]
    public function chosenRate(): ?ProductionRate
    {
        $id = (int) ($this->surfaceForm['production_rate_id'] ?? 0);

        return $id === 0 ? null : ProductionRate::query()->where('price_book_id', $this->estimate->price_book_id)->find($id);
    }

    /**
     * @return Collection<int, PaintProduct>
     */
    #[Computed]
    public function paints(): Collection
    {
        return PaintProduct::query()->where('price_book_id', $this->estimate->price_book_id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (PaintProduct $paint): bool => in_array($this->estimate->estimate_type->value, $paint->estimate_types, true))->values();
    }

    /**
     * @return Collection<int, AreaTemplate>
     */
    #[Computed]
    public function areaTemplates(): Collection
    {
        return AreaTemplate::query()->where('price_book_id', $this->estimate->price_book_id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (AreaTemplate $template): bool => in_array($this->estimate->estimate_type->value, $template->estimate_types, true))->values();
    }

    /**
     * @return Collection<int, LineItemTemplate>
     */
    #[Computed]
    public function lineItemTemplates(): Collection
    {
        return LineItemTemplate::query()->where('price_book_id', $this->estimate->price_book_id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (LineItemTemplate $template): bool => in_array($this->estimate->estimate_type->value, $template->estimate_types, true))->values();
    }

    /**
     * @return Collection<int, Discount>
     */
    #[Computed]
    public function bookDiscounts(): Collection
    {
        return Discount::query()->where('price_book_id', $this->estimate->price_book_id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, TaxRate>
     */
    #[Computed]
    public function taxRates(): Collection
    {
        return TaxRate::query()->where('price_book_id', $this->estimate->price_book_id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function showOption(int $optionId): void
    {
        $this->optionId = $this->options->firstWhere('id', $optionId)?->id ?? $this->optionId;
        unset($this->option, $this->priced);
    }

    /*
     * Options.
     */

    public function editOption(): void
    {
        $this->optionForm = ['name' => $this->option->name, 'description' => (string) $this->option->description];
        Flux::modal('estimate-option')->show();
    }

    public function saveOption(SaveEstimateOption $saveEstimateOption): void
    {
        $this->asForm('optionForm', fn () => $saveEstimateOption->handle($this->estimate, $this->option, $this->optionForm, $this->user()));
        $this->changed();
        Flux::modal('estimate-option')->close();
    }

    public function copyOption(SaveEstimateOption $saveEstimateOption): void
    {
        $copy = $saveEstimateOption->copy($this->estimate, $this->option, $this->user());
        $this->optionId = $copy->id;
        $this->changed();
        Flux::toast(__('Copied. Change this option to make it different.'));
    }

    /*
     * Areas.
     */

    public function newArea(): void
    {
        $this->resetValidation();
        $this->areaForm = ['shape' => $this->defaultShape()->value] + self::NO_AREA;
        Flux::modal('estimate-area')->show();
    }

    public function editArea(int $areaId): void
    {
        $area = $this->area($areaId);
        $this->resetValidation();
        $this->areaForm = [
            'id' => $area->id,
            'area_template_id' => (string) $area->area_template_id,
            'name' => $area->name,
            'shape' => $area->shape->value,
            'width' => Decimal::plain($area->width),
            'length' => Decimal::plain($area->length),
            'height' => Decimal::plain($area->height),
        ];
        Flux::modal('estimate-area')->show();
    }

    /**
     * Choosing an area template names the area after it.
     */
    public function updatedAreaFormAreaTemplateId(string $value): void
    {
        $template = $this->areaTemplates->firstWhere('id', (int) $value);

        if ($template !== null) {
            $this->areaForm['name'] = $template->name;
            $this->areaForm['shape'] = match ($template->side) {
                'exterior' => AreaShape::Surface->value,
                'interior' => AreaShape::Room->value,
                default => $this->areaForm['shape'],
            };
        }
    }

    public function saveArea(SaveEstimateArea $saveEstimateArea): void
    {
        $area = $this->areaForm['id'] === null ? null : $this->area((int) $this->areaForm['id']);
        $this->asForm('areaForm', fn () => $saveEstimateArea->handle($this->estimate, $this->option, $area, $this->areaForm, $this->user()));
        $this->changed();
        Flux::modal('estimate-area')->close();
    }

    /*
     * Surfaces.
     */

    public function newSurface(int $areaId): void
    {
        $this->resetValidation();
        $this->surfaceForm = ['area_id' => $this->area($areaId)->id] + self::NO_SURFACE;
        Flux::modal('estimate-surface')->show();
    }

    public function editSurface(int $surfaceId): void
    {
        $surface = $this->surface($surfaceId);
        $rate = $surface->productionRate;

        $this->resetValidation();
        $this->surfaceForm = [
            'id' => $surface->id,
            'area_id' => $surface->estimate_area_id,
            'category_id' => (string) $rate?->rate_category_id,
            'production_rate_id' => (string) $surface->production_rate_id,
            'coats' => (string) $surface->coats,
            'quantity' => Decimal::plain($surface->quantity),
            'paint_product_id' => (string) $surface->paint_product_id,
            'prep_hours' => (string) Decimal::plain($surface->prep_hours),
        ];
        Flux::modal('estimate-surface')->show();
    }

    public function updatedSurfaceFormCategoryId(): void
    {
        $this->surfaceForm['production_rate_id'] = '';
    }

    /**
     * Choosing a rate starts from its default coats, paint and prep.
     */
    public function updatedSurfaceFormProductionRateId(): void
    {
        $rate = $this->chosenRate;

        if ($rate !== null) {
            $this->surfaceForm['coats'] = (string) ($rate->uses_coats ? $rate->default_coats : 1);
            $this->surfaceForm['paint_product_id'] = $rate->calculation === RateCalculation::PricePerUnit ? '' : (string) $rate->paint_product_id;
            $this->surfaceForm['prep_hours'] = (string) Decimal::plain($rate->prep_hours);
        }
    }

    public function saveSurface(SaveEstimateSurface $saveEstimateSurface): void
    {
        $area = $this->area((int) $this->surfaceForm['area_id']);
        $surface = $this->surfaceForm['id'] === null ? null : $this->surface((int) $this->surfaceForm['id']);

        $this->asForm('surfaceForm', fn () => $saveEstimateSurface->handle($this->estimate, $area, $surface, $this->surfaceForm, $this->user()));
        $this->changed();
        Flux::modal('estimate-surface')->close();
    }

    /*
     * Line items.
     */

    public function newLineItem(): void
    {
        $this->resetValidation();
        $this->lineItemForm = self::NO_LINE_ITEM;
        Flux::modal('estimate-line-item')->show();
    }

    public function editLineItem(int $itemId): void
    {
        $item = $this->lineItem($itemId);
        $this->resetValidation();
        $this->lineItemForm = [
            'id' => $item->id,
            'line_item_template_id' => (string) $item->line_item_template_id,
            'name' => $item->name,
            'description' => (string) $item->description,
            'pricing' => $item->pricing->value,
            'hours' => (string) Decimal::plain($item->hours),
            'price' => $item->price_cents === null ? '' : number_format($item->price_cents / 100, 2, '.', ''),
            'hourly_rate' => $item->hourly_rate_cents === null ? '' : number_format($item->hourly_rate_cents / 100, 2, '.', ''),
            'quantity' => (string) Decimal::plain($item->quantity),
            'hours_each' => (string) Decimal::plain($item->hours_each),
            'price_each' => $item->price_each_cents === null ? '' : number_format($item->price_each_cents / 100, 2, '.', ''),
        ];
        Flux::modal('estimate-line-item')->show();
    }

    /**
     * Choosing a line item template fills in its name, words and price.
     */
    public function updatedLineItemFormLineItemTemplateId(string $value): void
    {
        $template = $this->lineItemTemplates->firstWhere('id', (int) $value);

        if ($template === null) {
            return;
        }

        $this->lineItemForm['name'] = $template->name;
        $this->lineItemForm['description'] = $template->plainDescription();

        if ($template->hours !== null && (float) $template->hours > 0) {
            $this->lineItemForm['pricing'] = LineItemPricing::Hours->value;
            $this->lineItemForm['hours'] = (string) Decimal::plain($template->hours);
            $this->lineItemForm['hourly_rate'] = $template->hourly_rate_cents === null ? '' : number_format($template->hourly_rate_cents / 100, 2, '.', '');
        } else {
            $this->lineItemForm['pricing'] = LineItemPricing::Price->value;
            $this->lineItemForm['price'] = number_format(($template->price_cents ?? 0) / 100, 2, '.', '');
        }
    }

    public function saveLineItem(SaveEstimateLineItem $saveEstimateLineItem): void
    {
        $item = $this->lineItemForm['id'] === null ? null : $this->lineItem((int) $this->lineItemForm['id']);
        $this->asForm('lineItemForm', fn () => $saveEstimateLineItem->handle($this->estimate, $this->option, $item, $this->lineItemForm, $this->user()));
        $this->changed();
        Flux::modal('estimate-line-item')->close();
    }

    /*
     * Removing, after a styled confirmation.
     */

    public function confirmRemove(string $kind, int $id): void
    {
        $this->part($kind, $id);
        $this->removing = ['kind' => $kind, 'id' => $id];
        Flux::modal('estimate-remove')->show();
    }

    public function remove(RemoveFromEstimate $removeFromEstimate): void
    {
        if ($this->removing === null) {
            return;
        }

        // Resolved again on confirmation, and authorized by the action.
        $part = $this->part($this->removing['kind'], $this->removing['id']);
        $removeFromEstimate->handle($this->estimate, $part, $this->user());

        if ($part instanceof EstimateOption) {
            $this->optionId = $this->estimate->options()->value('id');
        }

        $this->removing = null;
        $this->changed();
        Flux::modal('estimate-remove')->close();
    }

    #[Computed]
    public function removingName(): string
    {
        if ($this->removing === null) {
            return '';
        }

        $part = $this->part($this->removing['kind'], $this->removing['id']);

        return match (true) {
            $part instanceof EstimateSurface => $part->rateSnapshot()->customerName(),
            default => $part->name,
        };
    }

    /*
     * Discounts, tax, hourly rate and repricing.
     */

    public function saveDiscounts(ChangeEstimateDiscounts $changeEstimateDiscounts): void
    {
        $changeEstimateDiscounts->handle($this->estimate, $this->discountIds, $this->taxRateId === '' ? null : $this->taxRateId, $this->user());
        $this->changed();
        Flux::toast(__('Discounts and tax saved.'));
    }

    public function saveHourlyRate(ChangeEstimateHourlyRate $changeEstimateHourlyRate): void
    {
        $changeEstimateHourlyRate->handle($this->estimate, $this->hourlyRate, $this->user());
        $this->changed();
        Flux::toast(__('Hourly rate saved.'));
    }

    public function reprice(RepriceEstimate $repriceEstimate): void
    {
        $result = $repriceEstimate->handle($this->estimate, $this->user());
        $this->changed();
        Flux::modal('estimate-reprice')->close();

        Flux::toast($result['kept'] === 0
            ? trans_choice('{0} Repriced. Nothing had changed.|{1} Repriced. One price changed.|[2,*] Repriced. :count prices changed.', $result['changed'])
            : __('Repriced. :changed changed; :kept no longer in the price book, kept as they were.', ['changed' => $result['changed'], 'kept' => $result['kept']]));
    }

    private function fillTerms(): void
    {
        $estimate = $this->estimate;
        $this->discountIds = $estimate->discounts->pluck('discount_id')->filter()->map(fn (int $id): string => (string) $id)->values()->all();
        $this->taxRateId = (string) (TaxRate::query()->where('price_book_id', $estimate->price_book_id)->where('name', $estimate->tax_name)->value('id') ?? '');
        $this->hourlyRate = number_format($estimate->hourly_rate_cents / 100, 2, '.', '');
    }

    /**
     * Forget what was read, so the page shows the estimate as it is now.
     */
    private function changed(): void
    {
        unset($this->estimate, $this->options, $this->option, $this->priced, $this->removingName);
        $this->fillTerms();
    }

    private function defaultShape(): AreaShape
    {
        return $this->estimate->estimate_type === EstimateType::ResidentialExterior ? AreaShape::Surface : AreaShape::Room;
    }

    /**
     * Save through an action, with each refusal shown under its field in the open form.
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

    private function area(int $id): EstimateArea
    {
        return EstimateArea::query()->whereKey($id)->whereHas('option', fn ($query) => $query->where('estimate_id', $this->estimateId))->firstOrFail();
    }

    private function surface(int $id): EstimateSurface
    {
        return EstimateSurface::query()->with('productionRate')->whereKey($id)->whereHas('area.option', fn ($query) => $query->where('estimate_id', $this->estimateId))->firstOrFail();
    }

    private function lineItem(int $id): EstimateLineItem
    {
        return EstimateLineItem::query()->whereKey($id)->whereHas('option', fn ($query) => $query->where('estimate_id', $this->estimateId))->firstOrFail();
    }

    private function part(string $kind, int $id): EstimateOption|EstimateArea|EstimateSurface|EstimateLineItem
    {
        return match ($kind) {
            'option' => EstimateOption::query()->where('estimate_id', $this->estimateId)->findOrFail($id),
            'area' => $this->area($id),
            'surface' => $this->surface($id),
            'line-item' => $this->lineItem($id),
            default => abort(404),
        };
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="estimate-page">
    @php
        $estimate = $this->estimate;
        $priced = $this->priced;
        $editable = $this->editable;
    @endphp

    <div class="flex flex-col gap-2">
        <flux:link :href="route('leads.show', $leadId)" wire:navigate class="text-sm">{{ __('Back to the lead') }}</flux:link>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="xl" level="1">{{ __(':type estimate', ['type' => $estimate->estimate_type->label()]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $estimate->lead->displayName() ?? __('Lead #:id', ['id' => $leadId]) }} ·
                    {{ $estimate->priceBook?->label() ?? __('No price book') }} ·
                    {{ __('prices from :date', ['date' => $estimate->priced_at->timezone(config('app.display_timezone'))->format('M j, Y g:i a')]) }}
                </flux:text>
            </div>
            @if ($editable)
                <flux:modal.trigger name="estimate-reprice">
                    <flux:button size="sm" icon="arrow-path" data-test="reprice">{{ __('Reprice with today\'s prices') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    {{-- The options, each with its price. --}}
    <div class="flex flex-wrap items-center gap-2" data-test="estimate-options">
        @foreach ($this->options as $option)
            <flux:button size="sm" wire:key="option-tab-{{ $option->id }}" wire:click="showOption({{ $option->id }})" :variant="$option->id === $this->option->id ? 'primary' : 'outline'" data-test="option-{{ $option->id }}">
                {{ $option->name }} · <span class="tabular-nums">{{ Money::format($option->total_cents) }}</span>
            </flux:button>
        @endforeach
        @if ($editable)
            <flux:button size="sm" variant="ghost" icon="document-duplicate" wire:click="copyOption" data-test="copy-option">{{ __('Copy this option') }}</flux:button>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-zinc-200 bg-card p-4">
                <div class="min-w-0">
                    <flux:heading size="lg" level="2">{{ $this->option->name }}</flux:heading>
                    @if ($this->option->description)
                        <flux:text class="mt-1 whitespace-pre-line text-sm">{{ $this->option->description }}</flux:text>
                    @endif
                </div>
                @if ($editable)
                    <div class="flex gap-2">
                        <flux:button size="sm" wire:click="editOption" data-test="edit-option">{{ __('Name and describe') }}</flux:button>
                        @if ($this->options->count() > 1)
                            <flux:button size="sm" variant="ghost" wire:click="confirmRemove('option', {{ $this->option->id }})" data-test="remove-option">{{ __('Remove option') }}</flux:button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Areas, each with its surfaces and their figures. --}}
            @foreach ($this->option->areas as $index => $area)
                @php($pricedArea = $priced->areas[$index])
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" wire:key="area-{{ $area->id }}" data-test="area-{{ $area->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <flux:heading level="3">{{ $area->name }}</flux:heading>
                            <flux:text class="text-sm">
                                {{ $area->shape->label() }} · {{ Decimal::plain($area->width) }} × {{ Decimal::plain($area->length) }} × {{ Decimal::plain($area->height) }} ft ·
                                {{ __('walls :walls sq ft, ceiling :ceiling sq ft, :length ft', ['walls' => round($pricedArea->measurements->wallArea, 2), 'ceiling' => round($pricedArea->measurements->ceilingArea, 2), 'length' => round($pricedArea->measurements->linearFeet, 2)]) }}
                            </flux:text>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium tabular-nums text-ink" data-test="area-total-{{ $area->id }}">{{ Money::format($pricedArea->totalCents()) }}</span>
                            @if ($editable)
                                <flux:button size="xs" variant="ghost" wire:click="editArea({{ $area->id }})" data-test="edit-area-{{ $area->id }}">{{ __('Edit') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="confirmRemove('area', {{ $area->id }})" data-test="remove-area-{{ $area->id }}">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($area->surfaces->isNotEmpty())
                        <div class="-mx-4 overflow-x-auto px-4">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ __('Work') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('Paint') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                                    @if ($editable)
                                        <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                                    @endif
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($area->surfaces as $position => $surface)
                                        @php($pricedSurface = $pricedArea->surfaces[$position])
                                        @php($rate = $surface->rateSnapshot())
                                        <flux:table.row :key="'surface-'.$surface->id" data-test="surface-{{ $surface->id }}">
                                            <flux:table.cell class="min-w-48 whitespace-normal">
                                                <div class="font-medium text-ink">{{ $rate->customerName() }}</div>
                                                <div class="text-xs text-zinc-500">
                                                    {{ $rate->name }}
                                                    @if ($rate->usesCoats) · {{ trans_choice(':count coat|:count coats', $surface->coats) }} @endif
                                                    @if ($rate->measure === RateMeasure::Count) · {{ __(':count each', ['count' => Decimal::plain($surface->quantity)]) }} @endif
                                                    @if ($surface->paint) · {{ data_get($surface->paint, 'name') }} @endif
                                                    @if ($pricedSurface->prepHours > 0) · {{ __(':hours prep hours', ['hours' => $pricedSurface->prepHours]) }} @endif
                                                </div>
                                            </flux:table.cell>
                                            <flux:table.cell align="end" class="tabular-nums">{{ $pricedSurface->hours + $pricedSurface->prepHours }}</flux:table.cell>
                                            <flux:table.cell align="end" class="tabular-nums">{{ $pricedSurface->materialsCents > 0 ? Money::format($pricedSurface->materialsCents) : '—' }}</flux:table.cell>
                                            <flux:table.cell align="end" class="font-medium tabular-nums">{{ Money::format($pricedSurface->totalCents()) }}</flux:table.cell>
                                            @if ($editable)
                                                <flux:table.cell align="end">
                                                    <div class="flex justify-end gap-1">
                                                        <flux:button size="xs" variant="ghost" wire:click="editSurface({{ $surface->id }})" data-test="edit-surface-{{ $surface->id }}">{{ __('Edit') }}</flux:button>
                                                        <flux:button size="xs" variant="ghost" wire:click="confirmRemove('surface', {{ $surface->id }})" data-test="remove-surface-{{ $surface->id }}">{{ __('Remove') }}</flux:button>
                                                    </div>
                                                </flux:table.cell>
                                            @endif
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @endif

                    @if ($editable)
                        <div>
                            <flux:button size="sm" icon="plus" wire:click="newSurface({{ $area->id }})" data-test="add-surface-{{ $area->id }}">{{ __('Add work') }}</flux:button>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($editable)
                <div>
                    <flux:button icon="plus" wire:click="newArea" data-test="add-area">{{ $estimate->estimate_type === EstimateType::ResidentialExterior ? __('Add a surface or area') : __('Add a room or area') }}</flux:button>
                </div>
            @endif

            {{-- Line items. --}}
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" data-test="line-items">
                <flux:heading level="3">{{ __('Line items') }}</flux:heading>
                @forelse ($this->option->lineItems as $position => $item)
                    @php($pricedItem = $priced->lineItems[$position])
                    <div class="flex flex-wrap items-start justify-between gap-2 border-t border-zinc-100 pt-3 first-of-type:border-0 first-of-type:pt-0" wire:key="line-item-{{ $item->id }}" data-test="line-item-{{ $item->id }}">
                        <div class="min-w-0">
                            <div class="font-medium text-ink">{{ $item->name }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ $item->pricing->label() }}
                                @if ($pricedItem->hours > 0) · {{ __(':hours hours', ['hours' => $pricedItem->hours]) }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium tabular-nums">{{ Money::format($pricedItem->priceCents) }}</span>
                            @if ($editable)
                                <flux:button size="xs" variant="ghost" wire:click="editLineItem({{ $item->id }})" data-test="edit-line-item-{{ $item->id }}">{{ __('Edit') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="confirmRemove('line-item', {{ $item->id }})" data-test="remove-line-item-{{ $item->id }}">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('No line items.') }}</flux:text>
                @endforelse
                @if ($editable)
                    <div>
                        <flux:button size="sm" icon="plus" wire:click="newLineItem" data-test="add-line-item">{{ __('Add line item') }}</flux:button>
                    </div>
                @endif
            </div>
        </div>

        {{-- The option's total, and the estimate's discounts, tax and hourly rate. --}}
        <div class="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
            <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-4" data-test="option-total">
                <flux:heading level="3">{{ __('This option') }}</flux:heading>
                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-zinc-500">{{ __('Hours') }}</dt><dd class="text-end tabular-nums">{{ $priced->hours }}@if ($priced->prepHours > 0) + {{ $priced->prepHours }} {{ __('prep') }}@endif</dd>
                    <dt class="text-zinc-500">{{ __('Labor') }}</dt><dd class="text-end tabular-nums">{{ Money::format($priced->workCents) }}</dd>
                    <dt class="text-zinc-500">{{ __('Paint') }}</dt><dd class="text-end tabular-nums">{{ Money::format($priced->materialsCents) }}</dd>
                    <dt class="text-zinc-500">{{ __('Line items') }}</dt><dd class="text-end tabular-nums">{{ Money::format($priced->lineItemsCents) }}</dd>
                    <dt class="text-zinc-500">{{ __('Subtotal') }}</dt><dd class="text-end tabular-nums">{{ Money::format($priced->subtotalCents) }}</dd>
                    @foreach ($priced->discounts as $given)
                        <dt class="text-zinc-500">{{ $given['discount']->name }}</dt><dd class="text-end tabular-nums">−{{ Money::format($given['cents']) }}</dd>
                    @endforeach
                    @if ($estimate->tax_name)
                        <dt class="text-zinc-500">{{ $estimate->tax_name }} ({{ Decimal::plain($estimate->tax_percent) }}%)</dt><dd class="text-end tabular-nums">{{ Money::format($priced->taxCents) }}</dd>
                    @endif
                    <dt class="font-medium text-ink">{{ __('Total') }}</dt><dd class="text-end text-lg font-semibold tabular-nums text-ink" data-test="option-total-amount">{{ Money::format($priced->totalCents) }}</dd>
                </dl>
            </div>

            <form wire:submit="saveDiscounts" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" data-test="estimate-discounts">
                <flux:heading level="3">{{ __('Discounts and tax') }}</flux:heading>
                <flux:text class="text-xs">{{ __('For every option.') }}</flux:text>
                @forelse ($this->bookDiscounts as $discount)
                    <flux:checkbox wire:model="discountIds" :value="(string) $discount->id" :label="$discount->name" :disabled="! $editable" wire:key="discount-{{ $discount->id }}" />
                @empty
                    <flux:text class="text-sm">{{ __('No named discounts in this price book.') }}</flux:text>
                @endforelse
                <flux:error name="discountIds" />
                <flux:select wire:model="taxRateId" :label="__('Tax')" :disabled="! $editable" data-test="tax-rate">
                    <flux:select.option value="">{{ __('No tax') }}</flux:select.option>
                    @foreach ($this->taxRates as $taxRate)
                        <flux:select.option :value="(string) $taxRate->id" wire:key="tax-{{ $taxRate->id }}">{{ $taxRate->name }} ({{ Decimal::plain($taxRate->percent) }}%)</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="taxRateId" />
                @if ($editable)
                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" variant="primary" data-test="save-discounts">{{ __('Save') }}</flux:button>
                    </div>
                @endif
            </form>

            <form wire:submit="saveHourlyRate" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4" data-test="estimate-hourly-rate">
                <flux:input wire:model="hourlyRate" :label="__('Hourly rate ($)')" inputmode="decimal" :disabled="! $this->rateEditable" data-test="hourly-rate" />
                @if ($this->rateEditable)
                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" data-test="save-hourly-rate">{{ __('Save') }}</flux:button>
                    </div>
                @else
                    <flux:text class="text-xs">{{ __('A manager changes the hourly rate.') }}</flux:text>
                @endif
            </form>
        </div>
    </div>

    @if ($editable)
        <flux:modal name="estimate-option" class="w-full max-w-lg">
            <form wire:submit="saveOption" class="flex flex-col gap-5">
                <flux:heading size="lg">{{ __('Name and describe this option') }}</flux:heading>
                <flux:input wire:model="optionForm.name" :label="__('Name')" :placeholder="__('Whole interior')" data-test="option-name" />
                <flux:error name="optionForm.name" />
                <flux:textarea wire:model="optionForm.description" :label="__('What the customer reads about it')" rows="4" />
                <flux:error name="optionForm.description" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-option">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="estimate-area" class="w-full max-w-lg">
            <form wire:submit="saveArea" class="flex flex-col gap-5">
                <flux:heading size="lg">{{ $areaForm['id'] === null ? __('Add an area') : __('Edit area') }}</flux:heading>
                <flux:select wire:model.live="areaForm.area_template_id" :label="__('Area')" :placeholder="__('Choose, or type a name below')" data-test="area-template">
                    @foreach ($this->areaTemplates as $template)
                        <flux:select.option :value="(string) $template->id" wire:key="area-template-{{ $template->id }}">{{ $template->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="areaForm.name" :label="__('Name')" data-test="area-name" />
                <flux:error name="areaForm.name" />
                <flux:radio.group wire:model="areaForm.shape" :label="__('Shape')" variant="segmented">
                    @foreach (AreaShape::cases() as $shape)
                        <flux:radio :value="$shape->value" :label="$shape->label()" />
                    @endforeach
                </flux:radio.group>
                <div class="grid grid-cols-3 gap-3">
                    <flux:input wire:model="areaForm.width" :label="__('Width (ft)')" inputmode="decimal" data-test="area-width" />
                    <flux:input wire:model="areaForm.length" :label="__('Length (ft)')" inputmode="decimal" data-test="area-length" />
                    <flux:input wire:model="areaForm.height" :label="__('Height (ft)')" inputmode="decimal" data-test="area-height" />
                </div>
                <flux:error name="areaForm.width" />
                <flux:error name="areaForm.length" />
                <flux:error name="areaForm.height" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-area">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="estimate-surface" class="w-full max-w-xl">
            <form wire:submit="saveSurface" class="flex flex-col gap-5">
                <flux:heading size="lg">{{ $surfaceForm['id'] === null ? __('Add work') : __('Edit work') }}</flux:heading>
                <flux:select wire:model.live="surfaceForm.category_id" :label="__('Category')" :placeholder="__('Choose a category')" data-test="surface-category">
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="(string) $category->id" wire:key="category-{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @php($categoryRates = $this->categories->firstWhere('id', (int) $surfaceForm['category_id'])?->rates ?? collect())
                <flux:select wire:model.live="surfaceForm.production_rate_id" :label="__('Rate')" :placeholder="__('Choose a rate')" :disabled="$categoryRates->isEmpty() && ! $this->chosenRate" data-test="surface-rate">
                    @foreach ($categoryRates as $rate)
                        <flux:select.option :value="(string) $rate->id" wire:key="rate-{{ $rate->id }}">{{ $rate->name }}</flux:select.option>
                    @endforeach
                    @if ($this->chosenRate && ! $categoryRates->contains('id', $this->chosenRate->id))
                        <flux:select.option :value="(string) $this->chosenRate->id">{{ $this->chosenRate->name }}</flux:select.option>
                    @endif
                </flux:select>
                <flux:error name="surfaceForm.production_rate_id" />

                @if ($rate = $this->chosenRate)
                    <div class="grid gap-4 sm:grid-cols-2">
                        @if ($rate->uses_coats)
                            <flux:select wire:model="surfaceForm.coats" :label="__('Coats')" data-test="surface-coats">
                                @foreach (array_keys(array_filter($rate->values(), fn ($value) => $value !== null && (float) $value > 0)) as $index)
                                    <flux:select.option :value="(string) ($index + 1)">{{ $index + 1 }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                        @if ($rate->measure === RateMeasure::Count)
                            <flux:input wire:model="surfaceForm.quantity" :label="__('How many')" inputmode="decimal" data-test="surface-quantity" />
                        @endif
                        @if ($rate->calculation !== RateCalculation::PricePerUnit)
                            <flux:select wire:model="surfaceForm.paint_product_id" :label="__('Paint')" data-test="surface-paint">
                                <flux:select.option value="">{{ __('No paint') }}</flux:select.option>
                                @foreach ($this->paints as $paint)
                                    <flux:select.option :value="(string) $paint->id" wire:key="paint-{{ $paint->id }}">{{ $paint->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="surfaceForm.prep_hours" :label="__('Prep hours')" inputmode="decimal" data-test="surface-prep" />
                        @endif
                    </div>
                    <flux:error name="surfaceForm.coats" />
                    <flux:error name="surfaceForm.quantity" />
                    <flux:error name="surfaceForm.paint_product_id" />
                    <flux:error name="surfaceForm.prep_hours" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-surface">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="estimate-line-item" class="w-full max-w-xl">
            <form wire:submit="saveLineItem" class="flex flex-col gap-5">
                <flux:heading size="lg">{{ $lineItemForm['id'] === null ? __('Add a line item') : __('Edit line item') }}</flux:heading>
                <flux:select wire:model.live="lineItemForm.line_item_template_id" :label="__('Start from')" :placeholder="__('Type one in')" data-test="line-item-template">
                    @foreach ($this->lineItemTemplates as $template)
                        <flux:select.option :value="(string) $template->id" wire:key="line-template-{{ $template->id }}">{{ $template->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="lineItemForm.name" :label="__('Name')" data-test="line-item-name" />
                <flux:error name="lineItemForm.name" />
                <flux:textarea wire:model="lineItemForm.description" :label="__('Description')" rows="3" />
                <flux:radio.group wire:model.live="lineItemForm.pricing" :label="__('Priced')" variant="segmented">
                    @foreach (LineItemPricing::cases() as $pricing)
                        <flux:radio :value="$pricing->value" :label="$pricing->label()" />
                    @endforeach
                </flux:radio.group>
                <div class="grid gap-4 sm:grid-cols-3">
                    @if ($lineItemForm['pricing'] === 'hours')
                        <flux:input wire:model="lineItemForm.hours" :label="__('Hours')" inputmode="decimal" data-test="line-item-hours" />
                        <flux:input wire:model="lineItemForm.hourly_rate" :label="__('Own hourly rate ($)')" inputmode="decimal" :placeholder="Money::format($estimate->hourly_rate_cents)" :disabled="! $this->rateEditable" />
                    @elseif ($lineItemForm['pricing'] === 'price')
                        <flux:input wire:model="lineItemForm.price" :label="__('Price ($)')" inputmode="decimal" data-test="line-item-price" />
                    @else
                        <flux:input wire:model="lineItemForm.quantity" :label="__('How many')" inputmode="decimal" data-test="line-item-quantity" />
                        <flux:input wire:model="lineItemForm.hours_each" :label="__('Hours each')" inputmode="decimal" />
                        <flux:input wire:model="lineItemForm.price_each" :label="__('Price each ($)')" inputmode="decimal" data-test="line-item-price-each" />
                    @endif
                </div>
                @foreach (['hours', 'hourly_rate', 'price', 'quantity', 'hours_each', 'price_each'] as $field)
                    <flux:error name="lineItemForm.{{ $field }}" />
                @endforeach
                <flux:text class="text-xs">{{ __('A line item\'s price is rounded up to the whole dollar.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-line-item">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="estimate-remove" class="w-full max-w-md">
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Remove :name?', ['name' => $this->removingName]) }}</flux:heading>
                    <flux:text class="mt-2">{{ ($removing['kind'] ?? null) === 'area' ? __('Its work comes out with it.') : __('It comes out of this estimate.') }}</flux:text>
                </div>
                <flux:error name="option" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost" autofocus>{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="remove" data-test="confirm-remove">{{ __('Remove') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="estimate-reprice" class="w-full max-w-md">
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Reprice with today\'s prices?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Every rate, paint, discount, tax rate and the hourly rate are taken again from the price book as it is now. Prices that changed since this estimate was priced change here too.') }}</flux:text>
                </div>
                <flux:error name="reprice" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost" autofocus>{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="reprice" data-test="confirm-reprice">{{ __('Reprice') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
