<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Discount;
use App\Models\PriceBook;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a discount or tax rate one place up or down in its price book's list
 * (docs/slice-35-pricing-settings-plan.md, step 35a; decision 213). New
 * estimates list them in this order. Owner and Leadership only; recorded
 * with the item's name and its new place.
 */
class MovePriceBookItem
{
    /**
     * Each list that can be reordered, by key: its model and what it is called.
     *
     * @var array<string, array{model: class-string<Discount|TaxRate>, label: string}>
     */
    public const array LISTS = [
        'discounts' => ['model' => Discount::class, 'label' => 'Discounts'],
        'tax_rates' => ['model' => TaxRate::class, 'label' => 'Tax rates'],
    ];

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  string  $direction  "up" or "down"; anything else is not found.
     *
     * @throws AuthorizationException
     */
    public function handle(PriceBook $book, string $list, int $itemId, string $direction, User $actor): void
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        abort_unless(isset(self::LISTS[$list]) && in_array($direction, ['up', 'down'], true), 404);

        $model = self::LISTS[$list]['model'];

        DB::transaction(function () use ($book, $list, $model, $itemId, $direction, $actor): void {
            /** @var list<Discount|TaxRate> $items */
            $items = $model::query()->where('price_book_id', $book->id)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get()->all();

            $from = array_search($itemId, array_map(fn (Discount|TaxRate $item): int => $item->id, $items), true);

            if ($from === false) {
                throw new AuthorizationException;
            }

            $to = $direction === 'up' ? $from - 1 : $from + 1;

            if (! isset($items[$to])) {
                return;
            }

            [$items[$from], $items[$to]] = [$items[$to], $items[$from]];

            foreach ($items as $position => $item) {
                if ($item->sort_order !== $position) {
                    $item->forceFill(['sort_order' => $position])->save();
                }
            }

            $this->recordAuditEvent->handle(
                AuditAction::PricingListReordered,
                $book,
                actor: $actor,
                after: [
                    'list' => self::LISTS[$list]['label'],
                    'moved' => $items[$to]->name,
                    'place' => $to + 1,
                ],
                location: $book->location,
            );
        });
    }
}
