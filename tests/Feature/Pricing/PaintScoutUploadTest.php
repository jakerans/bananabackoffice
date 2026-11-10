<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * Import from PaintScout on Settings > Pricing Settings, step 37a
 * (docs/slice-37-location-setup-plan.md; decision 220): the saved settings
 * file is uploaded instead of being put on the server. The sample files hold
 * made-up prices and terms only.
 */

/**
 * A saved PaintScout settings file with the sample prices and the sample terms.
 */
function paintScoutUpload(?string $content = null): UploadedFile
{
    $settings = json_decode((string) file_get_contents(base_path('tests/Fixtures/paintscout-settings-sample.json')), true);
    $terms = json_decode((string) file_get_contents(base_path('tests/Fixtures/paintscout-terms-sample.json')), true);

    return UploadedFile::fake()->createWithContent('paintscout-settings.json', $content ?? json_encode(array_replace_recursive($settings, $terms)));
}

beforeEach(function () {
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
});

test('the Owner uploads the saved file and it fills the default price book and the agreement wording', function () {
    $this->actingAs($this->owner)->get(route('settings.pricing'))->assertOk()
        ->assertSeeHtml('data-test="paintscout-import"')
        ->assertSee('Default price book: no prices yet.');

    Livewire::actingAs($this->owner)->test('pages::settings.paintscout-import')
        ->assertSet('prices', true)
        ->assertSet('wording', true)
        ->set('file', paintScoutUpload())
        ->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('paintscout-imported')
        ->assertSee('Default price book: has its prices.')
        ->assertSee('Agreement wording: version 1.')
        ->assertSet('file', null);

    expect(PriceBook::companyDefault()->rates()->exists())->toBeTrue()
        ->and(AgreementWording::current()?->version)->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::PricingImported)->sole()->actor_id)->toBe($this->owner->id);
});

test('prices are imported once here; the wording can come again as a new version', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.paintscout-import')
        ->set('wording', false)
        ->set('file', paintScoutUpload())
        ->call('import')
        ->assertHasNoErrors();

    Livewire::actingAs($this->owner)->test('pages::settings.paintscout-import')
        ->assertSeeHtml('data-test="paintscout-prices-done"')
        ->assertDontSeeHtml('data-test="paintscout-prices"')
        ->assertSet('wording', true)
        ->set('prices', true)
        ->set('file', paintScoutUpload())
        ->call('import')
        ->assertHasNoErrors();

    expect(AgreementWording::current()?->version)->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::PricingImported)->count())->toBe(1);
});

test('a file that is not a saved PaintScout file is refused, and nothing changes', function (string $content, string $message) {
    Livewire::actingAs($this->owner)->test('pages::settings.paintscout-import')
        ->set('file', paintScoutUpload($content))
        ->call('import')
        ->assertHasErrors('file')
        ->assertSee($message);

    expect(PriceBook::companyDefault()?->rates()->exists() ?? false)->toBeFalse()
        ->and(AgreementWording::current())->toBeNull();
})->with([
    'not JSON' => ['this is not json', 'it is not JSON'],
    'no rates or terms' => ['{"options": {}}', 'it has no options or rates'],
]);

test('nothing is imported until something is chosen, and a file must be given', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.paintscout-import')
        ->call('import')
        ->assertHasErrors(['file' => 'required'])
        ->set('prices', false)
        ->set('wording', false)
        ->set('file', paintScoutUpload())
        ->call('import')
        ->assertHasErrors('file')
        ->assertSee('Choose what to import from it.');
});

test('only those who may change prices can import', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();

    $this->actingAs($manager)->get(route('settings.pricing'))->assertOk()->assertDontSeeHtml('data-test="paintscout-import"');

    Livewire::actingAs($manager)->test('pages::settings.paintscout-import')->assertForbidden();
});
