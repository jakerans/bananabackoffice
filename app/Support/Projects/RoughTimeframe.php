<?php

namespace App\Support\Projects;

use App\Models\Location;
use App\Models\Project;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * What a job in pre-production has and still needs
 * (docs/slice-10-pre-production-plan.md, step 10a; decision 141): the rough
 * timeframe in words, and whether the welcome call is falling behind.
 *
 * A rough timeframe is two calendar days in the Project's Location. It is
 * shown in thirds of a month, "late November to mid December", because it is
 * an expectation and not a booked date (product-architecture.md, 6.5).
 */
final class RoughTimeframe
{
    /** How many business days a job may wait for its welcome call before it is flagged (decision 141). */
    public const int WELCOME_CALL_BUSINESS_DAYS = 2;

    /** The longest note or reason kept. */
    public const int NOTE_LENGTH = 1000;

    /**
     * Today, in the Location's calendar, as a plain day.
     */
    public static function today(?Location $location): CarbonImmutable
    {
        return self::day(now()->timezone(CallingHours::timezoneFor($location))->toDateString());
    }

    /**
     * The timeframe in words: "Late November", "Early to late November",
     * "Late November to mid December". A year is said only when it is not
     * this year.
     */
    public static function words(CarbonInterface $earliest, CarbonInterface $latest, ?Location $location = null): string
    {
        $thisYear = self::today($location)->year;
        $part = fn (CarbonInterface $day): string => match (true) {
            $day->day <= 10 => __('early'),
            $day->day <= 20 => __('mid'),
            default => __('late'),
        };
        $year = fn (CarbonInterface $day): string => $day->year === $thisYear ? '' : ' '.$day->year;

        if ($earliest->format('Y-m') === $latest->format('Y-m')) {
            $range = $part($earliest) === $part($latest) ? $part($earliest) : __(':from to :to', ['from' => $part($earliest), 'to' => $part($latest)]);

            return ucfirst($range.' '.$latest->format('F').$year($latest));
        }

        // The year is said once, at the end, when both ends are in the same year.
        $from = $part($earliest).' '.$earliest->format('F').($earliest->year === $latest->year ? '' : $year($earliest));

        return ucfirst(__(':from to :to', ['from' => $from, 'to' => $part($latest).' '.$latest->format('F').$year($latest)]));
    }

    /**
     * The words for the timeframe a Project has now, or nothing when it has none.
     */
    public static function of(Project $project): ?string
    {
        return $project->timeframe_earliest === null || $project->timeframe_latest === null
            ? null
            : self::words($project->timeframe_earliest, $project->timeframe_latest, $project->location);
    }

    /**
     * The moment a job that nobody has called starts to count as falling
     * behind: two business days after its Production Manager took it or was
     * given it, in the Location's time. Monday to Friday; public holidays are
     * not known and count as working days. Nothing when it is not waiting
     * for a call.
     */
    public static function welcomeCallDueAt(Project $project): ?CarbonImmutable
    {
        if ($project->isCancelled() || $project->welcome_call_on !== null || $project->production_manager_id === null || $project->production_manager_assigned_at === null) {
            return null;
        }

        return CarbonImmutable::instance($project->production_manager_assigned_at)
            ->timezone(CallingHours::timezoneFor($project->location))
            ->addWeekdays(self::WELCOME_CALL_BUSINESS_DAYS)
            ->utc();
    }

    /**
     * Whether the welcome call is falling behind now (decision 141).
     */
    public static function welcomeCallIsBehind(Project $project): bool
    {
        $due = self::welcomeCallDueAt($project);

        return $due !== null && now()->greaterThanOrEqualTo($due);
    }

    /**
     * Read a day a person typed, as "2026-11-24".
     *
     * @throws ValidationException
     */
    public static function typed(?string $typed, string $field, string $message): CarbonImmutable
    {
        $typed = trim((string) $typed);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $typed, 'UTC') : null;

        // A day that does not exist, like the 31st of a short month, does not read back the same.
        if (! $day instanceof CarbonImmutable || $day->toDateString() !== $typed) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $day;
    }

    /**
     * A plain calendar day from "2026-11-24".
     */
    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }
}
