<?php

use App\Actions\Pricing\ChangeHourlyRates;
use App\Actions\Pricing\CustomizePriceBook;
use App\Actions\Pricing\ImportPaintScoutSettings;
use App\Actions\Pricing\SavePaintProduct;
use App\Actions\Pricing\SaveProductionRate;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Price books and production rates, step 31a (docs/slice-31-estimating-plan.md;
 * decisions 196 and 197). The sample PaintScout file holds made-up prices only:
 * Banana's real prices never enter the repository (decision 197, question 5).
 */

/**
 * Import the made-up sample of a saved PaintScout settings file into the default price book.
 *
 * @return array{book: PriceBook, categories: int, rates: int, paints: int, areas: int, line_items: int}
 */
function importSamplePricing(?User $actor = null): array
{
    $settings = json_decode((string) file_get_contents(base_path('tests/Fixtures/paintscout-settings-sample.json')), true);

    return app(ImportPaintScoutSettings::class)->handle($settings, $actor);
}

/**
 * @return array<string, mixed>
 */
function rateInput(ProductionRate $rate, array $changes = []): array
{
    return [
        'name' => $rate->name, 'customer_label' => $rate->customer_label, 'rate_category_id' => $rate->rate_category_id,
        'measure' => $rate->measure->value, 'calculation' => $rate->calculation->value,
        'value_1' => $rate->value_1, 'value_2' => $rate->value_2, 'value_3' => $rate->value_3, 'value_4' => $rate->value_4,
        'default_coats' => $rate->default_coats, 'uses_coats' => $rate->uses_coats, 'prep_hours' => $rate->prep_hours,
        'hourly_rate' => null, 'paint_product_id' => $rate->paint_product_id, 'estimate_types' => $rate->estimate_types,
        'excluded_from_discount' => $rate->excluded_from_discount, 'excluded_from_tax' => $rate->excluded_from_tax, 'is_active' => $rate->is_active,
        ...$changes,
    ];
}

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
});

test('PaintScout settings fill the default price book exactly, once', function () {
    $imported = importSamplePricing($this->owner);
    $book = $imported['book'];

    expect($book->isDefault())->toBeTrue()
        ->and([$imported['categories'], $imported['rates'], $imported['paints'], $imported['areas'], $imported['line_items']])->toBe([2, 3, 1, 2, 2])
        ->and($book->hourly_rate_cents)->toBe(5000)
        ->and($book->hourlyRateFor(EstimateType::ResidentialExterior))->toBe(6000)
        ->and($book->hourlyRateFor(EstimateType::ResidentialInterior))->toBe(5000)
        ->and($book->categories->pluck('name')->all())->toBe(['Sample Outside', 'Sample Walls'])
        ->and($book->categories->last()->estimate_types)->toBe([EstimateType::ResidentialInterior->value, EstimateType::CommercialInterior->value]);

    $roll = $book->rates()->where('name', 'Sample Walls Roll')->sole();
    expect($roll->measure)->toBe(RateMeasure::WallArea)
        ->and($roll->calculation)->toBe(RateCalculation::UnitsPerHour)
        ->and(array_map(fn (?string $value): ?string => $value === null ? null : (string) (float) $value, $roll->values()))->toBe(['100', '85.5', null, '0.5'])
        ->and($roll->default_coats)->toBe(2)
        ->and((float) $roll->prep_hours)->toBe(0.25)
        ->and($roll->paint->name)->toBe('Sample Wall Paint')
        ->and($roll->customer_label)->toBe('Walls')
        ->and($roll->details['showPrice'])->toBeTrue()
        ->and($roll->is_active)->toBeTrue();

    expect($book->rates()->where('name', 'Sample Door')->sole())
        ->calculation->toBe(RateCalculation::HoursPerUnit)
        ->uses_coats->toBeFalse()
        ->excluded_from_discount->toBeTrue();

    // A rate whose category PaintScout no longer has is kept, switched off; one Backoffice cannot read is left out.
    expect($book->rates()->where('name', 'Sample Old Drawer')->sole())->rate_category_id->toBeNull()->is_active->toBeFalse()
        ->and($book->rates()->where('name', 'Sample Unknown')->exists())->toBeFalse();

    expect($book->paints->sole())->price_cents->toBe(3050)->unit_label->toBe('gal')
        ->and($book->lineItems->last())->price_cents->toBe(12000)->is_calculated->toBeTrue()
        ->and($book->lineItems->first()->plainDescription())->toBe("We cover the floors.\nHoles filled.")
        ->and($book->areaTemplates->pluck('side')->all())->toBe(['interior', 'exterior']);

    expect(AuditEvent::query()->sole())->action->toBe(AuditAction::PricingImported)->actor_id->toBe($this->owner->id)
        ->and(fn () => importSamplePricing())->toThrow(RuntimeException::class, 'already has rates');
});

