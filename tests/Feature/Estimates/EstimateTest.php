<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\ChangeEstimateHourlyRate;
use App\Actions\Estimates\RemoveFromEstimate;
use App\Actions\Estimates\RepriceEstimate;
use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Estimates\StartEstimate;
use App\Actions\Pricing\CustomizePriceBook;
use App\Enums\AuditAction;
use App\Enums\DiscountKind;
use App\Enums\EstimateType;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Discount;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateOption;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The estimate in the Sales Workspace, step 31c
 * (docs/slice-31-estimating-plan.md; decision 204). Made-up prices only:
 * Banana's real prices never enter the repository (decision 197, question 5).
 *
 * The default price book charges $50 an hour inside and $60 outside. Walls
 * are 150 sq ft an hour for one coat and 90 for two; a door is half an hour.
 * The paint is $30.50 a gallon and covers 250 sq ft.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->book = PriceBook::factory()->create(['hourly_rate_cents' => 5000, 'estimate_type_hourly_rates' => [EstimateType::ResidentialExterior->value => 6000]]);
    $this->category = RateCategory::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample Walls', 'source_key' => 'sample-walls']);
    $this->paint = PaintProduct::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample Paint', 'price_cents' => 3050, 'coverage_area' => '250', 'coverage_each' => '0.2', 'source_key' => 'sample-paint']);
    $this->walls = ProductionRate::factory()->create([
        'price_book_id' => $this->book->id, 'rate_category_id' => $this->category->id, 'name' => 'Sample Walls Roll', 'customer_label' => 'Paint walls',
        'value_1' => '150', 'value_2' => '90', 'value_3' => null, 'uses_coats' => true, 'default_coats' => 2, 'paint_product_id' => $this->paint->id, 'source_key' => 'sample-walls-roll',
    ]);
    $this->doors = ProductionRate::factory()->create([
        'price_book_id' => $this->book->id, 'rate_category_id' => $this->category->id, 'name' => 'Sample Door',
        'measure' => RateMeasure::Count, 'calculation' => RateCalculation::HoursPerUnit, 'value_1' => '0.5', 'value_2' => null, 'value_3' => null, 'uses_coats' => false, 'default_coats' => 1, 'source_key' => 'sample-door',
    ]);
    $this->checkDiscount = Discount::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample check savings', 'kind' => DiscountKind::Percentage, 'value' => '10']);
    $this->cityTax = TaxRate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample City Tax', 'percent' => '5.5']);

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->lead = $this->lead->fresh();
});

/**
 * A bedroom, 12 by 14 by 8: 416 sq ft of wall.
 */
function bedroom(Estimate $estimate, User $by, ?EstimateOption $option = null): EstimateArea
{
    return app(SaveEstimateArea::class)->handle($estimate, $option ?? $estimate->options()->firstOrFail(), null, ['name' => 'Bedroom', 'shape' => 'room', 'width' => '12', 'length' => '14', 'height' => '8'], $by);
}

test('the salesperson starts an estimate from the Location\'s price book at its hourly rate for the type', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialExterior->value, $this->sam);

    expect($estimate)
        ->price_book_id->toBe($this->book->id)
        ->hourly_rate_cents->toBe(6000)
        ->created_by_name->toBe('Sam Sales')
        ->and($estimate->options()->sole()->name)->toBe('Option 1')
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateStarted)->sole())
        ->subject_id->toBe($this->lead->id)
        ->location_id->toBe($this->lakeview->id);

    // Another salesperson, and anyone for a Commercial estimate, is refused.
    expect(fn () => app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->riley))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartEstimate::class)->handle($this->lead, EstimateType::CommercialInterior->value, $this->sam))->toThrow(ValidationException::class);
});

test('an estimate is priced by the engine as it is built, and every option keeps its total', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    $room = bedroom($estimate, $this->sam);

    // Two coats of walls: 416 / 90 = 4.62 hours, so 5, $250; 3.328 gallons, $101.50, so $102.
    app(SaveEstimateSurface::class)->handle($estimate, $room, null, ['production_rate_id' => $this->walls->id, 'coats' => '2', 'paint_product_id' => $this->paint->id], $this->sam);
    // Three doors at half an hour: 1.5 hours, so 2, $100; 0.6 gallons, $18.30, so $19.
    app(SaveEstimateSurface::class)->handle($estimate, $room, null, ['production_rate_id' => $this->doors->id, 'quantity' => '3', 'paint_product_id' => $this->paint->id, 'prep_hours' => '0.5'], $this->sam);
    // A trip charge of $74.20 is charged as $75.
    app(SaveEstimateLineItem::class)->handle($estimate, $estimate->options()->sole(), null, ['name' => 'Trip charge', 'pricing' => 'price', 'price' => '74.20'], $this->sam);

    // Prep: half an hour at $50, so the doors are $125 of labor.
    expect($estimate->options()->sole()->total_cents)->toBe(25000 + 10200 + 12500 + 1900 + 7500);

    $surface = $room->surfaces()->first();
    expect($surface->rate)->toMatchArray(['name' => 'Sample Walls Roll', 'customer_label' => 'Paint walls', 'uses_coats' => true])
        ->and($surface->paint)->toMatchArray(['name' => 'Sample Paint', 'price_cents' => 3050]);

    // A count needs how many; a rate from another price book is refused.
    $elsewhere = ProductionRate::factory()->create();
    expect(fn () => app(SaveEstimateSurface::class)->handle($estimate, $room, null, ['production_rate_id' => $this->doors->id], $this->sam))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveEstimateSurface::class)->handle($estimate, $room, null, ['production_rate_id' => $elsewhere->id], $this->sam))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveEstimateArea::class)->handle($estimate, $estimate->options()->sole(), null, ['name' => 'X', 'shape' => 'room', 'width' => 'wide', 'length' => '1', 'height' => '1'], $this->sam))->toThrow(ValidationException::class);
});

