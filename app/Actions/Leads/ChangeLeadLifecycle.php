<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChangeLeadLifecycle
{
    /**
     * Claim the caller's version atomically before validating or changing the
     * lifecycle. The write locks the row on both SQLite and MySQL; the version,
     * business change, assignment, history and audit all commit or roll back.
     * Location and assignment must still match the caller's authorization context.
     *
     * @param  Closure(): bool  $change
     */
    public function handle(Lead $lead, ?int $expectedVersion, Closure $change): void
    {
        $version = $expectedVersion ?? (int) $lead->lifecycle_version;

        try {
            DB::transaction(function () use ($lead, $version, $change): void {
                $claimed = DB::table('leads')
                    ->where('id', $lead->id)
                    ->where('lifecycle_version', $version)
                    ->where('status', $lead->status->value)
                    ->where('location_id', $lead->location_id)
                    ->where('assigned_user_id', $lead->assigned_user_id)
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
        } catch (Throwable $exception) {
            $lead->refresh();

            throw $exception;
        }
    }
}
