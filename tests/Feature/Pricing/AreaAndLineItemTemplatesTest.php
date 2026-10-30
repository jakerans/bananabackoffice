<?php

use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Estimates\StartEstimate;
use App\Actions\Pricing\CustomizePriceBook;
use App\Actions\Pricing\MovePriceBookItem;
use App\Actions\Pricing\SaveAreaTemplate;
use App\Actions\Pricing\SaveLineItemTemplate;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Role;
use App\Models\AreaTemplate;
use App\Models\AuditEvent;
use App\Models\LineItemTemplate;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Area and line item templates in Settings > Pricing Settings, step 35b
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
    $this->bedroom = AreaTemplate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample Bedroom', 'side' => 'interior', 'estimate_types' => [EstimateType::ResidentialInterior->value], 'sort_order' => 0]);
    $this->prep = LineItemTemplate::factory()->create([
        'price_book_id' => $this->book->id, 'name' => 'Sample Prep', 'description' => '<ul><li>Cover the floors</li><li>Fill the holes</li></ul>',
        'price_cents' => 0, 'hours' => null, 'estimate_types' => EstimateType::values(), 'sort_order' => 0,
    ]);
});

/**
 * @return array{name: string, side: string, estimate_types: list<string>, is_active: bool}
 */
function areaInput(array $changes = []): array
{
    return ['name' => 'Sample Garage Door', 'side' => 'exterior', 'estimate_types' => [EstimateType::ResidentialExterior->value], 'is_active' => true, ...$changes];
}

/**
 * @return array<string, mixed>
 */
function lineItemInput(array $changes = []): array
{
    return [
        'name' => 'Sample Wood Repair', 'description' => "Replace soft wood\n\n  Prime < 24 hours after ", 'pricing' => SaveLineItemTemplate::HOURS,
        'price' => '', 'hours' => '2.5', 'hourly_rate' => '85', 'estimate_types' => [EstimateType::ResidentialExterior->value], 'is_active' => true, ...$changes,
    ];
}

test('an Owner adds and changes an area, recorded with only what changed', function () {
    $garage = app(SaveAreaTemplate::class)->handle($this->book, null, areaInput(), $this->owner);

    expect($garage->fresh())->side->toBe('exterior')->sort_order->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::PricingAreaAdded)->sole()->after())
        ->toEqual(['area' => 'Sample Garage Door', 'inside_or_out' => 'Outside', 'estimate_types' => 'Residential Exterior', 'is_active' => true]);

    app(SaveAreaTemplate::class)->handle($this->book, $garage, areaInput(['side' => '', 'is_active' => false]), $this->owner);

    $changed = AuditEvent::query()->where('action', AuditAction::PricingAreaChanged)->sole();
    expect($garage->fresh()->side)->toBeNull()
        ->and($changed->before())->toEqual(['area' => 'Sample Garage Door', 'inside_or_out' => 'Outside', 'is_active' => true])
        ->and($changed->after())->toEqual(['area' => 'Sample Garage Door', 'inside_or_out' => 'Either', 'is_active' => false]);

    foreach ([['name' => ''], ['side' => 'attic'], ['estimate_types' => []], ['estimate_types' => ['boat']]] as $wrong) {
        expect(fn () => app(SaveAreaTemplate::class)->handle($this->book, null, areaInput($wrong), $this->owner))->toThrow(ValidationException::class);
    }
});

test('a line item is priced by a price or by hours, and its description is kept as typed', function () {
    $repair = app(SaveLineItemTemplate::class)->handle($this->book, null, lineItemInput(), $this->owner);

    expect($repair->fresh())->hours->toBe('2.50')->hourly_rate_cents->toBe(8500)->price_cents->toBeNull()->is_calculated->toBeTrue()
        ->and($repair->fresh()->plainDescription())->toBe("Replace soft wood\nPrime < 24 hours after")
        ->and(SaveLineItemTemplate::pricedAs($repair->fresh()))->toBe('2.5 hours at $85');

    app(SaveLineItemTemplate::class)->handle($this->book, $repair, lineItemInput(['pricing' => SaveLineItemTemplate::PRICED, 'price' => '$120']), $this->owner);

    expect($repair->fresh())->price_cents->toBe(12000)->hours->toBeNull()->hourly_rate_cents->toBeNull()->is_calculated->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::PricingLineItemChanged)->sole()->after())
        ->toEqual(['line_item' => 'Sample Wood Repair', 'priced_as' => '$120']);

    // A section that only explains costs nothing; its formatted description stays as it was while its words are unchanged.
    app(SaveLineItemTemplate::class)->handle($this->book, $this->prep, lineItemInput(['name' => 'Sample Prep', 'description' => "Cover the floors\nFill the holes", 'pricing' => SaveLineItemTemplate::PRICED, 'price' => '0']), $this->owner);
    expect($this->prep->fresh())->description->toBe('<ul><li>Cover the floors</li><li>Fill the holes</li></ul>')->price_cents->toBe(0);

    foreach ([['hours' => '0'], ['hours' => '1.255'], ['hourly_rate' => 'lots'], ['pricing' => 'free'], ['pricing' => SaveLineItemTemplate::PRICED, 'price' => 'free'], ['name' => '']] as $wrong) {
        expect(fn () => app(SaveLineItemTemplate::class)->handle($this->book, null, lineItemInput($wrong), $this->owner))->toThrow(ValidationException::class);
    }
});

