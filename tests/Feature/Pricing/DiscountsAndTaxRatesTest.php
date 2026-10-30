<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\StartEstimate;
use App\Actions\Pricing\CustomizePriceBook;
use App\Actions\Pricing\MovePriceBookItem;
use App\Actions\Pricing\SaveDiscount;
use App\Actions\Pricing\SaveTaxRate;
use App\Enums\AuditAction;
use App\Enums\DiscountKind;
use App\Enums\EstimateType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Discount;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Discounts and tax rates in Settings > Pricing Settings, step 35a
 * (docs/slice-35-pricing-settings-plan.md; decision 213). Made-up names and
 * prices only.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    $this->book = PriceBook::factory()->create();
    ProductionRate::factory()->create(['price_book_id' => $this->book->id]);
    $this->cash = Discount::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample cash savings', 'kind' => DiscountKind::Percentage, 'value' => '10', 'sort_order' => 0]);
    $this->cityTax = TaxRate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample City Tax', 'percent' => '7.625', 'sort_order' => 0]);
});

/**
 * @return array{name: string, kind: string, value: string, is_default: bool, is_active: bool}
 */
function discountInput(array $changes = []): array
{
    return ['name' => 'Sample neighbor savings', 'kind' => DiscountKind::Percentage->value, 'value' => '12.5', 'is_default' => false, 'is_active' => true, ...$changes];
}

/**
 * @return array{name: string, percent: string, is_default: bool, is_active: bool}
 */
function taxRateInput(array $changes = []): array
{
    return ['name' => 'Sample County Tax', 'percent' => '6.188', 'is_default' => false, 'is_active' => true, ...$changes];
}

test('an Owner adds and changes a discount, and each is recorded with only what changed', function () {
    $neighbor = app(SaveDiscount::class)->handle($this->book, null, discountInput(), $this->owner);
    $flat = app(SaveDiscount::class)->handle($this->book, null, discountInput(['name' => 'Sample flat savings', 'kind' => DiscountKind::Amount->value, 'value' => '$40']), $this->owner);

    expect($neighbor->fresh())->kind->toBe(DiscountKind::Percentage)->sort_order->toBe(1)
        ->and($neighbor->fresh()->value)->toBe('12.5000')
        ->and($flat->fresh()->value)->toBe('40.0000')
        ->and($flat->fresh()->sort_order)->toBe(2)
        ->and(AuditEvent::query()->where('action', AuditAction::PricingDiscountAdded)->first()->after())
        ->toEqual(['discount' => 'Sample neighbor savings', 'takes_off' => '12.5%', 'starts_on_new_estimates' => false, 'is_active' => true]);

    app(SaveDiscount::class)->handle($this->book, $neighbor, discountInput(['value' => '15', 'is_default' => true]), $this->owner);

    $changed = AuditEvent::query()->where('action', AuditAction::PricingDiscountChanged)->sole();
    expect($changed->before())->toEqual(['discount' => 'Sample neighbor savings', 'takes_off' => '12.5%', 'starts_on_new_estimates' => false])
        ->and($changed->after())->toEqual(['discount' => 'Sample neighbor savings', 'takes_off' => '15%', 'starts_on_new_estimates' => true])
        ->and($changed->subject_id)->toBe($this->book->id);

    // Saving it again unchanged records nothing.
    app(SaveDiscount::class)->handle($this->book, $neighbor->fresh(), discountInput(['value' => '15', 'is_default' => true]), $this->owner);
    expect(AuditEvent::query()->where('action', AuditAction::PricingDiscountChanged)->count())->toBe(1);
});

test('a discount refuses what is not an amount or a percentage, and a retired one cannot start on new estimates', function () {
    foreach ([['name' => ''], ['kind' => 'free'], ['value' => '0'], ['value' => '101'], ['value' => '10.12345'], ['value' => 'lots'], ['kind' => DiscountKind::Amount->value, 'value' => '0'], ['is_default' => true, 'is_active' => false]] as $wrong) {
        expect(fn () => app(SaveDiscount::class)->handle($this->book, null, discountInput($wrong), $this->owner))->toThrow(ValidationException::class);
    }

    expect(Discount::query()->count())->toBe(1);
});