test('the import command fills the default price book and refuses a file it cannot read', function () {
    $this->artisan('backoffice:pricing-import', ['file' => base_path('tests/Fixtures/paintscout-settings-sample.json')])
        ->expectsOutputToContain('The default price book is filled from PaintScout.')
        ->assertSuccessful();

    expect(ProductionRate::query()->count())->toBe(3)
        ->and(AuditEvent::query()->sole()->actor_name)->toBeNull();

    $this->artisan('backoffice:pricing-import', ['file' => base_path('tests/Fixtures/no-such-file.json')])->assertFailed();
    $this->artisan('backoffice:pricing-import', ['file' => base_path('tests/Fixtures/paintscout-settings-sample.json')])
        ->expectsOutputToContain('already has rates')
        ->assertFailed();
});

test('replacing the default\'s prices removes the old ones, records how many, and leaves a Location\'s own book alone', function () {
    $default = importSamplePricing()['book'];
    $own = app(CustomizePriceBook::class)->handle($this->lakeview, $this->owner);
    $default->rates()->where('name', 'Sample Door')->sole()->forceFill(['name' => 'Sample Door, changed'])->save();

    $settings = json_decode((string) file_get_contents(base_path('tests/Fixtures/paintscout-settings-sample.json')), true);
    $replaced = app(ImportPaintScoutSettings::class)->handle($settings, $this->owner, replace: true);

    expect($replaced['book']->is($default))->toBeTrue()
        ->and($default->rates()->pluck('name')->sort()->values()->all())->toBe(['Sample Door', 'Sample Old Drawer', 'Sample Walls Roll'])
        ->and($default->categories()->count())->toBe(2)
        ->and($own->rates()->count())->toBe(3);

    $event = AuditEvent::query()->where('action', AuditAction::PricingImported)->latest('id')->first();
    expect($event->before())->toEqual(['rates' => 3, 'categories' => 2, 'paints' => 1, 'areas' => 2, 'line_items' => 2])
        ->and($event->reason)->toBe('From PaintScout, replacing the prices imported before');

    $this->artisan('backoffice:pricing-import', ['file' => base_path('tests/Fixtures/paintscout-settings-sample.json'), '--replace' => true])->assertSuccessful();
    expect(ProductionRate::query()->where('price_book_id', $default->id)->count())->toBe(3);
});

test('a Location uses the default until it is customized, then its prices are its own', function () {
    $default = importSamplePricing()['book'];

    expect(PriceBook::forLocation($this->lakeview)->is($default))->toBeTrue();

    $own = app(CustomizePriceBook::class)->handle($this->lakeview, $this->owner);

    expect(PriceBook::forLocation($this->lakeview)->is($own))->toBeTrue()
        ->and($own->customized_from_id)->toBe($default->id)
        ->and($own->rates()->count())->toBe(3)
        ->and($own->categories()->count())->toBe(2)
        ->and($own->paints()->count())->toBe(1)
        ->and($own->areaTemplates()->count())->toBe(2)
        ->and($own->lineItems()->count())->toBe(2);

    // Each copied rate points at the Location's own category and paint, never the default's.
    $roll = $own->rates()->where('name', 'Sample Walls Roll')->sole();
    expect($roll->category->price_book_id)->toBe($own->id)
        ->and($roll->paint->price_book_id)->toBe($own->id);

    // A change to the default no longer reaches the Location.
    $defaultRoll = $default->rates()->where('name', 'Sample Walls Roll')->sole();
    app(SaveProductionRate::class)->handle($default, $defaultRoll, rateInput($defaultRoll, ['value_1' => '120']), $this->owner);
    expect((float) $roll->fresh()->value_1)->toBe(100.0);

    expect(AuditEvent::query()->where('action', AuditAction::PricingCustomized)->sole())->location_id->toBe($this->lakeview->id)
        ->and(fn () => app(CustomizePriceBook::class)->handle($this->lakeview, $this->owner))->toThrow(ValidationException::class);
});

