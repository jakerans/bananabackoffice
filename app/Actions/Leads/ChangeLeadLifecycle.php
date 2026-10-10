<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Support\Queue\QueueTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChangeLeadLifecycle
{
    public function __construct(private QueueTransaction $queueTransaction) {}

    /**
     * Claim the caller's version atomically before validating or changing the
     * lifecycle. The write locks the row on both SQLite and MySQL; the version,
     * business change, assignment, history and audit all commit or roll back.
     * Location and assignment must still match the caller's authorization context.
     *
     * The two changes that also end a Lead's call cadence ask for
     * `$retryWhenBusy`: when the database reports a deadlock or says it is
     * busy, the transaction is run again (decision 87). Every try claims
     * against what the caller saw, never against what a failed try left in
     * memory, so a Lead that someone else really changed is still refused as
     * stale.
     *
     * @param  Closure(): bool  $change  Must read the Lead as it is when called: it may run more than once.
     */
    public function handle(Lead $lead, ?int $expectedVersion, Closure $change, bool $retryWhenBusy = false): void
    {
        $version = $expectedVersion ?? (int) $lead->lifecycle_version;
        $status = $lead->status->value;
        $locationId = $lead->location_id;
        $assigneeId = $lead->assigned_user_id;

        $transaction = function () use ($lead, $version, $status, $locationId, $assigneeId, $change): void {
            DB::transaction(function () use ($lead, $version, $status, $locationId, $assigneeId, $change): void {
                $claimed = DB::table('leads')
                    ->where('id', $lead->id)
                    ->where('lifecycle_version', $version)
                    ->where('status', $status)
                    ->where('location_id', $locationId)
                    ->where('assigned_user_id', $assigneeId)
                    ->update(['lifecycle_version' => $version + 1]);

                if ($claimed !== 1) {
                    throw ValidationException::withMessages([
                        'stale' => __('This lead was just changed by someone else, so nothing was saved. Reopen the action and review its current details.'),
                    ]);
                }

                $lead->refresh();
                if (! $change()) {
                    DB::table('leads')->where('id', $lead->id)->update(['lifecycle_version' => $version]);
                    $lead->refresh();
                }
            });
        };

        try {
            $retryWhenBusy ? $this->queueTransaction->attempt($transaction) : $transaction();
        } catch (Throwable $exception) {
            $lead->refresh();

            throw $exception;
        }
    }
}
