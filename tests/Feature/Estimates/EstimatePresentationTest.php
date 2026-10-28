<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Estimates\StartEstimate;
use App\Enums\DiscountKind;
use App\Enums\EstimateType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Discount;
use App\Models\Estimate;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\Proposal;
use App\Models\RateCategory;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Money;

/*
 * The presentation, step 31d (docs/slice-31-estimating-plan.md; decision
 * 207): an estimate shown to the homeowner. Made-up prices only.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $book = PriceBook::factory()->create(['hourly_rate_cents' => 5000]);
    $category = RateCategory::factory()->create(['price_book_id' => $book->id, 'name' => 'Sample Walls', 'source_key' => 'sample-walls']);
    $paint = PaintProduct::factory()->create(['price_book_id' => $book->id, 'name' => 'Sample Paint', 'price_cents' => 3050, 'coverage_area' => '250', 'source_key' => 'sample-paint']);
    $this->walls = ProductionRate::factory()->create([
        'price_book_id' => $book->id, 'rate_category_id' => $category->id, 'name' => 'Sample Walls Roll', 'customer_label' => 'Paint walls',
        'value_1' => '150', 'value_2' => '90', 'value_3' => null, 'uses_coats' => true, 'default_coats' => 2, 'paint_product_id' => $paint->id, 'source_key' => 'sample-walls-roll',
    ]);
    $this->checkDiscount = Discount::factory()->create(['price_book_id' => $book->id, 'name' => 'Sample check savings', 'kind' => DiscountKind::Percentage, 'value' => '10']);
    $this->cityTax = TaxRate::factory()->create(['price_book_id' => $book->id, 'name' => 'Sample City Tax', 'percent' => '5.5']);

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->lead = $this->lead->fresh();

    // "Bedroom only", then a copy of it with a hallway and written preparation: the larger one, made second.
    $this->estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    $this->small = app(SaveEstimateOption::class)->handle($this->estimate, $this->estimate->options()->sole(), ['name' => 'Bedroom only', 'description' => 'Fresh walls in the main bedroom.'], $this->sam);
    $bedroom = app(SaveEstimateArea::class)->handle($this->estimate, $this->small, null, ['name' => 'Main bedroom', 'shape' => 'room', 'width' => '12', 'length' => '14', 'height' => '8'], $this->sam);
    app(SaveEstimateSurface::class)->handle($this->estimate, $bedroom, null, ['production_rate_id' => $this->walls->id, 'coats' => '2'], $this->sam);

    $this->large = app(SaveEstimateOption::class)->copy($this->estimate, $this->small, $this->sam);
    app(SaveEstimateOption::class)->handle($this->estimate, $this->large, ['name' => 'Bedroom and hallway', 'description' => ''], $this->sam);
    $hallway = app(SaveEstimateArea::class)->handle($this->estimate, $this->large, null, ['name' => 'Upstairs hallway', 'shape' => 'room', 'width' => '4', 'length' => '10', 'height' => '8'], $this->sam);
    app(SaveEstimateSurface::class)->handle($this->estimate, $hallway, null, ['production_rate_id' => $this->walls->id, 'coats' => '2'], $this->sam);
    app(SaveEstimateLineItem::class)->handle($this->estimate, $this->large, null, ['name' => 'Preparation', 'description' => 'Patch nail holes and sand before painting.', 'pricing' => 'hours', 'hours' => '2'], $this->sam);
});

test('the homeowner sees each option, largest first, with what it covers and its price after discounts', function () {
    app(ChangeEstimateDiscounts::class)->handle($this->estimate, [$this->checkDiscount->id], $this->cityTax->id, $this->sam);
    $large = $this->large->fresh();
    $small = $this->small->fresh();

    $this->actingAs($this->sam)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertOk()
        ->assertSeeInOrder(['Bedroom and hallway', 'Main bedroom', 'Upstairs hallway', 'Preparation', 'Patch nail holes and sand before painting.', 'Bedroom only', 'Fresh walls in the main bedroom.', 'Main bedroom'])
        // The bedroom is five hours, $250, so 10% is $25 off; with the hallway's three hours and two of preparation, $50.
        ->assertSee(Money::format($large->total_cents))
        ->assertSee(Money::format($small->total_cents))
        ->assertSee('Sample check savings: $50 off')
        ->assertSee('Sample check savings: $25 off')
        ->assertSee('Tax included')
        ->assertSeeHtml('href="#option-'.$large->id.'"')
        // Never the rates, the paint, the hours or the hourly rate.
        ->assertDontSee('Sample Walls Roll')
        ->assertDontSee('Sample Paint')
        ->assertDontSee('Paint walls')
        ->assertDontSee('hours')
        ->assertDontSee('hourly');
});

test('without discounts or tax, the price stands alone', function () {
    $this->actingAs($this->sam)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertOk()
        ->assertDontSee(' off')
        ->assertDontSee('Tax included');
});

test('opening the presentation counts nothing and records nothing', function () {
    $events = AuditEvent::query()->count();

    $this->actingAs($this->sam)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertOk();

    expect(Proposal::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($events);
});

test('anyone who may see the estimate may present it, and only through its own Lead', function () {
    $this->actingAs($this->sam)->get(route('leads.estimate', [$this->lead, $this->estimate]))
        ->assertSeeHtml(route('leads.estimate.present', [$this->lead, $this->estimate]));

    $this->actingAs($this->riley)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertOk();

    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create(['name' => 'Northside']))->create();
    $this->actingAs($elsewhere)->get(route('leads.estimate.present', [$this->lead, $this->estimate]))->assertForbidden();

    $other = bookableLead($this->lakeview);
    $this->actingAs($this->sam)->get(route('leads.estimate.present', [$other, $this->estimate]))->assertNotFound();
});
