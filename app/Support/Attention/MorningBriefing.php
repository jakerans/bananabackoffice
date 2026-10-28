<?php

namespace App\Support\Attention;

use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The morning briefing on Home (docs/slice-26-needs-attention-plan.md, step
 * 26c; decision 185, question 7): a short summary for the person's role,
 * worked out each time Home opens and counting from that morning in their
 * Location's calendar. Nothing is emailed.
 *
 * - Everyone: their own items, new and cleared since the morning.
 * - A salesperson: pending proposals, appointments to confirm in the next
 *   four days, and visits waiting for them to say what happened.
 * - A Production Manager: jobs running over, job costing late, balances to
 *   chase and Brand Ambassador items, from their own items.
 * - A manager: what is open, Urgent, new and cleared at their Locations.
 */
final class MorningBriefing
{
    /** Sales confirms each appointment one to four days before it (product-architecture.md, 5.3). */
    public const int CONFIRM_DAYS = 4;

    /**
     * @return array{day: string, since: array{new: int, cleared: int}, sections: list<array{title: string, lines: list<array{label: string, count: int, url: string}>}>}
     */
    public static function for(User $user): array
    {
        $timezone = CallingHours::timezoneFor($user->authorizedLocations()->first());
        $morning = CarbonImmutable::now($timezone)->startOfDay();
        $mine = AttentionItem::query()->where('user_id', $user->id);
        $sections = [];

        if ($user->holdsRole(Role::SalesConsultant)) {
            $sections[] = self::sales($user, $morning);
        }

        if ($user->holdsRole(Role::ProductionManager)) {
            $sections[] = self::production($user);
        }

        if ($user->locationsWhere(Permission::ManageProjects)->isNotEmpty()) {
            $sections[] = self::locations($user, $morning);
        }

        return [
            'day' => $morning->isoFormat('dddd D MMMM'),
            'since' => [
                'new' => (clone $mine)->where('opened_at', '>=', $morning->utc())->count(),
                'cleared' => (clone $mine)->where('closed_at', '>=', $morning->utc())->count(),
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @return array{title: string, lines: list<array{label: string, count: int, url: string}>}
     */
    private static function sales(User $user, CarbonImmutable $morning): array
    {
        $withSales = array_values(array_filter(LeadStatus::cases(), fn (LeadStatus $status): bool => $status->isWithSales()));

        $pending = Proposal::query()
            ->counted()
            ->where('result', ProposalResult::Pending)
            ->whereHas('lead', fn (Builder $leads) => $leads->where('assigned_user_id', $user->id)->whereIn('status', $withSales))
            ->distinct()
            ->count('lead_id');

        $toConfirm = Appointment::query()
            ->upcomingAt(now())
            ->where('salesperson_id', $user->id)
            ->where('starts_at', '<', $morning->addDays(self::CONFIRM_DAYS + 1)->utc())
            ->count();

        $toCloseOut = Appointment::query()
            ->overdueForCloseOutAt(now())
            ->where('salesperson_id', $user->id)
            ->whereHas('lead', fn (Builder $leads) => $leads->where('status', LeadStatus::AppointmentBooked))
            ->count();

        return ['title' => 'Sales', 'lines' => [
            ['label' => 'Customers with a pending estimate', 'count' => $pending, 'url' => route('leads')],
            ['label' => 'Appointments to confirm, today to '.self::CONFIRM_DAYS.' days out', 'count' => $toConfirm, 'url' => route('sales.calendar')],
            ['label' => 'Visits waiting for you to say what happened', 'count' => $toCloseOut, 'url' => route('sales.calendar')],
        ]];
    }

    /**
     * @return array{title: string, lines: list<array{label: string, count: int, url: string}>}
     */
    private static function production(User $user): array
    {
        $open = AttentionItem::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->get()
            ->countBy('rule');
        $list = route('dashboard').'#needs-attention';

        return ['title' => 'Production', 'lines' => [
            ['label' => 'Jobs running over', 'count' => (int) $open->get('work_running_over', 0), 'url' => $list],
            ['label' => 'Job costing late', 'count' => (int) $open->get('job_costing_late', 0), 'url' => $list],
            ['label' => 'Balances to chase', 'count' => (int) $open->get('balance_past_due', 0), 'url' => $list],
            ['label' => 'Brand Ambassador items overdue', 'count' => (int) $open->get('ambassador_overdue', 0), 'url' => $list],
        ]];
    }

    /**
     * Each finding counts once however many people own it, and only one the
     * person may open.
     *
     * @return array{title: string, lines: list<array{label: string, count: int, url: string}>}
     */
    private static function locations(User $user, CarbonImmutable $morning): array
    {
        $locations = Location::query()->get();
        $items = AttentionItem::query()
            ->whereIn('location_id', $user->locationsWhere(Permission::ManageProjects)->modelKeys())
            ->where(fn (Builder $recent) => $recent->whereNull('closed_at')->orWhere('closed_at', '>=', $morning->utc()))
            ->get()
            ->filter(fn (AttentionItem $item): bool => Visibility::allows($user, $item, $locations))
            ->unique(fn (AttentionItem $item): string => $item->rule.'|'.$item->subject_key.'|'.($item->closed_at === null ? 'open' : 'closed'));
        $open = $items->whereNull('closed_at');
        $url = $user->hasPermissionAcrossAllLocations(Permission::CompanyOverviewAccess) ? route('company.needs_attention') : route('dashboard').'#needs-attention-locations';

        return ['title' => 'Your Locations', 'lines' => [
            ['label' => 'Urgent', 'count' => $open->where('severity', AttentionItem::URGENT)->count(), 'url' => $url],
            ['label' => 'Open in all', 'count' => $open->count(), 'url' => $url],
            ['label' => 'New since this morning', 'count' => self::since($open, 'opened_at', $morning), 'url' => $url],
            ['label' => 'Cleared since this morning', 'count' => $items->whereNotNull('closed_at')->count(), 'url' => $url],
        ]];
    }

    /**
     * @param  Collection<int, AttentionItem>  $items
     */
    private static function since(Collection $items, string $moment, CarbonImmutable $morning): int
    {
        return $items->filter(fn (AttentionItem $item): bool => $item->{$moment}->greaterThanOrEqualTo($morning))->count();
    }
}
