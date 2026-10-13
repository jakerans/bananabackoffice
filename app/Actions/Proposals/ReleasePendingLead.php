<?php

namespace App\Actions\Proposals;

use App\Actions\Appointments\ReturnLeadFromSales;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Support\Proposals\ProposalValidity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReleasePendingLead
{
    public function __construct(private ReturnLeadFromSales $returnLeadFromSales) {}

    /**
     * Leads that may be due to leave their salesperson for Rehash: they show
     * Appointment run, have done for about as long as a proposal is valid,
     * and nothing pending on them is valid past tomorrow. A wide net, read in
     * UTC; release() decides each one by its own Location's calendar.
     *
     * A Lead with a return visit booked shows Appointment booked and is not
     * here (decision 137, answer 4). Nor is one that is sold or lost.
     *
     * @return Builder<Lead>
     */
    public static function due(CarbonInterface $moment): Builder
    {
        $days = ContactCenterSettings::current()->proposal_valid_days;

        return Lead::query()
            ->where('status', LeadStatus::AppointmentRun)
            ->where('status_changed_at', '<=', $moment->copy()->subDays(max($days - 1, 0)))
            ->whereDoesntHave('proposals', fn (Builder $proposals) => $proposals
                ->counted()
                ->where('result', ProposalResult::Pending)
                ->where('valid_until', '>', $moment->copy()->addDay()));
    }

    /**
     * Release one Lead to the Contact Center's Rehash queue if the last day it
     * stays with its salesperson has passed (docs/slice-9-pending-rehash-plan.md,
     * step 9b; product-architecture.md, 5.5 and 20; decision 137).
     *
     * It is then as a cancelled Lead the timer released (rulebook, rule 8): it
     * shows Qualified with nobody assigned, still counts as issued, keeps its
     * proposals as they are, and remembers who it was released from so that
     * salesperson can take it back (rule 12). They are told inside the app.
     * The call center's card says what was pitched.
     *
     * Done by the timer, so nobody is recorded as having done it. Safe to
     * repeat: a Lead that was booked, sold, lost or given more time a moment
     * ago is left alone.
     *
     * @return bool Whether the Lead was released.
     */
    public function release(int $leadId): bool
    {
        return DB::transaction(function () use ($leadId): bool {
            // The Lead row is locked before anything else, with a write that changes nothing.
            DB::table('leads')->where('id', $leadId)->update(['id' => DB::raw('id')]);

            $lead = Lead::query()->with(['location', 'assignee'])->find($leadId);

            if ($lead === null || $lead->status !== LeadStatus::AppointmentRun || $lead->hasUpcomingAppointment()) {
                return false;
            }

            $until = ProposalValidity::ownershipUntil($lead);

            if ($until === null || ! ProposalValidity::today($lead->location)->greaterThan($until)) {
                return false;
            }

            $pending = ProposalValidity::latestPending($lead);

            $note = match (true) {
                $pending !== null => __('Nothing was sold, lost or booked before the last pending proposal expired on :day.', ['day' => $pending->format('M j, Y')]),
                $lead->proposals()->counted()->exists() => __('Nothing is pending and nothing was booked by :day.', ['day' => $until->format('M j, Y')]),
                default => __('Nothing was presented and nothing was booked by :day.', ['day' => $until->format('M j, Y')]),
            };

            $lead->lifecycle_version++;

            $this->returnLeadFromSales->handle($lead, null, ReturnLeadFromSales::RELEASED_PENDING, $note, true, $lead->location, $lead->assignee);

            return true;
        });
    }
}
