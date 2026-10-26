<?php

use App\Actions\Attention\RunAttentionSweep;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AttentionItem;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * The QuickBooks connection, step 28a (docs/slice-28-quickbooks-plan.md;
 * decision 189, questions 2, 3, 7 and 8). Each Location connects its own
 * QuickBooks company. QuickBooks itself is faked: nothing leaves the test.
 */

beforeEach(function () {
    config(['services.quickbooks' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'environment' => 'sandbox']]);

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);

    Http::preventStrayRequests();
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::sequence()
            ->push(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400])
            ->push(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400])
            ->push(['error' => 'invalid_grant'], 400),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['CompanyInfo' => ['CompanyName' => 'Banana Painting Lakeview LLC']]),
        'developer.api.intuit.com/*' => Http::response([], 200),
    ]);
});

/**
 * Start connecting a Location and come back from QuickBooks with its answer.
 *
 * @param  array<string, string>  $answer
 */
function returnFromQuickBooks(Location $location, array $answer = ['code' => 'one-time-code', 'realmId' => '9130350001']): TestResponse
{
    test()->get(route('quickbooks.connect', $location))->assertRedirectContains(QuickBooksClient::AUTHORIZE_URL);
    $state = session('quickbooks_pending.state');

    return test()->get(route('quickbooks.callback', [...$answer, 'state' => $state]));
}

test('the Owner connects a Location to its own QuickBooks company, approved on QuickBooks\' own page', function () {
    $this->actingAs($this->owner);

    $sent = $this->get(route('quickbooks.connect', $this->lakeview));
    $sent->assertRedirectContains('client_id=test-client')->assertRedirectContains('scope=com.intuit.quickbooks.accounting');

    $this->get(route('quickbooks.callback', ['code' => 'one-time-code', 'realmId' => '9130350001', 'state' => session('quickbooks_pending.state')]))
        ->assertRedirect(route('settings.integrations'));

    $connection = QuickBooksConnection::query()->sole();

    expect($connection)->location_id->toBe($this->lakeview->id)->realm_id->toBe('9130350001')
        ->company_name->toBe('Banana Painting Lakeview LLC')->access_token->toBe('access-1')->refresh_token->toBe('refresh-1')
        ->and(DB::table('quickbooks_connections')->value('access_token'))->not->toContain('access-1')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token'])
        ->and(AuditEvent::query()->where('action', AuditAction::QuickBooksConnected)->sole()->values_after)->toEqual(['company' => 'Banana Painting Lakeview LLC', 'realm_id' => '9130350001']);

    Http::assertSent(fn (Request $request): bool => $request->url() === QuickBooksClient::TOKEN_URL
        && $request['grant_type'] === 'authorization_code'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret')));

    $this->get(route('settings.integrations'))->assertOk()
        ->assertSee('Lakeview is connected to Banana Painting Lakeview LLC.')
        ->assertSee('Connected to Banana Painting Lakeview LLC by Olive Owner');
});

test('a return from QuickBooks that was not started here, or was declined, connects nothing', function () {
    $this->actingAs($this->owner);

    $this->get(route('quickbooks.connect', $this->lakeview));
    $this->get(route('quickbooks.callback', ['code' => 'one-time-code', 'realmId' => '9130350001', 'state' => 'someone-elses']))
        ->assertSessionHas('quickbooks_status', ['variant' => 'danger', 'text' => 'That QuickBooks sign-in has expired. Connect it again.']);

    returnFromQuickBooks($this->lakeview, ['error' => 'access_denied'])
        ->assertSessionHas('quickbooks_status', ['variant' => 'warning', 'text' => 'QuickBooks was not connected for Lakeview.']);

    expect(QuickBooksConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('one QuickBooks company connects to one Location only', function () {
    $this->actingAs($this->owner);

    returnFromQuickBooks($this->lakeview);
    returnFromQuickBooks($this->harbor)
        ->assertSessionHas('quickbooks_status', ['variant' => 'danger', 'text' => 'That QuickBooks company is already connected to Lakeview. Each Location connects its own company.']);

    expect(QuickBooksConnection::query()->pluck('location_id')->all())->toBe([$this->lakeview->id]);
});

test('the Owner disconnects a Location after confirming, and QuickBooks is told to forget it', function () {
    $this->actingAs($this->owner);
    returnFromQuickBooks($this->lakeview);

    Livewire::test('pages::settings.integrations')
        ->call('openDisconnect', $this->lakeview->id)
        ->call('disconnect')
        ->assertSee('Not connected');

    expect(QuickBooksConnection::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::QuickBooksDisconnected)->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === QuickBooksClient::REVOKE_URL && $request['token'] === 'refresh-1');
});

test('keys are renewed before they run out, and a refused renewal needs the Owner\'s attention', function () {
    $this->actingAs($this->owner);
    returnFromQuickBooks($this->lakeview);
    $connection = QuickBooksConnection::query()->with('location')->sole();

    $this->travel(2)->hours();
    app(QuickBooksClient::class)->get($connection, 'reports/ProfitAndLoss');

    expect($connection->fresh())->access_token->toBe('access-2')->refresh_token->toBe('refresh-2')->failed_at->toBeNull();

    $this->travel(2)->hours();
    expect(fn () => app(QuickBooksClient::class)->get($connection->fresh()->load('location'), 'reports/ProfitAndLoss'))->toThrow(QuickBooksUnavailable::class);
    expect($connection->fresh()->failed_at)->not->toBeNull();

    app(RunAttentionSweep::class)->handle();

    expect(AttentionItem::query()->where('rule', 'quickbooks_reconnect')->sole())
        ->user_id->toBe($this->owner->id)->severity->toBe(AttentionItem::URGENT)
        ->title->toBe('QuickBooks needs connecting again for Lakeview.');
    $this->get(route('settings.integrations'))->assertSee('Needs connecting again');
});

test('only whoever manages Locations connects, and not before the server has Intuit\'s keys', function () {
    $this->actingAs($this->manager)->get(route('settings.integrations'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('quickbooks.connect', $this->lakeview))->assertForbidden();

    config(['services.quickbooks.client_id' => null]);
    $this->actingAs($this->owner)->get(route('settings.integrations'))->assertOk()
        ->assertSee('QuickBooks is not set up on this server yet')
        ->assertDontSee('data-test="quickbooks-connect-', false);
});