test('a price change in Settings never changes a saved estimate until it is repriced', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    app(SaveEstimateSurface::class)->handle($estimate, bedroom($estimate, $this->sam), null, ['production_rate_id' => $this->walls->id, 'coats' => '1', 'paint_product_id' => $this->paint->id], $this->sam);
    $option = $estimate->options()->sole();

    // 416 / 150 = 2.77 hours, so 3, $150; 1.664 gallons, $50.75, so $51.
    expect($option->fresh()->total_cents)->toBe(20100);

    $this->walls->forceFill(['value_1' => '100'])->save();
    $this->paint->forceFill(['price_cents' => 4000])->save();
    $this->book->forceFill(['hourly_rate_cents' => 5500])->save();

    // Saving the same surface again keeps the prices it was built with.
    $surface = $option->areas()->sole()->surfaces()->sole();
    app(SaveEstimateSurface::class)->handle($estimate, $option->areas()->sole(), $surface, ['production_rate_id' => $this->walls->id, 'coats' => '1', 'paint_product_id' => $this->paint->id], $this->sam);
    expect($option->fresh()->total_cents)->toBe(20100);

    // Repricing takes today's: 416 / 100 = 4.16, so 5 hours at $55, $275; 1.664 gallons at $40, $66.56, so $67.
    $result = app(RepriceEstimate::class)->handle($estimate, $this->sam);

    expect($option->fresh()->total_cents)->toBe(27500 + 6700)
        ->and($result['changed'])->toBe(2)
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateRepriced)->sole()->after())->toMatchArray(['Option 1' => '$342']);
});

test('repricing after the Location is customized takes the Location\'s own prices', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    app(SaveEstimateSurface::class)->handle($estimate, bedroom($estimate, $this->sam), null, ['production_rate_id' => $this->walls->id, 'coats' => '1', 'paint_product_id' => $this->paint->id], $this->sam);

    $own = app(CustomizePriceBook::class)->handle($this->lakeview, $this->owner);
    $own->rates()->where('name', 'Sample Walls Roll')->sole()->forceFill(['value_1' => '208'])->save();

    app(RepriceEstimate::class)->handle($estimate, $this->sam);

    // 416 / 208 = 2 hours, $100, and the paint, $51.
    expect($estimate->fresh())->price_book_id->toBe($own->id)
        ->and($estimate->options()->sole()->total_cents)->toBe(15100)
        ->and($estimate->options()->sole()->areas()->sole()->surfaces()->sole()->productionRate->price_book_id)->toBe($own->id);
});

test('a copied option is its own, and an estimate keeps at least one option', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    $first = $estimate->options()->sole();
    app(SaveEstimateSurface::class)->handle($estimate, bedroom($estimate, $this->sam), null, ['production_rate_id' => $this->walls->id, 'coats' => '2', 'paint_product_id' => $this->paint->id], $this->sam);

    $copy = app(SaveEstimateOption::class)->copy($estimate, $first, $this->sam);

    expect($copy->name)->toBe('Option 1 (copy)')
        ->and($copy->fresh()->total_cents)->toBe($first->fresh()->total_cents);

    // One coat in the copy only.
    $surface = $copy->areas()->sole()->surfaces()->sole();
    app(SaveEstimateSurface::class)->handle($estimate, $copy->areas()->sole(), $surface, ['production_rate_id' => $this->walls->id, 'coats' => '1', 'paint_product_id' => $this->paint->id], $this->sam);
    app(SaveEstimateOption::class)->handle($estimate, $copy, ['name' => 'Walls, one coat', 'description' => 'One fresh coat.'], $this->sam);

    expect($copy->fresh()->total_cents)->toBe(20100)
        ->and($first->fresh()->total_cents)->toBe(35200);

    app(RemoveFromEstimate::class)->handle($estimate, $copy, $this->sam);
    expect(fn () => app(RemoveFromEstimate::class)->handle($estimate, $first, $this->sam))->toThrow(ValidationException::class)
        ->and($estimate->options()->count())->toBe(1);
});

