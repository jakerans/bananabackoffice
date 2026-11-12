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

        // Started from the Location's checklist, every answer goes back there (decision 226).
        $setup = $request->boolean('setup') ? $location : null;

        if (! QuickBooksClient::isConfigured()) {
            return $this->back('danger', __('QuickBooks is not set up on this server yet.'), $setup);
        }

        $state = Str::random(40);
        $request->session()->put(self::PENDING, ['state' => $state, 'location' => $location->id, 'setup' => $setup !== null]);

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
        $setup = ($pending['setup'] ?? false) === true ? $location : null;
        $code = $request->query('code');
        $realmId = $request->query('realmId');

        if (! is_string($code) || $code === '' || ! is_string($realmId)) {
            return $this->back('warning', __('QuickBooks was not connected for :location.', ['location' => $location->name]), $setup);
        }

        try {
            $connection = $connectQuickBooks->handle($location, $code, $realmId, $request->user());
        } catch (ValidationException $invalid) {
            return $this->back('danger', (string) collect($invalid->errors())->flatten()->first(), $setup);
        } catch (QuickBooksUnavailable $unavailable) {
            return $this->back('danger', $unavailable->getMessage(), $setup);
        }

        return $this->back('success', __(':location is connected to :company.', ['location' => $location->name, 'company' => $connection->company_name ?? __('its QuickBooks company')]), $setup);
    }

    /**
     * Back to Integrations, or to the Location's checklist when connecting began there.
     */
    private function back(string $variant, string $text, ?Location $setup = null): RedirectResponse
    {
        $to = $setup === null ? redirect()->route('settings.integrations') : redirect()->route('settings.locations.setup', $setup);

        return $to->with('quickbooks_status', ['variant' => $variant, 'text' => $text]);
    }
}
