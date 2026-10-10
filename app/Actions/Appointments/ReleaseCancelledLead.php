<?php

namespace App\Actions\Appointments;

use App\Enums\LeadStatus;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Notifications\AppointmentChanged;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReleaseCancelledLead
{
    public function __construct(private ReturnLeadFromSales $returnLeadFromSales) {}

    /**
     * Leads whose appointment was cancelled and that have waited with their
     * salesperson for the whole waiting period without being booked again
     * (rulebook, rule 8; decisions 116 and 119).
     *
     * The wait is counted from when the Lead became Appointment cancelled:
     * the cancellation, or the moment its salesperson took it back.
     *
     * @return Builder<Lead>
     */
    public static function due(CarbonInterface $moment, int $days): Builder
    {
        return Lead::query()
            ->where('status', LeadStatus::AppointmentCancelled)
            ->where('status_changed_at', '<=', $moment->copy()->subDays($days));
    }

    /**
     * Leads whose salesperson should be warned now: the release is five days
     * away or less, and they have not been warned since the wait began
     * (decision 118). A waiting period of five days or fewer has no warning.
     *
     * @return Builder<Lead>
     */
    public static function dueForWarning(CarbonInterface $moment, int $days): Builder
    {
        $query = Lead::query()->where('status', LeadStatus::AppointmentCancelled)->whereNotNull('assigned_user_id');

        if ($days <= ContactCenterSettings::RELEASE_WARNING_DAYS) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('status_changed_at', '<=', $moment->copy()->subDays($days - ContactCenterSettings::RELEASE_WARNING_DAYS))
            ->where(fn (Builder $unwarned) => $unwarned->whereNull('release_warned_at')->orWhereColumn('release_warned_at', '<', 'status_changed_at'));
    }

    /**
     * How many days a cancelled Lead still has with its salesperson, counting
     * a part of a day as a day. Zero once it is due.
     */
    public static function daysLeft(Lead $lead, int $days, CarbonInterface $moment): int
    {
        if ($lead->status_changed_at === null) {
            return $days;
        }

        $seconds = $lead->status_changed_at->copy()->addDays($days)->getTimestamp() - $moment->getTimestamp();

        return max(0, (int) ceil($seconds / 86400));
    }

    /**
     * Release one Lead to the Contact Center if it is still due. It is then
     * as a Lead a manager sent back (decisions 97, 103 and 117), still counted
     * as issued (rule 8), and remembers who it was released from so that
     * salesperson can take it back (rule 12). They are told inside the app.
     *
     * Done by the timer, so nobody is recorded as having done it. Safe to
     * repeat: a Lead that was booked, taken back or released a moment ago is
     * left alone.
     *
     * @return bool Whether the Lead was released.
     */
    public function release(int $leadId): bool
    {
        // Read before the Lead is locked: the order every queue change takes them in.
        $days = ContactCenterSettings::current()->cancelled_release_days;

        return DB::transaction(function () use ($leadId, $days): bool {
            // The Lead row is locked before anything else, with a write that changes nothing.
            DB::table('leads')->where('id', $leadId)->update(['id' => DB::raw('id')]);

            $lead = self::due(now(), $days)->with(['location', 'assignee'])->find($leadId);

            if ($lead === null || $lead->hasUpcomingAppointment()) {
                return false;
            }

            $lead->lifecycle_version++;

            $this->returnLeadFromSales->handle(
                $lead,
                null,
                ReturnLeadFromSales::RELEASED,
                trans_choice('{1} Not booked again within 1 day of the cancellation.|[2,*] Not booked again within :count days of the cancellation.', $days, ['count' => $days]),
                true,
                $lead->location,
                $lead->assignee,
            );

            return true;
        });
    }

    /**
     * Warn one Lead's salesperson that it will be released soon, once.
     *
     * @return bool Whether the warning was sent.
     */
    public function warn(int $leadId): bool
    {
        $days = ContactCenterSettings::current()->cancelled_release_days;

        return DB::transaction(function () use ($leadId, $days): bool {
            DB::table('leads')->where('id', $leadId)->update(['id' => DB::raw('id')]);

            $lead = self::dueForWarning(now(), $days)->with('assignee')->find($leadId);

            if ($lead === null || $lead->assignee === null) {
                return false;
            }

            $left = self::daysLeft($lead, $days, now());

            // A Lead that is due now is released by this same tick, which tells them that instead.
            if ($left === 0) {
                return false;
            }

            $lead->forceFill(['release_warned_at' => now()])->save();
            $lead->assignee->notify(new AppointmentChanged($lead->id, AppointmentChanged::RELEASE_WARNING, AppointmentChanged::IN_APP, days: $left));

            return true;
        });
    }
}
