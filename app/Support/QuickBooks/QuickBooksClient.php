<?php

namespace App\Support\QuickBooks;

use App\Models\QuickBooksConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Backoffice's side of QuickBooks Online (docs/slice-28-quickbooks-plan.md,
 * step 28a; decision 189, questions 3 and 8): the sign-in that lets the
 * Owner approve a Location's company on QuickBooks' own page, renewing its
 * keys, and reading from it. It asks for accounting access only, and this
 * slice only reads. Keys are never logged or shown.
 */
final class QuickBooksClient
{
    public const string AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';

    public const string TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public const string REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    public const string SCOPE = 'com.intuit.quickbooks.accounting';

    /** QuickBooks' API version Backoffice reads with. */
    public const int MINOR_VERSION = 75;

    /** Keys are renewed when they have less than this long left. */
    private const int RENEW_SECONDS = 300;

    /**
     * Whether this server has the app's keys, so connecting is switched on.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.quickbooks.client_id')) && filled(config('services.quickbooks.client_secret'));
    }

    public static function isSandbox(): bool
    {
        return config('services.quickbooks.environment') !== 'production';
    }

    public function redirectUri(): string
    {
        return route('quickbooks.callback');
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('services.quickbooks.client_id'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * Trade the code QuickBooks sent back for the company's keys.
     *
     * @return array{access_token: string, refresh_token: string, access_expires_in: int, refresh_expires_in: int|null}
     *
     * @throws QuickBooksUnavailable
     */
    public function exchange(string $code): array
    {
        return $this->tokens(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri()]);
    }

    /**
     * Renew a connection's keys. QuickBooks may give a new renewal key each
     * time; it is always kept. When QuickBooks refuses, the connection is
     * marked as needing to be connected again.
     *
     * @throws QuickBooksUnavailable
     */
    public function renew(QuickBooksConnection $connection): void
    {
        try {
            $tokens = $this->tokens(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
        } catch (QuickBooksRefused $refused) {
            $connection->forceFill(['failed_at' => now()])->save();

            throw $refused;
        }

        $connection->forceFill([
            ...self::stored($tokens),
            'failed_at' => null,
        ])->save();
    }

    /**
     * Read from a connection's company: "reports/ProfitAndLoss",
     * "companyinfo/{realm}".
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     *
     * @throws QuickBooksUnavailable
     */
    public function get(QuickBooksConnection $connection, string $path, array $query = []): array
    {
        if ($connection->failed_at !== null) {
            throw new QuickBooksUnavailable(__('QuickBooks needs connecting again for :location.', ['location' => $connection->location->name]));
        }

        if ($connection->access_token_expires_at->lessThanOrEqualTo(now()->addSeconds(self::RENEW_SECONDS))) {
            $this->renew($connection);
        }

        $response = $this->read($connection, $path, $query);

        if ($response->status() === 401) {
            $this->renew($connection);
            $response = $this->read($connection, $path, $query);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new QuickBooksUnavailable(__('QuickBooks did not answer for :location (:status).', ['location' => $connection->location->name, 'status' => $response->status()]));
        }

        return $response->json();
    }

    /**
     * The company's name, read with keys just given.
     */
    public function companyName(string $realmId, string $accessToken): ?string
    {
        try {
            $response = $this->api()->withToken($accessToken)->get($this->apiBase()."/v3/company/{$realmId}/companyinfo/{$realmId}", ['minorversion' => self::MINOR_VERSION]);
        } catch (ConnectionException) {
            return null;
        }

        $name = $response->successful() ? $response->json('CompanyInfo.CompanyName') : null;

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 250) : null;
    }

    /**
     * Tell QuickBooks to forget a connection's keys. Best effort: they are
     * removed from Backoffice whatever QuickBooks answers.
     */
    public function revoke(QuickBooksConnection $connection): void
    {
        try {
            $this->app()->asJson()->post(self::REVOKE_URL, ['token' => $connection->refresh_token]);
        } catch (ConnectionException) {
            // Removed from Backoffice all the same.
        }
    }

    /**
     * What a connection keeps from a set of keys.
     *
     * @param  array{access_token: string, refresh_token: string, access_expires_in: int, refresh_expires_in: int|null}  $tokens
     * @return array{access_token: string, refresh_token: string, access_token_expires_at: CarbonImmutable, refresh_token_expires_at: CarbonImmutable|null}
     */
    public static function stored(array $tokens): array
    {
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'access_token_expires_at' => now()->addSeconds($tokens['access_expires_in']),
            'refresh_token_expires_at' => $tokens['refresh_expires_in'] === null ? null : now()->addSeconds($tokens['refresh_expires_in']),
        ];
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string, access_expires_in: int, refresh_expires_in: int|null}
     *
     * @throws QuickBooksUnavailable
     */
    private function tokens(array $form): array
    {
        try {
            $response = $this->app()->asForm()->post(self::TOKEN_URL, $form);
        } catch (ConnectionException) {
            throw new QuickBooksUnavailable(__('QuickBooks could not be reached. Try again in a moment.'));
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        if (! $response->successful() || ! is_string($access) || ! is_string($refresh)) {
            throw new QuickBooksRefused(__('QuickBooks did not approve the connection. Connect it again.'));
        }

        $refreshExpires = $response->json('x_refresh_token_expires_in');

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'access_expires_in' => (int) ($response->json('expires_in') ?? 3600),
            'refresh_expires_in' => is_numeric($refreshExpires) ? (int) $refreshExpires : null,
        ];
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws QuickBooksUnavailable
     */
    private function read(QuickBooksConnection $connection, string $path, array $query): Response
    {
        try {
            return $this->api()->withToken($connection->access_token)->get(
                $this->apiBase().'/v3/company/'.$connection->realm_id.'/'.$path,
                [...$query, 'minorversion' => (string) self::MINOR_VERSION],
            );
        } catch (ConnectionException) {
            throw new QuickBooksUnavailable(__('QuickBooks could not be reached for :location. Try again in a moment.', ['location' => $connection->location->name]));
        }
    }

    /**
     * Requests signed with the app's own keys.
     */
    private function app(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
            ->acceptJson()
            ->timeout(20);
    }

    private function api(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }

    private function apiBase(): string
    {
        return self::isSandbox() ? 'https://sandbox-quickbooks.api.intuit.com' : 'https://quickbooks.api.intuit.com';
    }
}
