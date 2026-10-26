<?php

namespace App\Http\Controllers;

use App\Actions\QuickBooks\ConnectQuickBooks;
use App\Models\Location;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a Location to its QuickBooks company (docs/slice-28-quickbooks-plan.md,
 * step 28a; decision 189): the Owner is sent to QuickBooks' own page to sign
 * in with that Location's company and approve Backoffice, and QuickBooks
 * sends them back here. A one-time code ties the return to this person and
 * this Location, so nobody else's approval can be slipped in.
 */
class QuickBooksController extends Controller
{
    private const string PENDING = 'quickbooks_pending';

    /**
     * @throws AuthorizationException
     */
    public function connect(Request $request, Location $location, QuickBooksClient $client): RedirectResponse
    {
        Gate::authorize('update', $location);

        if (! QuickBooksClient::isConfigured()) {
            return $this->back('danger', __('QuickBooks is not set up on this server yet.'));
        }

        $state = Str::random(40);
        $request->session()->put(self::PENDING, ['state' => $state, 'location' => $location->id]);

        return redirect()->away($client->authorizeUrl($state));
    }

    public function callback(Request $request, ConnectQuickBooks $connectQuickBooks): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING);
        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return $this->back('danger', __('That QuickBooks sign-in has expired. Connect it again.'));
        }

        $location = Location::query()->findOrFail((int) $pending['location']);
        $code = $request->query('code');
        $realmId = $request->query('realmId');

        if (! is_string($code) || $code === '' || ! is_string($realmId)) {
            return $this->back('warning', __('QuickBooks was not connected for :location.', ['location' => $location->name]));
        }

        try {
            $connection = $connectQuickBooks->handle($location, $code, $realmId, $request->user());
        } catch (ValidationException $invalid) {
            return $this->back('danger', (string) collect($invalid->errors())->flatten()->first());
        } catch (QuickBooksUnavailable $unavailable) {
            return $this->back('danger', $unavailable->getMessage());
        }

        return $this->back('success', __(':location is connected to :company.', ['location' => $location->name, 'company' => $connection->company_name ?? __('its QuickBooks company')]));
    }

    private function back(string $variant, string $text): RedirectResponse
    {
        return redirect()->route('settings.integrations')->with('quickbooks_status', ['variant' => $variant, 'text' => $text]);
    }
}
