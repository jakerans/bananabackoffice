<?php

namespace App\Support\Attention;

use App\Enums\Permission;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The open items of Needs Attention a person sees, the most serious first,
 * then the oldest (docs/slice-26-needs-attention-plan.md, step 26b; decision
 * 185, question 4). Each finding is shown once, with everyone who owns it.
 *
 * - "mine": the person's own, leaving out what they dismissed.
 * - "locations": every one at the Locations they manage projects at.
 * - "company": every one.
 *
 * Never an item about a record the person may not open.
 */
final class AttentionList
{
    public const string MINE = 'mine';

    public const string LOCATIONS = 'locations';

    public const string COMPANY = 'company';

    /**
     * @return Collection<int, array{item: AttentionItem, owners: list<string>}>
     */
    public static function rows(User $user, string $scope): Collection
    {
        $query = AttentionItem::query()->whereNull('closed_at')->with(['user', 'location']);

        if ($scope === self::MINE) {
            $query->where('user_id', $user->id)->where(fn ($open) => $open->whereNull('feedback')->orWhere('feedback', '!=', AttentionItem::DISMISSED));
        } elseif ($scope === self::LOCATIONS) {
            $query->whereIn('location_id', $user->locationsWhere(Permission::ManageProjects)->modelKeys());
        }

        $locations = Location::query()->get();

        return $query->get()
            ->filter(fn (AttentionItem $item): bool => Visibility::allows($user, $item, $locations))
            ->groupBy(fn (AttentionItem $item): string => $item->rule.'|'.$item->subject_key)
            ->map(fn (Collection $items): array => self::row($items, $user))
            ->sortBy([
                fn (array $a, array $b): int => $a['item']->severityRank() <=> $b['item']->severityRank(),
                fn (array $a, array $b): int => $a['item']->opened_at <=> $b['item']->opened_at,
            ])
            ->values();
    }

    /**
     * One finding: the person's own item of it when they have one, and
     * everyone who owns it.
     *
     * @param  Collection<int, AttentionItem>  $items
     * @return array{item: AttentionItem, owners: list<string>}
     */
    private static function row(Collection $items, User $user): array
    {
        return [
            'item' => $items->first(fn (AttentionItem $item): bool => $item->user_id === $user->id) ?? $items->firstOrFail(),
            'owners' => array_values($items->map(fn (AttentionItem $item): string => $item->user->name)->unique()->sort()->values()->all()),
        ];
    }

    /**
     * The widest list a person may see: every item for the Owner and
     * Leadership, their Locations' for a manager, or else their own.
     */
    public static function widestScopeFor(User $user): string
    {
        if ($user->hasPermissionAcrossAllLocations(Permission::CompanyOverviewAccess)) {
            return self::COMPANY;
        }

        return $user->locationsWhere(Permission::ManageProjects)->isNotEmpty() ? self::LOCATIONS : self::MINE;
    }
}