test('only one tax rate is the default, and making another the default is recorded on it', function () {
    $county = app(SaveTaxRate::class)->handle($this->book, null, taxRateInput(['is_default' => true]), $this->owner);
    $town = app(SaveTaxRate::class)->handle($this->book, null, taxRateInput(['name' => 'Sample Town Tax', 'percent' => '8.3']), $this->owner);

    app(SaveTaxRate::class)->handle($this->book, $town, taxRateInput(['name' => 'Sample Town Tax', 'percent' => '8.3', 'is_default' => true]), $this->owner);

    expect($county->fresh()->is_default)->toBeFalse()
        ->and($town->fresh())->is_default->toBeTrue()->percent->toBe('8.3000')
        ->and(TaxRate::query()->where('is_default', true)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::PricingTaxRateChanged)->sole()->after())
        ->toEqual(['tax_rate' => 'Sample Town Tax', 'starts_on_new_estimates' => true, 'replaces_default' => 'Sample County Tax']);

    foreach ([['percent' => '0'], ['percent' => '100'], ['percent' => '7.12345'], ['percent' => 'some'], ['is_default' => true, 'is_active' => false]] as $wrong) {
        expect(fn () => app(SaveTaxRate::class)->handle($this->book, null, taxRateInput($wrong), $this->owner))->toThrow(ValidationException::class);
    }
});

test('only Owner and Leadership change discounts and tax rates, and only in their own book', function () {
    foreach ([$this->manager, $this->sam] as $refused) {
        expect(fn () => app(SaveDiscount::class)->handle($this->book, null, discountInput(), $refused))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveTaxRate::class)->handle($this->book, null, taxRateInput(), $refused))->toThrow(AuthorizationException::class)
            ->and(fn () => app(MovePriceBookItem::class)->handle($this->book, 'discounts', $this->cash->id, 'down', $refused))->toThrow(AuthorizationException::class);
    }

    $own = app(CustomizePriceBook::class)->handle($this->lakeview, $this->owner);

    expect(fn () => app(SaveDiscount::class)->handle($own, $this->cash, discountInput(), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveTaxRate::class)->handle($own, $this->cityTax, taxRateInput(), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MovePriceBookItem::class)->handle($own, 'discounts', $this->cash->id, 'down', $this->owner))->toThrow(AuthorizationException::class);
});

test('a discount or tax rate moves one place up or down, and the move is recorded', function () {
    $second = Discount::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample card savings', 'sort_order' => 1]);
    $third = Discount::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample friend savings', 'sort_order' => 2]);

    app(MovePriceBookItem::class)->handle($this->book, 'discounts', $third->id, 'up', $this->owner);

    expect($this->book->discounts()->pluck('name')->all())->toBe(['Sample cash savings', 'Sample friend savings', 'Sample card savings'])
        ->and(AuditEvent::query()->where('action', AuditAction::PricingListReordered)->sole()->after())
        ->toEqual(['list' => 'Discounts', 'moved' => 'Sample friend savings', 'place' => 2]);

    // The first cannot move up, and nothing is recorded.
    app(MovePriceBookItem::class)->handle($this->book, 'discounts', $this->cash->id, 'up', $this->owner);
    expect(AuditEvent::query()->where('action', AuditAction::PricingListReordered)->count())->toBe(1)
        ->and($second->fresh()->sort_order)->toBe(2);
});

test('a new estimate starts with the discounts marked to and the default tax rate, never retired ones', function () {
    app(SaveDiscount::class)->handle($this->book, $this->cash, discountInput(['name' => 'Sample cash savings', 'value' => '10', 'is_default' => true]), $this->owner);
    Discount::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample retired savings', 'is_default' => true, 'is_active' => false]);
    app(SaveTaxRate::class)->handle($this->book, $this->cityTax, taxRateInput(['name' => 'Sample City Tax', 'percent' => '7.625', 'is_default' => true]), $this->owner);

    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);
    $estimate = app(StartEstimate::class)->handle($lead->fresh(), EstimateType::ResidentialInterior->value, $this->sam);

    expect($estimate->discounts()->pluck('name')->all())->toBe(['Sample cash savings'])
        ->and($estimate->fresh())->tax_name->toBe('Sample City Tax')->tax_percent->toBe('7.6250')
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateStarted)->sole()->after())
        ->toMatchArray(['discounts' => ['Sample cash savings'], 'tax' => 'Sample City Tax']);

    // The salesperson can still take them off, as before.
    app(ChangeEstimateDiscounts::class)->handle($estimate, [], null, $this->sam);
    expect($estimate->fresh())->tax_name->toBeNull()
        ->and($estimate->discounts()->count())->toBe(0);

    // A discount changed in Settings later does not change the estimate that has it.
    app(ChangeEstimateDiscounts::class)->handle($estimate, [$this->cash->id], null, $this->sam);
    app(SaveDiscount::class)->handle($this->book, $this->cash->fresh(), discountInput(['name' => 'Sample cash savings', 'value' => '20', 'is_default' => true]), $this->owner);
    expect($estimate->discounts()->sole()->value)->toBe('10.0000');
});