test('discounts and tax are chosen once for the estimate and apply to every option', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    app(SaveEstimateSurface::class)->handle($estimate, bedroom($estimate, $this->sam), null, ['production_rate_id' => $this->walls->id, 'coats' => '1', 'paint_product_id' => $this->paint->id], $this->sam);
    $copy = app(SaveEstimateOption::class)->copy($estimate, $estimate->options()->first(), $this->sam);

    app(ChangeEstimateDiscounts::class)->handle($estimate, [$this->checkDiscount->id], $this->cityTax->id, $this->sam);

    // $201, less 10% rounded up ($21): $180, plus 5.5% tax ($9.90): $189.90.
    expect($estimate->options()->pluck('total_cents')->all())->toBe([18990, 18990])
        ->and($copy->fresh()->total_cents)->toBe(18990)
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateDiscountsChanged)->sole()->after())
        ->toEqual(['discounts' => ['Sample check savings'], 'tax' => 'Sample City Tax']);

    // A discount from another price book is refused.
    expect(fn () => app(ChangeEstimateDiscounts::class)->handle($estimate, [Discount::factory()->create()->id], null, $this->sam))->toThrow(ValidationException::class);
});

test('only a manager changes the hourly rate, of the estimate or of a line item', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    $option = $estimate->options()->sole();

    expect(fn () => app(ChangeEstimateHourlyRate::class)->handle($estimate, '45', $this->sam))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveEstimateLineItem::class)->handle($estimate, $option, null, ['name' => 'Consult', 'pricing' => 'hours', 'hours' => '2', 'hourly_rate' => '30'], $this->sam))->toThrow(ValidationException::class);

    app(ChangeEstimateHourlyRate::class)->handle($estimate, '45', $this->manager);
    app(SaveEstimateLineItem::class)->handle($estimate, $option, null, ['name' => 'Consult', 'pricing' => 'hours', 'hours' => '2.5'], $this->sam);

    // 2.5 hours at $45 is $112.50, so $113.
    expect($estimate->fresh()->hourly_rate_cents)->toBe(4500)
        ->and($option->fresh()->total_cents)->toBe(11300)
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateHourlyRateChanged)->sole())
        ->before()->toEqual(['hourly_rate' => '$50'])
        ->after()->toEqual(['hourly_rate' => '$45']);
});

test('someone who may only see the Lead sees its estimate read-only', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    bedroom($estimate, $this->sam);

    $this->actingAs($this->riley)->get(route('leads.estimate', [$this->lead, $estimate]))->assertOk()
        ->assertSee('Bedroom')
        ->assertDontSee('data-test="add-area"', false)
        ->assertDontSee('data-test="copy-option"', false);

    expect(fn () => bedroom($estimate, $this->riley))->toThrow(AuthorizationException::class);

    // An estimate is only reached through its own Lead.
    $other = bookableLead($this->lakeview);
    $this->actingAs($this->sam)->get(route('leads.estimate', [$other, $estimate]))->assertNotFound();
});

test('from the page, the salesperson builds an option, copies it and removes a surface after confirming', function () {
    $this->actingAs($this->sam);

    Livewire::test('pages::leads.estimates', ['lead' => $this->lead])
        ->set('estimateType', EstimateType::ResidentialInterior->value)
        ->call('start')
        ->assertRedirect();

    $estimate = Estimate::query()->sole();

    $page = Livewire::test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $estimate])
        ->call('newArea')
        ->set('areaForm.name', 'Bedroom')->set('areaForm.width', '12')->set('areaForm.length', '14')->set('areaForm.height', '8')
        ->call('saveArea')
        ->assertHasNoErrors();

    $area = EstimateArea::query()->sole();

    $page->call('newSurface', $area->id)
        ->set('surfaceForm.category_id', (string) $this->category->id)
        ->set('surfaceForm.production_rate_id', (string) $this->walls->id)
        ->assertSet('surfaceForm.coats', '2')
        ->assertSet('surfaceForm.paint_product_id', (string) $this->paint->id)
        ->call('saveSurface')
        ->assertHasNoErrors()
        ->assertSee('Paint walls')
        ->assertSee('$352');

    $page->call('newSurface', $area->id)
        ->set('surfaceForm.category_id', (string) $this->category->id)
        ->set('surfaceForm.production_rate_id', (string) $this->doors->id)
        ->call('saveSurface')
        ->assertHasErrors(['surfaceForm.quantity']);

    $page->call('copyOption')->assertSee('Option 1 (copy)');
    expect($estimate->options()->count())->toBe(2);

    $surface = $area->surfaces()->sole();
    $page->call('showOption', $estimate->options()->first()->id)
        ->call('confirmRemove', 'surface', $surface->id)
        ->assertSee('Remove Paint walls?')
        ->call('remove');

    expect($area->surfaces()->count())->toBe(0)
        ->and($estimate->options()->first()->total_cents)->toBe(0)
        ->and($estimate->options()->get()->last()->total_cents)->toBe(35200);
});
