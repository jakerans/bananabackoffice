<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AreaTemplate;
use App\Models\Discount;
use App\Models\LineItemTemplate;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Give a Location a price book of its own, copied from the default
 * (docs/slice-31-estimating-plan.md, step 31a; decision 197, question 2).
 *
 * Until then the Location estimates with the default. From then on the two
 * books are changed separately: a change to the default no longer reaches
 * this Location. Owner and Leadership only.
 */
class CustomizePriceBook
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Location $location, User $actor): PriceBook
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        return DB::transaction(function () use ($location, $actor): PriceBook {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);

            if (PriceBook::query()->where('location_id', $location->id)->exists()) {
                throw ValidationException::withMessages(['book' => __(':location already has its own price book.', ['location' => $location->name])]);
            }

            $default = PriceBook::query()->whereNull('location_id')->lockForUpdate()->orderBy('id')->first();

            if ($default === null) {
                throw ValidationException::withMessages(['book' => __('There is no default price book to start from yet.')]);
            }

            $book = $default->replicate(['created_by', 'created_by_name']);
            $book->forceFill([
                'location_id' => $location->id,
                'customized_from_id' => $default->id,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            $categoryIds = $this->copy(RateCategory::query()->where('price_book_id', $default->id)->get(), $book);
            $paintIds = $this->copy(PaintProduct::query()->where('price_book_id', $default->id)->get(), $book);
            $this->copy(AreaTemplate::query()->where('price_book_id', $default->id)->get(), $book);
            $this->copy(LineItemTemplate::query()->where('price_book_id', $default->id)->get(), $book);
            $this->copy(Discount::query()->where('price_book_id', $default->id)->get(), $book);
            $this->copy(TaxRate::query()->where('price_book_id', $default->id)->get(), $book);

            foreach (ProductionRate::query()->where('price_book_id', $default->id)->get() as $rate) {
                $copy = $rate->replicate();
                $copy->forceFill([
                    'price_book_id' => $book->id,
                    'rate_category_id' => $rate->rate_category_id === null ? null : $categoryIds[$rate->rate_category_id],
                    'paint_product_id' => $rate->paint_product_id === null ? null : $paintIds[$rate->paint_product_id],
                ])->save();
            }

            $this->recordAuditEvent->handle(
                AuditAction::PricingCustomized,
                $book,
                actor: $actor,
                after: ['started_from' => $default->label()],
                location: $location,
            );

            return $book;
        });
    }

    /**
     * Copy each record into the new book, and say which new record each old one became.
     *
     * @param  iterable<RateCategory|PaintProduct|AreaTemplate|LineItemTemplate|Discount|TaxRate>  $records
     * @return array<int, int>
     */
    private function copy(iterable $records, PriceBook $book): array
    {
        $ids = [];

        foreach ($records as $record) {
            $copy = $record->replicate();
            $copy->forceFill(['price_book_id' => $book->id])->save();
            $ids[$record->id] = $copy->id;
        }

        return $ids;
    }
}