test('from the page, an Owner adds a discount, makes a tax rate the default and moves it', function () {
    $county = TaxRate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample County Tax', 'percent' => '6.188', 'sort_order' => 1]);

    Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['tab' => 'discounts'])
        ->call('newDiscount')
        ->set('discountForm.name', 'Sample neighbor savings')
        ->set('discountForm.value', 'half')
        ->call('saveDiscount')
        ->assertHasErrors('discountForm.value')
        ->set('discountForm.value', '12.5')
        ->call('saveDiscount')
        ->assertHasNoErrors()
        ->assertSee('Sample neighbor savings')
        ->call('editDiscount', $this->cash->id)
        ->assertSet('discountForm.value', '10')
        ->set('discountForm.is_active', false)
        ->call('saveDiscount')
        ->assertSee('Retired');

    Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['tab' => 'hourly'])
        ->call('editTaxRate', $county->id)
        ->set('taxRateForm.is_default', true)
        ->call('saveTaxRate')
        ->assertHasNoErrors()
        ->call('move', 'tax_rates', $county->id, 'up')
        ->assertSeeInOrder(['Sample County Tax', 'Default', 'Sample City Tax']);

    expect($this->cash->fresh()->is_active)->toBeFalse()
        ->and(Discount::query()->where('name', 'Sample neighbor savings')->sole()->value)->toBe('12.5000');
});

test('those who may only see prices see the discounts and tax rates but no way to change them', function () {
    $this->actingAs($this->manager)->get(route('settings.pricing', ['tab' => 'discounts']))->assertOk()
        ->assertSee('Sample cash savings')
        ->assertDontSee('data-test="add-discount"', false)
        ->assertDontSee('data-test="edit-discount-', false);

    $this->actingAs($this->manager)->get(route('settings.pricing', ['tab' => 'hourly']))->assertOk()
        ->assertSee('Sample City Tax')
        ->assertDontSee('data-test="add-tax-rate"', false);

    Livewire::actingAs($this->manager)->test('pages::settings.pricing')
        ->call('newDiscount')
        ->assertForbidden();

    // A Location still on the default is not changed from its own view.
    Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['book' => (string) $this->lakeview->id])
        ->call('move', 'discounts', $this->cash->id, 'down')
        ->assertForbidden();
});

test('an estimate keeps showing a retired discount and tax rate it already has', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);
    $estimate = app(StartEstimate::class)->handle($lead->fresh(), EstimateType::ResidentialInterior->value, $this->sam);
    app(ChangeEstimateDiscounts::class)->handle($estimate, [$this->cash->id], $this->cityTax->id, $this->sam);

    app(SaveDiscount::class)->handle($this->book, $this->cash, discountInput(['name' => 'Sample cash savings', 'value' => '10', 'is_active' => false]), $this->owner);
    app(SaveTaxRate::class)->handle($this->book, $this->cityTax, taxRateInput(['name' => 'Sample City Tax', 'percent' => '7.625', 'is_active' => false]), $this->owner);

    $this->actingAs($this->sam)->get(route('leads.estimate', [$lead, $estimate]))->assertOk()
        ->assertSee('Sample cash savings (retired)')
        ->assertSee('Sample City Tax (7.625%) · retired');

    // Another estimate is not offered them.
    $other = app(StartEstimate::class)->handle($lead->fresh(), EstimateType::ResidentialExterior->value, $this->sam);
    $this->actingAs($this->sam)->get(route('leads.estimate', [$lead, $other]))->assertOk()
        ->assertDontSee('Sample cash savings')
        ->assertDontSee('Sample City Tax');
});
