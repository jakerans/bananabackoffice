<?php

namespace App\Actions\QuickBooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\QuickBooks\QuickBooksClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Disconnect a Location from its QuickBooks company
 * (docs/slice-28-quickbooks-plan.md, step 28a; decision 189, question 8):
 * QuickBooks is told to forget the keys and Backoffice removes them. The
 * profit and loss already read is kept.
 */
class DisconnectQuickBooks
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private QuickBooksClient $client) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(Location $location, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $location);

        $connection = QuickBooksConnection::query()->with('location')->where('location_id', $location->id)->first();

        if ($connection === null) {
            return;
        }

        $this->client->revoke($connection);

        DB::transaction(function () use ($location, $actor): void {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $connection = QuickBooksConnection::query()->where('location_id', $location->id)->first();

            if ($connection === null) {
                return;
            }

            $before = ['company' => $connection->company_name, 'realm_id' => $connection->realm_id];
            $connection->delete();

            $this->recordAuditEvent->handle(
                AuditAction::QuickBooksDisconnected,
                $location,
                actor: $actor,
                before: $before,
                location: $location,
            );
        });
    }
}