test('Owner and Leadership change prices; a manager, a salesperson and Finance cannot', function () {
    $book = importSamplePricing()['book'];
    $roll = $book->rates()->where('name', 'Sample Walls Roll')->sole();

    $leadership = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    app(SaveProductionRate::class)->handle($book, $roll, rateInput($roll, ['value_2' => '90']), $leadership);
    expect((float) $roll->fresh()->value_2)->toBe(90.0);

    foreach ([
        User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(),
        User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(),
        User::factory()->withRoleEverywhere(Role::Finance)->create(),
    ] as $someoneElse) {
        expect(fn () => app(SaveProductionRate::class)->handle($book, $roll, rateInput($roll, ['value_2' => '1']), $someoneElse))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CustomizePriceBook::class)->handle($this->lakeview, $someoneElse))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ChangeHourlyRates::class)->handle($book, '99', [], '0', $someoneElse))->toThrow(AuthorizationException::class);
    }

    expect((float) $roll->fresh()->value_2)->toBe(90.0);
});

test('a rate change records the rate and only what changed, and a rate is only changed in its own book', function () {
    $book = importSamplePricing()['book'];
    $roll = $book->rates()->where('name', 'Sample Walls Roll')->sole();

    app(SaveProductionRate::class)->handle($book, $roll, rateInput($roll, ['value_1' => '110', 'default_coats' => 3]), $this->owner);

    $event = AuditEvent::query()->where('action', AuditAction::PricingRateChanged)->sole();
    expect($event->before())->toEqual(['rate' => 'Sample Walls Roll', 'value_1' => '100', 'default_coats' => 2])
        ->and($event->after())->toEqual(['rate' => 'Sample Walls Roll', 'value_1' => '110', 'default_coats' => 3]);

    // Saving with nothing changed records nothing.
    app(SaveProductionRate::class)->handle($book, $roll->fresh(), rateInput($roll->fresh()), $this->owner);
    expect(AuditEvent::query()->where('action', AuditAction::PricingRateChanged)->count())->toBe(1);

    $other = PriceBook::factory()->forLocation($this->lakeview)->create();
    expect(fn () => app(SaveProductionRate::class)->handle($other, $roll, rateInput($roll), $this->owner))->toThrow(AuthorizationException::class);
});

