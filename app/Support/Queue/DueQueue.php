<?php

namespace App\Support\Queue;

use App\Enums\CadenceState;
use App\Enums\Permission;
use App\Enums\QueueEventType;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only questions about the due queue, for the queue screen.
 *
 * Nothing here writes, locks or hands out a Lead. The setter's count is built
 * from the same candidate query the claim reads (candidateQuery), so the two
 * cannot drift; the claim still decides every hand-out on rows read through
 * locks.
 *
 * A "scope" is the same shape QueueEligibility::scope returns: the Location
 * IDs that count, and whether unrouted Leads do.
 *
 * @phpstan-type Scope array{locations: list<int>, unrouted: bool, anywhere: bool}
 */
class DueQueue
{
    /** How many Leads the held and finished lists show. */
    public const int LIST_LIMIT = 25;

    /** The heartbeat is stale once it is older than this many minutes. */
    public const int HEARTBEAT_STALE_MINUTES = 10;

    public function __construct(private QueueEligibility $eligibility) {}

    /**
     * Cadences that are in the queue, due, not held by someone else, and whose
     * Lead is at one of the scope's Locations (or unrouted, when the scope says
     * so). The claim adds the person's skip cooldown, the queue order and its
     * limit; the count adds the cooldown only.
     *
     * @param  array{locations: list<int>, unrouted: bool, anywhere?: bool}  $scope
     */
    public static function candidateQuery(array $scope, CarbonInterface $moment): Builder
    {
        return DB::table('lead_cadences as c')
            ->join('leads as l', 'l.id', '=', 'c.lead_id')
            ->where('c.state', CadenceState::InQueue->value)
            ->where('c.due_at', '<=', $moment)
            ->where(fn (Builder $query) => $query->whereNull('c.held_by_user_id')->orWhere('c.held_until', '<=', $moment))
            ->where(function (Builder $query) use ($scope): void {
                $query->whereIn('l.location_id', $scope['locations']);

                if ($scope['unrouted']) {
                    $query->orWhereNull('l.location_id');
                }
            });
    }

    /**
     * A skip by the person on that cadence whose cooldown is still running.
     *
     * @return Closure(Builder): void
     */
    public static function runningCooldown(int $userId, CarbonInterface $moment, string $cadenceColumn): Closure
    {
        return function (Builder $query) use ($userId, $moment, $cadenceColumn): void {
            $query->selectRaw('1')
                ->from('lead_queue_events as e')
                ->whereColumn('e.lead_cadence_id', $cadenceColumn)
                ->where('e.type', QueueEventType::Skipped->value)
                ->where('e.affected_user_id', $userId)
                ->where('e.cooldown_until', '>', $moment);
        };
    }

    /**
     * How many Leads Next lead could give this person right now. The Lead they
     * are holding is not counted: it is on their card.
     */
    public function dueNowCount(User $user, CarbonInterface $moment): int
    {
        $scope = $this->eligibility->scope($user, $moment);

        if ($scope['locations'] === [] && ! $scope['unrouted']) {
            return 0;
        }

        return self::candidateQuery($scope, $moment)
            ->whereNotExists(self::runningCooldown($user->id, $moment, 'c.id'))
            ->count();
    }

    /**
     * When calling hours next open for the person, across everything they may
     * work, for the "nothing due" message. Null when they may work nothing.
     *
     * @return array{at: CarbonImmutable, timezone: string}|null
     */
    public function nextOpening(User $user, CarbonInterface $moment): ?array
    {
        $hours = CallingHours::fromSettings(ContactCenterSettings::current());

        $timezones = $user->locationsWhere(Permission::WorkLeadQueue)
            ->filter(fn (Location $location): bool => $this->eligibility->mayWork($user, (new Lead)->forceFill(['location_id' => $location->id])->setRelation('location', $location)))
            ->map(fn (Location $location): string => CallingHours::timezoneFor($location));

        if ($this->eligibility->mayWork($user, new Lead)) {
            $timezones->push(CallingHours::UNROUTED_TIMEZONE);
        }

        return $timezones
            ->unique()
            ->map(fn (string $timezone): array => ['at' => $hours->nextOpeningAtOrAfter($moment, $timezone), 'timezone' => $timezone])
            ->sortBy(fn (array $opening): int => $opening['at']->getTimestamp())
            ->first();
    }

