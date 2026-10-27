<?php

namespace App\Models;

use App\Enums\EstimateType;
use Database\Factories\PriceBookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What estimating charges (docs/slice-31-estimating-plan.md, step 31a;
 * decision 197, question 2). The company has one default price book, with no
 * Location. A Location uses the default until it is customized, which copies
 * the default into a book of the Location's own; from then on the two are
 * changed separately.
 *
 * @property int $id
 * @property int|null $location_id Null for the company's default.
 * @property int $hourly_rate_cents
 * @property array<string, int>|null $estimate_type_hourly_rates An hourly rate in cents for an estimate type that differs, keyed by type.
 * @property int $tax_rate_basis_points Hundredths of a percent.
 * @property int|null $customized_from_id The book it was copied from.
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location|null $location
 */
class PriceBook extends Model
{
    /** @use HasFactory<PriceBookFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_rate_cents' => 'integer',
            'estimate_type_hourly_rates' => 'array',
            'tax_rate_basis_points' => 'integer',
        ];
    }

    /**
     * The company's default price book, or null before one is set up.
     */
    public static function companyDefault(): ?self
    {
        return self::query()->whereNull('location_id')->orderBy('id')->first();
    }

    /**
     * The price book a Location estimates with: its own once customized, the default until then.
     */
    public static function forLocation(Location $location): ?self
    {
        return self::query()->where('location_id', $location->id)->first() ?? self::companyDefault();
    }

    public function isDefault(): bool
    {
        return $this->location_id === null;
    }

    /**
     * "Default price book", or the Location's name.
     */
    public function label(): string
    {
        return $this->isDefault() ? __('Default price book') : __('Price book for :location', ['location' => $this->location?->name]);
    }

    /**
     * The hourly rate for an estimate type, in cents: its own where set, otherwise the book's.
     */
    public function hourlyRateFor(EstimateType $type): int
    {
        $own = $this->estimate_type_hourly_rates[$type->value] ?? null;

        return is_int($own) && $own > 0 ? $own : $this->hourly_rate_cents;
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<RateCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(RateCategory::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductionRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ProductionRate::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PaintProduct, $this>
     */
    public function paints(): HasMany
    {
        return $this->hasMany(PaintProduct::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<AreaTemplate, $this>
     */
    public function areaTemplates(): HasMany
    {
        return $this->hasMany(AreaTemplate::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<LineItemTemplate, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItemTemplate::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Discount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<TaxRate, $this>
     */
    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderBy('sort_order')->orderBy('id');
    }
}
