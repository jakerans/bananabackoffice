<?php

namespace App\Actions\Locations;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Queue\EndQueueHold;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Support\Queue\QueueTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateLocation
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private EndQueueHold $endQueueHold,
        private QueueTransaction $queueTransaction,
    ) {}

    /**
     * Take a location out of operation.
     *
     * Records at a deactivated location cannot be opened by anyone, so a
     * location that still has active Leads is refused: they must first be
     * transferred to another location or returned to central. Nothing overrides
     * this. Once Projects exist, active Projects must block it the same way.
     *
     * No grant covers a deactivated location, so every hold from the call
     * queue on a Lead here is ended in the same transaction. The refusal above
     * means there is normally none; this is for a Lead that reaches the
     * location while it is being deactivated and for state a crash left behind.
     *
     * If the database reports a deadlock or says it is busy, the location is
     * read again and the whole deactivation is tried again, checks included
     * (decision 87).
     *
     * @throws ValidationException
     */
    public function handle(Location $location): void
    {
        $this->queueTransaction->attempt(
            function () use ($location): void {
                $this->deactivate($location);
            },
            function () use ($location): void {
                $location->refresh();
            },
        );
    }

    /**
     * @throws ValidationException
     */
    private function deactivate(Location $location): void
    {
        DB::transaction(function () use ($location): void {
            // The Location row is locked before anything else, with a write
            // that changes nothing: the order a queue claim relies on.
            DB::table('locations')->where('id', $location->id)->update(['id' => DB::raw('id')]);

            $activeLeads = $location->leads()->active()->count();

            if ($activeLeads > 0) {
                throw ValidationException::withMessages([
                    'deactivation' => trans_choice(
                        '{1} :name still has 1 active lead. Transfer it to another location or return it to central, then deactivate.|[2,*] :name still has :count active leads. Transfer them to another location or return them to central, then deactivate.',
                        $activeLeads,
                        ['name' => $location->name, 'count' => $activeLeads],
                    ),
                ]);
            }

            $location->update(['is_active' => false]);

            if ($location->wasChanged('is_active')) {
                $this->recordAuditEvent->handle(AuditAction::LocationDeactivated, $location, location: $location);
            }

            $this->endQueueHold->atLocation($location);
        });
    }
}
