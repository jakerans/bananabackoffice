<?php

namespace App\Actions\Proposals;

use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\Proposal;
use App\Notifications\AppointmentChanged;
use App\Support\Proposals\ProposalValidity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WarnOfPendingRelease
{
    /**
     * Leads that may be due the warning: with their salesperson after an
     * appointment that was run, whose last day with them is near, with
     * something pending or with nothing presented. A wide net, read in UTC;
     * handle() decides each one by its own Location's calendar.
     *
     * A Lead with a return visit booked is not here: it shows Appointment
     * booked and is not released while that stands (decision 137, answer 4).
     *
     * @return Builder<Lead>
     */
    public static function due(CarbonInterface $moment): Builder
    {
        $near = $moment->copy()->addDays(ContactCenterSettings::PENDING_WARNING_DAYS + 1);
        $days = ContactCenterSettings::current()->proposal_valid_days;

        return Lead::query()
            ->where('status', LeadStatus::AppointmentRun)
            ->whereNotNull('assigned_user_id')
            // It has been Appointment run for nearly as long as a proposal is valid.
            ->where('status_changed_at', '<=', $near->copy()->subDays($days))
            // Nothing pending runs past the next few days: the Lead leaves when the last one expires.
            ->whereDoesntHave('proposals', fn (Builder $proposals) => self::pending($proposals)->where('valid_until', '>', $near))
            // Not already warned about a day that is still to come or only just gone.
            ->where(fn (Builder $unwarned) => $unwarned
                ->whereNull('pending_warned_for')
                ->orWhere('pending_warned_for', '<', $moment->copy()->subDays(2))
                ->orWhereHas('proposals', fn (Builder $proposals) => self::pending($proposals)->whereColumn('valid_until', '>', 'leads.pending_warned_for')));
    }

    /**
     * A Lead's proposals that are pending and count.
     *
     * @param  Builder<Proposal>  $proposals
     * @return Builder<Proposal>
     */
    private static function pending(Builder $proposals): Builder
    {
        return $proposals->counted()->where('result', ProposalResult::Pending);
    }

    /**
     * Warn one Lead's salesperson, inside the app, that the Lead is on its
     * last days with them and will then go to the Contact Center for rehash
     * (docs/slice-9-pending-rehash-plan.md, steps 9a and 9b; decision 137,
     * answers 5 and 9). Once for each last day: if the day is moved on, they are
     * warned again when the new one is near.
     *
     * Done by the timer, so nobody is recorded as having done it. Safe to
     * repeat.
     *
     * @return bool Whether the warning was sent.
     */
    public function handle(int $leadId): bool
    {
        return DB::transaction(function () use ($leadId): bool {
            // The Lead row is locked before anything else, with a write that changes nothing.
            DB::table('leads')->where('id', $leadId)->update(['id' => DB::raw('id')]);

            $lead = Lead::query()->with(['location', 'assignee'])->find($leadId);

            if ($lead === null || $lead->status !== LeadStatus::AppointmentRun || $lead->assignee === null) {
                return false;
            }

            $until = ProposalValidity::ownershipUntil($lead);

            if ($until === null || ($lead->pending_warned_for !== null && $lead->pending_warned_for->toDateString() === $until->toDateString())) {
                return false;
            }

            $left = (int) ProposalValidity::today($lead->location)->diffInDays($until);

            // Too early; or already past, which is the release's to say.
            if ($left < 0 || $left > ContactCenterSettings::PENDING_WARNING_DAYS) {
                return false;
            }

            $lead->forceFill(['pending_warned_for' => $until])->save();
            $lead->assignee->notify(new AppointmentChanged($lead->id, AppointmentChanged::PENDING_RELEASE_WARNING, AppointmentChanged::IN_APP, days: $left));

            return true;
        });
    }
}