test('a rate, a paint and the hourly rates refuse what is not a price', function () {
    $book = importSamplePricing()['book'];
    $roll = $book->rates()->where('name', 'Sample Walls Roll')->sole();
    $paint = $book->paints->sole();

    foreach ([['value_1' => 'fast'], ['value_1' => ''], ['value_2' => '1.23456'], ['default_coats' => 0], ['estimate_types' => []], ['hourly_rate' => 'lots']] as $wrong) {
        expect(fn () => app(SaveProductionRate::class)->handle($book, $roll, rateInput($roll, $wrong), $this->owner))->toThrow(ValidationException::class);
    }

    $otherBook = PriceBook::factory()->forLocation($this->lakeview)->create();
    $foreignCategory = RateCategory::factory()->create(['price_book_id' => $otherBook->id]);
    expect(fn () => app(SaveProductionRate::class)->handle($book, $roll, rateInput($roll, ['rate_category_id' => $foreignCategory->id]), $this->owner))->toThrow(ValidationException::class);

    expect(fn () => app(SavePaintProduct::class)->handle($book, $paint, ['name' => 'X', 'unit_label' => 'gal', 'price' => 'free', 'estimate_types' => EstimateType::values()], $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeHourlyRates::class)->handle($book, '0', [], '0', $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeHourlyRates::class)->handle($book, '65', [], '7.255', $this->owner))->toThrow(ValidationException::class);

    app(ChangeHourlyRates::class)->handle($book, '65', [EstimateType::ResidentialExterior->value => '70.50'], '7.25', $this->owner);
    expect($book->fresh())->hourly_rate_cents->toBe(6500)->tax_rate_basis_points->toBe(725)
        ->and($book->fresh()->hourlyRateFor(EstimateType::ResidentialExterior))->toBe(7050)
        ->and(AuditEvent::query()->where('action', AuditAction::PricingHourlyRatesChanged)->sole()->after())->toMatchArray(['hourly_rate_cents' => 6500, 'tax' => '7.25%']);
});

/*
 * Settings > Pricing Settings.
 */

test('the page shows prices to those who may see them, and lets only Owner and Leadership change them', function () {
    importSamplePricing();
    $harbor = Location::factory()->create(['name' => 'Harbor']);

    $this->actingAs($this->owner)->get(route('settings.pricing'))->assertOk()
        ->assertSee('Sample Walls Roll')
        ->assertSee('data-test="add-category"', false)
        ->assertDontSee('data-test="view-only"', false);

    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();
    $this->actingAs($manager)->get(route('settings.pricing', ['book' => $this->lakeview->id]))->assertOk()
        ->assertSee('Lakeview uses the default price book')
        ->assertSee('data-test="view-only"', false)
        ->assertDontSee('data-test="customize-price-book"', false)
        ->assertDontSee('data-test="edit-rate-', false);

    // A manager is never shown another Location's book; asking for one shows the default.
    $this->actingAs($manager)->get(route('settings.pricing', ['book' => $harbor->id]))->assertOk()->assertDontSee('Harbor uses the default price book');

    $this->actingAs(User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create())->get(route('settings.pricing'))->assertOk()->assertSee('Sample Walls Roll');
    $this->actingAs(User::factory()->withRoleEverywhere(Role::Finance)->create())->get(route('settings.pricing'))->assertForbidden();
    $this->actingAs(User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create())->get(route('settings.pricing'))->assertForbidden();
});

test('from the page, an Owner customizes a Location and changes one of its rates', function () {
    importSamplePricing();

    $page = Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['book' => (string) $this->lakeview->id])
        ->assertSee('Lakeview uses the default price book')
        ->call('customize')
        ->assertSee('Lakeview has its own price book');

    $own = PriceBook::forLocation($this->lakeview);
    $roll = $own->rates()->where('name', 'Sample Walls Roll')->sole();

    $page->call('editRate', $roll->id)
        ->assertSet('rateForm.value_1', '100')
        ->set('rateForm.value_1', 'quick')
        ->call('saveRate')
        ->assertHasErrors('rateForm.value_1')
        ->set('rateForm.value_1', '105')
        ->call('saveRate')
        ->assertHasNoErrors();

    expect((float) $roll->fresh()->value_1)->toBe(105.0)
        ->and((float) PriceBook::companyDefault()->rates()->where('name', 'Sample Walls Roll')->sole()->value_1)->toBe(100.0);

    // A Location still on the default shows the default's prices, which are not changed from its view.
    Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['book' => (string) Location::factory()->create()->id])
        ->call('editRate', $roll->id)
        ->assertForbidden();
});

test('the audit log names the price book that was changed', function () {
    $book = importSamplePricing($this->owner)['book'];

    $this->actingAs($this->owner)->get(route('settings.audit_log', ['area' => 'pricing']))->assertOk()
        ->assertSeeInOrder(['Pricing · Imported', 'Default price book', 'Olive Owner'])
        ->assertSee(route('settings.pricing'), false);
});
