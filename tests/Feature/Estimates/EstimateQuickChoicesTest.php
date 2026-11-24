<?php

use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Estimates\StartEstimate;
use App\Enums\EstimateType;
use App\Enums\Role;
use App\Models\EstimateSurface;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Slice 40: quick choices on the estimate (docs/slice-40-estimate-and-presentation-plan.md;
 * decision 238): a piece of work added to several rooms at once, and taken
 * out of every room that has it. Made-up prices only.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $book = PriceBook::factory()->create(['hourly_rate_cents' => 5000]);
    $category = RateCategory::factory()->create(['price_book_id' => $book->id, 'name' => 'Sample Interior']);
    $paint = PaintProduct::factory()->create(['price_book_id' => $book->id, 'price_cents' => 3050, 'coverage_area' => '250']);
    $rate = fn (string $name): ProductionRate => ProductionRate::factory()->create([
        'price_book_id' => $book->id, 'rate_category_id' => $category->id, 'name' => $name, 'value_1' => '150', 'value_2' => '90', 'value_3' => null,
        'uses_coats' => true, 'default_coats' => 2, 'paint_product_id' => $paint->id,
    ]);
    $this->walls = $rate('Sample Walls');
    $this->ceiling = $rate('Sample Ceiling');

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->lead = $this->lead->fresh();

    $this->estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    $this->option = $this->estimate->options()->sole();

    $room = fn (string $name) => app(SaveEstimateArea::class)->handle($this->estimate, $this->option, null, ['name' => $name, 'shape' => 'room', 'width' => '12', 'length' => '14', 'height' => '8'], $this->sam);
    $this->bedroom = $room('Bedroom');
    $this->hall = $room('Hall');
    $this->kitchen = $room('Kitchen');

    // The hall has its ceiling already, with one coat.
    $this->hallCeiling = app(SaveEstimateSurface::class)->handle($this->estimate, $this->hall, null, ['production_rate_id' => $this->ceiling->id, 'coats' => '1'], $this->sam);
});

function estimateBuilder(): Testable
{
    return Livewire::actingAs(test()->sam)->test('pages::leads.estimate', ['lead' => test()->lead, 'estimate' => test()->estimate]);
}

/**
 * The rooms of the option that have the ceiling, by name.
 *
 * @return list<string>
 */
function roomsWithCeiling(): array
{
    return EstimateSurface::query()->where('production_rate_id', test()->ceiling->id)->with('area')->get()->map(fn (EstimateSurface $surface): string => $surface->area->name)->sort()->values()->all();
}

test('a piece of work is added to every room at once, and a room that has it already is left as it is', function () {
    estimateBuilder()
        ->call('newSurface', $this->bedroom->id)
        ->assertSeeHtml('data-test="surface-also"')
        ->set('surfaceForm.production_rate_id', (string) $this->ceiling->id)
        ->call('alsoEveryArea')
        ->assertSet('surfaceForm.also_area_ids', [(string) $this->hall->id, (string) $this->kitchen->id])
        ->call('saveSurface')
        ->assertHasNoErrors();

    expect(roomsWithCeiling())->toBe(['Bedroom', 'Hall', 'Kitchen'])
        // The hall's own ceiling, one coat, was not added again or changed.
        ->and($this->hall->surfaces()->sole())->id->toBe($this->hallCeiling->id)->coats->toBe(1)
        ->and($this->kitchen->surfaces()->sole()->coats)->toBe(2);
});

test('only the rooms ticked get the work, and never a room of another option', function () {
    $other = app(SaveEstimateOption::class)->copy($this->estimate, $this->option, $this->sam);
    $elsewhere = $other->areas()->where('name', 'Kitchen')->sole();

    estimateBuilder()
        ->call('newSurface', $this->bedroom->id)
        ->set('surfaceForm.production_rate_id', (string) $this->walls->id)
        ->set('surfaceForm.also_area_ids', [(string) $this->kitchen->id, (string) $elsewhere->id])
        ->call('saveSurface')
        ->assertHasNoErrors();

    expect(EstimateSurface::query()->where('production_rate_id', $this->walls->id)->pluck('estimate_area_id')->sort()->values()->all())
        ->toBe([$this->bedroom->id, $this->kitchen->id]);
});

test('a piece of work is removed from one room, or from every room that has it', function () {
    $bedroomCeiling = app(SaveEstimateSurface::class)->handle($this->estimate, $this->bedroom, null, ['production_rate_id' => $this->ceiling->id, 'coats' => '2'], $this->sam);
    $kitchenCeiling = app(SaveEstimateSurface::class)->handle($this->estimate, $this->kitchen, null, ['production_rate_id' => $this->ceiling->id, 'coats' => '2'], $this->sam);
    $bedroomWalls = app(SaveEstimateSurface::class)->handle($this->estimate, $this->bedroom, null, ['production_rate_id' => $this->walls->id, 'coats' => '2'], $this->sam);

    // By itself, as before.
    estimateBuilder()
        ->call('confirmRemove', 'surface', $kitchenCeiling->id)
        ->assertSet('removingElsewhere', 2)
        ->assertSeeHtml('data-test="remove-everywhere"')
        ->call('remove');

    expect(roomsWithCeiling())->toBe(['Bedroom', 'Hall']);

    // From every room that has it; other work stays.
    estimateBuilder()
        ->call('confirmRemove', 'surface', $bedroomCeiling->id)
        ->set('removeEverywhere', true)
        ->call('remove');

    expect(roomsWithCeiling())->toBe([])
        ->and(EstimateSurface::query()->whereKey($bedroomWalls->id)->exists())->toBeTrue();

    // Work that is in one room only is not offered the choice.
    estimateBuilder()
        ->call('confirmRemove', 'surface', $bedroomWalls->id)
        ->assertSet('removingElsewhere', 0)
        ->assertDontSeeHtml('data-test="remove-everywhere"');
});
