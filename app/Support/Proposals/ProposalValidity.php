<?php

namespace App\Support\Proposals;

use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\ProposalResult;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * The clock on a pending proposal (docs/slice-9-pending-rehash-plan.md, step
 * 9a; product-architecture.md, 5.5; decision 137).
 *
 * A proposal is valid until a day, in its Location's own calendar: so many
 * days after it was last presented. A Lead stays with its salesperson until
 * the last of its pending proposals has expired.
 *
 * Every day here is a plain calendar day, held as midnight UTC so two of them
 * can be compared and counted between whatever the Location's timezone is.
 */
final class ProposalValidity
{
    /** How far ahead a salesperson may move a proposal's day on their own; a manager may go further. */
    public const int SALESPERSON_MAX_DAYS = 30;

    /**
     * Today, in the Location's calendar.
     */
    public static function today(?Location $location): CarbonImmutable
    {
        return self::day(now()->timezone(CallingHours::timezoneFor($location))->toDateString());
    }

    /**
     * The day a proposal is valid until when it was presented at an
     * appointment: the number of days in the settings after that
     * appointment's day. One recorded late is valid until today at the
     * earliest, so it is never expired the moment it is typed in.
     */
    public static function afterPresentation(Lead $lead, CarbonInterface $presentedAt): CarbonImmutable
    {
        $presented = self::day($presentedAt->copy()->timezone(CallingHours::timezoneFor($lead->location))->toDateString());

        return $presented->addDays(ContactCenterSettings::current()->proposal_valid_days)->max(self::today($lead->location));
    }

    /**
     * The day a proposal is valid until when its clock starts over today: one
     * that a correction made pending again.
     */
    public static function fromToday(Lead $lead): CarbonImmutable
    {
        return self::today($lead->location)->addDays(ContactCenterSettings::current()->proposal_valid_days);
    }

    /**
     * How many days a proposal has left, counting today: 0 on its last day,
     * below zero once it has expired. Nothing when it has no day.
     */
    public static function daysLeft(Proposal $proposal, ?Location $location): ?int
    {
        $until = self::of($proposal);

        return $until === null ? null : (int) self::today($location)->diffInDays($until);
    }

    /**
     * The day a proposal is valid until, as a plain day.
     */
    public static function of(Proposal $proposal): ?CarbonImmutable
    {
        return $proposal->valid_until === null ? null : self::day($proposal->valid_until->toDateString());
    }

    /**
     * The latest "valid until" of a Lead's pending proposals. Nothing when it
     * has nothing pending.
     */
    public static function latestPending(Lead $lead): ?CarbonImmutable
    {
        $latest = $lead->proposals()->counted()->where('result', ProposalResult::Pending)->max('valid_until');

        return $latest === null ? null : self::day(substr((string) $latest, 0, 10));
    }

    /**
     * The last day a Lead that shows Appointment run stays with its
     * salesperson. After it, the Lead goes to the Contact Center for rehash
     * (decision 137, answers 2 and 5). Nothing for a Lead in any other
     * status: one that is booked, sold or lost is not leaving.
     *
     * - With something pending: until the last pending proposal expires.
     * - With nothing pending: the number of days a proposal is valid, counted
     *   from the day the Lead came to be as it is: its appointment was marked
     *   run, or its last proposal was given its result.
     * - Never sooner than that number of days after it last became
     *   Appointment run. A Lead that has just come back to a salesperson, from
     *   a return visit or because they took it back, is not released at once
     *   because its proposals expired while it was away. An offer's "valid
     *   until" and the salesperson's ownership are separate (plan, 5.5).
     */
    public static function ownershipUntil(Lead $lead): ?CarbonImmutable
    {
        if ($lead->status !== LeadStatus::AppointmentRun) {
            return null;
        }

        $pending = self::latestPending($lead);
        $since = CarbonImmutable::instance($lead->status_changed_at ?? now());

        if ($pending === null) {
            $answered = $lead->proposals()->counted()->max('result_at');

            if ($answered !== null) {
                $since = $since->max(CarbonImmutable::parse((string) $answered, 'UTC'));
            }
        }

        $floor = self::day($since->timezone(CallingHours::timezoneFor($lead->location))->toDateString())
            ->addDays(ContactCenterSettings::current()->proposal_valid_days);

        return $pending === null ? $floor : $pending->max($floor);
    }

    /**
     * Read a day a person chose for a proposal that is valid until $current
     * now. A day that is not changed is taken as it is. A new one must be
     * today or later, and a salesperson may go only so far ahead on their own
     * (decision 137, answer 3).
     *
     * @throws ValidationException
     */
    public static function chosen(Lead $lead, ?string $typed, ?CarbonImmutable $current, User $actor, string $field = 'proposalValidUntil'): CarbonImmutable
    {
        $typed = trim((string) $typed);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $typed, 'UTC') : null;

        // A day that does not exist, like the 31st of a short month, does not read back the same.
        if (! $day instanceof CarbonImmutable || $day->toDateString() !== $typed) {
            throw ValidationException::withMessages([$field => __('Choose the day the estimate is valid until.')]);
        }

        if ($current !== null && $day->equalTo($current)) {
            return $day;
        }

        $today = self::today($lead->location);

        if ($day->lessThan($today)) {
            throw ValidationException::withMessages([$field => __('Choose today or a later day.')]);
        }

        $furthest = $today->addDays(self::SALESPERSON_MAX_DAYS);

        if ($day->greaterThan($furthest) && ! ($lead->location !== null && $actor->hasPermissionAt(Permission::ManageAppointments, $lead->location))) {
            throw ValidationException::withMessages([
                $field => __('You can move it up to :days days ahead, to :day. A manager can set a later day.', ['days' => self::SALESPERSON_MAX_DAYS, 'day' => $furthest->format('M j, Y')]),
            ]);
        }

        return $day;
    }

    /**
     * A day as people read it, with how long is left: "Valid until Tue, Oct
     * 20 · 5 days left", or "Expired 2 days ago, Sun, Oct 11".
     */
    public static function label(CarbonImmutable $until, ?Location $location): string
    {
        $left = (int) self::today($location)->diffInDays($until);
        $day = $until->format('D, M j');

        return match (true) {
            $left < 0 => trans_choice('{1} Expired yesterday, :day|[2,*] Expired :count days ago, :day', -$left, ['count' => -$left, 'day' => $day]),
            $left === 0 => __('Valid until today, :day · last day', ['day' => $day]),
            default => trans_choice('{1} Valid until :day · 1 day left|[2,*] Valid until :day · :count days left', $left, ['count' => $left, 'day' => $day]),
        };
    }

    /**
     * A plain calendar day from "2026-10-20".
     */
    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }
}