test('only Owner and Leadership change areas and line items, and only in their own book', function () {
    foreach ([$this->manager, $this->sam] as $refused) {
        expect(fn () => app(SaveAreaTemplate::class)->handle($this->book, null, areaInput(), $refused))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveLineItemTemplate::class)->handle($this->book, null, lineItemInput(), $refused))->toThrow(AuthorizationException::class);
    }

    $own = app(CustomizePriceBook::class)->handle($this->lakeview, $this->owner);

    expect(fn () => app(SaveAreaTemplate::class)->handle($own, $this->bedroom, areaInput(), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveLineItemTemplate::class)->handle($own, $this->prep, lineItemInput(), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MovePriceBookItem::class)->handle($own, 'areas', $this->bedroom->id, 'down', $this->owner))->toThrow(AuthorizationException::class);
});

test('areas and line items move up and down their lists', function () {
    $kitchen = AreaTemplate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample Kitchen', 'sort_order' => 1]);
    $cleanup = LineItemTemplate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample Cleanup', 'sort_order' => 1]);

    app(MovePriceBookItem::class)->handle($this->book, 'areas', $kitchen->id, 'up', $this->owner);
    app(MovePriceBookItem::class)->handle($this->book, 'line_items', $this->prep->id, 'down', $this->owner);

    expect($this->book->areaTemplates()->pluck('name')->all())->toBe(['Sample Kitchen', 'Sample Bedroom'])
        ->and($this->book->lineItems()->pluck('name')->all())->toBe(['Sample Cleanup', 'Sample Prep'])
        ->and(AuditEvent::query()->where('action', AuditAction::PricingListReordered)->get()->map->after()->all())
        ->toEqual([['list' => 'Areas', 'moved' => 'Sample Kitchen', 'place' => 1], ['list' => 'Line items', 'moved' => 'Sample Prep', 'place' => 2]]);
});

test('an estimate cannot be given a retired area or line item, but keeps one it already has', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);
    $estimate = app(StartEstimate::class)->handle($lead->fresh(), EstimateType::ResidentialInterior->value, $this->sam);
    $option = $estimate->options()->sole();
    $room = ['name' => 'Bedroom', 'shape' => 'room', 'width' => '12', 'length' => '14', 'height' => '8'];
    $line = ['name' => 'Prep', 'pricing' => 'price', 'price' => '0'];

    $area = app(SaveEstimateArea::class)->handle($estimate, $option, null, ['area_template_id' => $this->bedroom->id, ...$room], $this->sam);
    $item = app(SaveEstimateLineItem::class)->handle($estimate, $option, null, ['line_item_template_id' => $this->prep->id, ...$line], $this->sam);

    app(SaveAreaTemplate::class)->handle($this->book, $this->bedroom, areaInput(['name' => 'Sample Bedroom', 'side' => 'interior', 'is_active' => false]), $this->owner);
    app(SaveLineItemTemplate::class)->handle($this->book, $this->prep, lineItemInput(['name' => 'Sample Prep', 'pricing' => 'price', 'price' => '0', 'is_active' => false]), $this->owner);

    expect(fn () => app(SaveEstimateArea::class)->handle($estimate, $option, null, ['area_template_id' => $this->bedroom->id, ...$room], $this->sam))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveEstimateLineItem::class)->handle($estimate, $option, null, ['line_item_template_id' => $this->prep->id, ...$line], $this->sam))->toThrow(ValidationException::class);

    app(SaveEstimateArea::class)->handle($estimate, $option, $area, ['area_template_id' => $this->bedroom->id, ...$room, 'width' => '13'], $this->sam);
    app(SaveEstimateLineItem::class)->handle($estimate, $option, $item, ['line_item_template_id' => $this->prep->id, ...$line, 'name' => 'Prep and cover'], $this->sam);

    expect($area->fresh())->area_template_id->toBe($this->bedroom->id)
        ->and($item->fresh()->name)->toBe('Prep and cover');
});

test('from the page, an Owner adds an area and changes a line item to hours', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['tab' => 'areas'])
        ->call('newArea')
        ->set('areaForm.name', 'Sample Sunroom')
        ->set('areaForm.estimate_types', [])
        ->call('saveArea')
        ->assertHasErrors('areaForm.estimate_types')
        ->set('areaForm.estimate_types', [EstimateType::ResidentialInterior->value])
        ->call('saveArea')
        ->assertHasNoErrors()
        ->assertSee('Sample Sunroom');

    Livewire::actingAs($this->owner)->test('pages::settings.pricing', ['tab' => 'line-items'])
        ->call('editLineItem', $this->prep->id)
        ->assertSet('lineItemForm.description', "Cover the floors\nFill the holes")
        ->assertSet('lineItemForm.pricing', SaveLineItemTemplate::PRICED)
        ->set('lineItemForm.pricing', SaveLineItemTemplate::HOURS)
        ->set('lineItemForm.hours', '1.5')
        ->call('saveLineItem')
        ->assertHasNoErrors()
        ->assertSee('1.5 hours');

    expect(AreaTemplate::query()->where('name', 'Sample Sunroom')->sole()->side)->toBe('interior')
        ->and($this->prep->fresh()->hours)->toBe('1.50');
});

test('those who may only see prices see areas and line items but no way to change them', function () {
    $this->actingAs($this->manager)->get(route('settings.pricing', ['tab' => 'areas']))->assertOk()
        ->assertSee('Sample Bedroom')
        ->assertDontSee('data-test="add-area"', false);

    $this->actingAs($this->manager)->get(route('settings.pricing', ['tab' => 'line-items']))->assertOk()
        ->assertSee('Sample Prep')
        ->assertDontSee('data-test="edit-line-item-', false);

    Livewire::actingAs($this->manager)->test('pages::settings.pricing')
        ->call('newLineItem')
        ->assertForbidden();
});
