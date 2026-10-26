<?php

use App\Actions\Locations\ChangeSalesTarget;
use App\Actions\Marketing\RecordMarketingSpend;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\LocationSalesTarget;
use App\Models\MarketingSpendEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Marketing spend and sales targets, step 25a
 * (docs/slice-25-marketing-and-executive-boards-plan.md; decision 183,
 * questions 2, 3 and 6).
 *
 * It is Monday 26 October 2026 at Lakeview. Morgan manages it, Fran is its
 * Finance, Casey works the Contact Center. Google Ads is a Lead Source.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->casey = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->google = LeadSource::factory()->create(['name' => 'Google Ads']);
});

function spend(): RecordMarketingSpend
{
    return app(RecordMarketingSpend::class);
}

test('spend is recorded on a Lead Source at a Location for a month, and corrected with why, never changed', function () {
    $entry = spend()->record($this->lakeview, $this->google->id, '2026-10', '1,250', 'October invoice.', $this->manager);

    expect($entry->amount_cents)->toBe(125000)
        ->and($entry->lead_source_name)->toBe('Google Ads')
        ->and(AuditEvent::query()->where('action', AuditAction::MarketingSpendRecorded)->count())->toBe(1);

    expect(fn () => spend()->correct($entry->id, 'deduct', '250', ' ', $this->fran))->toThrow(ValidationException::class, 'Say why it is corrected.');
    $correction = spend()->correct($entry->id, 'deduct', '250', 'Credit from Google.', $this->fran);

    expect($correction->amount_cents)->toBe(-25000)
        ->and($correction->corrects_id)->toBe($entry->id)
        ->and((int) MarketingSpendEntry::query()->sum('amount_cents'))->toBe(100000);
    expect(fn () => $entry->fresh()->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
});

test('spend needs a source, a month that has begun and an amount; the Contact Center does not record it', function () {
    expect(fn () => spend()->record($this->lakeview, null, '2026-10', '100', null, $this->manager))->toThrow(ValidationException::class, 'Choose the Lead Source it was spent on.');
    expect(fn () => spend()->record($this->lakeview, $this->google->id, '2026-11', '100', null, $this->manager))->toThrow(ValidationException::class, 'Choose a month that has begun.');
    expect(fn () => spend()->record($this->lakeview, $this->google->id, '2026-10', 'lots', null, $this->manager))->toThrow(ValidationException::class, 'Give the amount in dollars');
    expect(fn () => spend()->record($this->lakeview, $this->google->id, '2026-10', '100', null, $this->casey))->toThrow(AuthorizationException::class);
});

test('a Location\'s sales target holds from its month on; earlier months keep theirs', function () {
    app(ChangeSalesTarget::class)->handle($this->lakeview, '200,000', '2026-09', $this->owner);
    app(ChangeSalesTarget::class)->handle($this->lakeview, '250,000', '2026-11', $this->owner);

    expect(LocationSalesTarget::for($this->lakeview->id, '2026-08'))->toBeNull()
        ->and(LocationSalesTarget::for($this->lakeview->id, '2026-10'))->toBe(20000000)
        ->and(LocationSalesTarget::for($this->lakeview->id, '2026-12'))->toBe(25000000);
    expect(fn () => app(ChangeSalesTarget::class)->handle($this->lakeview, '1', '2026-11', $this->manager))->toThrow(AuthorizationException::class);
});

test('spend is added and corrected on its page, and the sales target is set in Settings > Locations', function () {
    Livewire::actingAs($this->fran)->test('pages::marketing.spend')
        ->call('openSpend')
        ->set('spendLocation', $this->lakeview->id)
        ->set('spendSource', $this->google->id)
        ->set('spendAmount', '900')
        ->call('saveSpend')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['October 2026', '$900', 'Google Ads · Lakeview']);

    $this->actingAs($this->casey)->get(route('marketing.performance.spend'))->assertForbidden();

    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->call('editSalesTarget', $this->lakeview->id)
        ->set('salesTargetAmount', '180,000')
        ->call('saveSalesTarget')
        ->assertHasNoErrors()
        ->assertSee('$180,000');
});