    /**
     * Where the person may assign Leads and so may see the manager part of the
     * page: the Locations where they hold the assign permission and may see
     * Leads, and unrouted Leads under the existing company-wide rule.
     *
     * @return array{locations: list<int>, unrouted: bool, anywhere: bool}
     */
    public function managerScope(User $user): array
    {
        $viewable = $user->locationsWhere(Permission::ViewLeads)->modelKeys();

        $locations = array_values(array_map(
            intval(...),
            $user->locationsWhere(Permission::AssignLeads)->filter(fn (Location $location): bool => in_array($location->id, $viewable, true))->modelKeys(),
        ));

        $unrouted = $user->hasPermissionAcrossAllLocations(Permission::AssignLeads)
            && $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);

        return ['locations' => $locations, 'unrouted' => $unrouted, 'anywhere' => $locations !== [] || $unrouted];
    }

    /**
     * The manager summary for a scope. "Due now" is in the queue, due, not
     * held and inside its Location's calling hours at this moment.
     *
     * @param  array{locations: list<int>, unrouted: bool, anywhere: bool}  $scope
     * @return array{
     *     dueNow: int,
     *     longestWaitSince: CarbonInterface|null,
     *     held: Collection<int, LeadCadence>,
     *     heldCount: int,
     *     finishedCount: int,
     *     finished: Collection<int, LeadCadence>,
     *     heartbeat: CarbonInterface|null,
     *     heartbeatStale: bool,
     * }
     */
    public function summary(array $scope, CarbonInterface $moment): array
    {
        $hours = CallingHours::fromSettings(ContactCenterSettings::current());

        $open = array_values(array_filter(
            $scope['locations'],
            fn (int $id): bool => $hours->isOpen($moment, CallingHours::timezoneFor(Location::query()->find($id))),
        ));

        $openScope = ['locations' => $open, 'unrouted' => $scope['unrouted'] && $hours->isOpen($moment, CallingHours::UNROUTED_TIMEZONE), 'anywhere' => true];

        $due = $open === [] && ! $openScope['unrouted']
            ? ['count' => 0, 'since' => null]
            : $this->dueFigures($openScope, $moment);

        $held = $this->inScope(LeadCadence::query(), $scope)
            ->where('state', CadenceState::InQueue)
            ->whereNotNull('held_by_user_id')
            ->where('held_until', '>', $moment);

        $finished = $this->inScope(LeadCadence::query(), $scope)->where('state', CadenceState::FinishedNoContact);

        $heartbeat = SchedulerHeartbeat::lastRanAt(SchedulerHeartbeat::QUEUE_TICK);

        return [
            'dueNow' => $due['count'],
            'longestWaitSince' => $due['since'],
            'heldCount' => (clone $held)->count(),
            'held' => (clone $held)->with(['lead', 'holder'])->orderBy('held_at')->limit(self::LIST_LIMIT)->get(),
            'finishedCount' => (clone $finished)->count(),
            'finished' => (clone $finished)->with(['lead.location'])->orderByDesc('ended_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get(),
            'heartbeat' => $heartbeat,
            'heartbeatStale' => $heartbeat === null || $heartbeat->lt($moment->copy()->subMinutes(self::HEARTBEAT_STALE_MINUTES)),
        ];
    }

    /**
     * @param  array{locations: list<int>, unrouted: bool, anywhere: bool}  $scope
     * @return array{count: int, since: CarbonInterface|null}
     */
    private function dueFigures(array $scope, CarbonInterface $moment): array
    {
        $row = self::candidateQuery($scope, $moment)
            ->selectRaw('count(*) as total, min(c.due_at) as oldest')
            ->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'since' => ($row->oldest ?? null) === null ? null : CarbonImmutable::parse($row->oldest, 'UTC'),
        ];
    }

    /**
     * Limit cadences to Leads at the scope's Locations (or unrouted).
     *
     * @param  EloquentBuilder<LeadCadence>  $query
     * @param  array{locations: list<int>, unrouted: bool, anywhere: bool}  $scope
     * @return EloquentBuilder<LeadCadence>
     */
    private function inScope(EloquentBuilder $query, array $scope): EloquentBuilder
    {
        return $query->whereHas('lead', function (EloquentBuilder $lead) use ($scope): void {
            $lead->where(function (EloquentBuilder $where) use ($scope): void {
                $where->whereIn('location_id', $scope['locations']);

                if ($scope['unrouted']) {
                    $where->orWhereNull('location_id');
                }
            });
        });
    }
}
