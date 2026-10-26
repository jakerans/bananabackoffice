<?php

namespace App\Actions\QuickBooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Connect a Location to its own QuickBooks Online company, once the Owner
 * has approved it on QuickBooks' own page (docs/slice-28-quickbooks-plan.md,
 * step 28a; decision 189, questions 2, 7 and 8). Whoever manages Locations.
 * One company, one Location. Connecting again replaces the keys.
 */
class ConnectQuickBooks
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private QuickBooksClient $client) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws QuickBooksUnavailable
     */
    public function handle(Location $location, string $code, string $realmId, User $actor): QuickBooksConnection
    {
        Gate::forUser($actor)->authorize('update', $location);

        if (preg_match('/^\d{1,32}$/', $realmId) !== 1) {
            throw ValidationException::withMessages(['quickbooks' => __('QuickBooks did not say which company was approved. Connect it again.')]);
        }

        $this->ensureNotElsewhere($location, $realmId);

        $tokens = $this->client->exchange($code);
        $companyName = $this->client->companyName($realmId, $tokens['access_token']);

        return DB::transaction(function () use ($location, $realmId, $tokens, $companyName, $actor): QuickBooksConnection {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $this->ensureNotElsewhere($location, $realmId);

            $connection = QuickBooksConnection::query()->where('location_id', $location->id)->first() ?? new QuickBooksConnection;
            $before = $connection->exists ? ['company' => $connection->company_name, 'realm_id' => $connection->realm_id] : [];

            $connection->forceFill([
                'location_id' => $location->id,
                'realm_id' => $realmId,
                'company_name' => $companyName,
                ...QuickBooksClient::stored($tokens),
                'connected_by' => $actor->id,
                'connected_by_name' => $actor->name,
                'connected_at' => now(),
                'failed_at' => null,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::QuickBooksConnected,
                $location,
                actor: $actor,
                before: $before,
                after: ['company' => $companyName, 'realm_id' => $realmId],
                location: $location,
            );

            return $connection;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureNotElsewhere(Location $location, string $realmId): void
    {
        $elsewhere = QuickBooksConnection::query()->with('location')->where('realm_id', $realmId)->where('location_id', '!=', $location->id)->first();

        if ($elsewhere !== null) {
            throw ValidationException::withMessages(['quickbooks' => __('That QuickBooks company is already connected to :location. Each Location connects its own company.', ['location' => $elsewhere->location->name])]);
        }
    }
}
