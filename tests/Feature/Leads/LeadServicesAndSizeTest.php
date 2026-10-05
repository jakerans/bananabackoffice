<?php

use App\Actions\Lists\UpdateProjectSizeRanges;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\ProjectSize;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function phoneCallId(): int
{
    return LeadChannel::query()->where('name', 'Phone Call')->sole()->id;
}

/*
|--------------------------------------------------------------------------
| Project size: required, one tap, never chosen for you
|--------------------------------------------------------------------------
*/

test('a lead cannot be saved without a project size, and none is chosen for you', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->assertSet('projectSizeId', null)
        ->set('name', 'Maria Lopez')
        ->set('phone', '555-201-3344')
        ->set('channelId', phoneCallId())
        ->set('serviceIds', oneService())
        ->call('save')
        ->assertHasErrors(['projectSizeId' => 'Choose a project size. It is your best judgment, not an estimate.']);

    expect(Lead::query()->count())->toBe(0);
});

test('the three sizes are offered with the value each covers', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('leads.create'))
        ->assertOk()
        ->assertSeeInOrder(['Small', 'under $2,000', 'Medium', '$2,000 to $9,999', 'Large', '$10,000 or more']);
});

test('the chosen size is stored with the value range it covered at the time', function (string $key, int $min, ?int $max) {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('serviceIds', oneService())
        ->set('projectSizeId', projectSizeId($key))
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->projectSize->key)->toBe($key);
    expect($lead->project_size_min_value)->toBe($min);
    expect($lead->project_size_max_value)->toBe($max);
})->with([
    'small' => [ProjectSize::SMALL, 0, 1999],
    'medium' => [ProjectSize::MEDIUM, 2000, 9999],
    'large' => [ProjectSize::LARGE, 10000, null],
]);

test('a size that does not exist is refused', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('serviceIds', oneService())
        ->set('projectSizeId', 999999)
        ->call('save')
        ->assertHasErrors('projectSizeId');

    expect(Lead::query()->count())->toBe(0);
});

test('a lead keeps the range it was classified under when the ranges are changed later', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $earlier = Lead::factory()->at($lakeview)->sized(ProjectSize::MEDIUM)->create(['inquiry_name' => 'Maria Lopes']);

    app(UpdateProjectSizeRanges::class)->handle(3000, 15000);

    // An unrelated correction, keeping the same size, does not reclassify it.
    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $earlier])
        ->assertSee('Medium')
        ->assertSee('$2,000 to $9,999 when classified')
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->call('update')
        ->assertHasNoErrors();

    $earlier->refresh();

    expect($earlier->inquiry_name)->toBe('Maria Lopez');
    expect($earlier->projectSize->key)->toBe(ProjectSize::MEDIUM);
    expect([$earlier->project_size_min_value, $earlier->project_size_max_value])->toBe([2000, 9999]);

    // A Lead classified after the change gets the new range.
    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('name', 'Later Lena')
        ->set('channelId', phoneCallId())
        ->set('serviceIds', oneService())
        ->set('projectSizeId', projectSizeId(ProjectSize::MEDIUM))
        ->call('save')
        ->assertHasNoErrors();

    $later = Lead::query()->where('inquiry_name', 'Later Lena')->sole();

    expect([$later->project_size_min_value, $later->project_size_max_value])->toBe([3000, 14999]);
});

test('reclassifying a lead to a different size records the range that size covers now', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->sized(ProjectSize::SMALL)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->assertSet('projectSizeId', projectSizeId(ProjectSize::SMALL))
        ->set('projectSizeId', projectSizeId(ProjectSize::LARGE))
        ->call('update')
        ->assertHasNoErrors();

    $lead->refresh();

    expect($lead->projectSize->key)->toBe(ProjectSize::LARGE);
    expect([$lead->project_size_min_value, $lead->project_size_max_value])->toBe([10000, null]);
});

test('a lead recorded before sizes existed shows as not classified and must be given a size when corrected', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->unclassified()->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Not classified')
        ->call('edit')
        ->call('update')
        ->assertHasErrors('projectSizeId')
        ->set('projectSizeId', projectSizeId(ProjectSize::MEDIUM))
        ->call('update')
        ->assertHasNoErrors();

    expect($lead->fresh()->projectSize->key)->toBe(ProjectSize::MEDIUM);
});

test('the size is kept apart from any estimate or sold amount', function () {
    $columns = collect(DB::getSchemaBuilder()->getColumnListing('leads'));

    expect($columns)->toContain('project_size_id', 'project_size_min_value', 'project_size_max_value');
    expect($columns->filter(fn (string $column) => str_contains($column, 'price') || str_contains($column, 'amount') || str_contains($column, 'estimate')))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Services: at least one, and an explicit primary when there are several
|--------------------------------------------------------------------------
*/

test('a lead cannot be saved without a service, and none is chosen for you', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->assertSet('serviceIds', [])
        ->assertSet('primaryServiceId', null)
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('projectSizeId', projectSizeId())
        ->call('save')
        ->assertHasErrors(['serviceIds' => 'Choose at least one service.']);

    expect(Lead::query()->count())->toBe(0);
});

test('a single service is the primary without being asked', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService('Cabinet Painting'))
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->services->pluck('name')->all())->toBe(['Cabinet Painting']);
    expect($lead->primaryService()->name)->toBe('Cabinet Painting');
});

test('a lead may have several services with one marked primary', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', [
            (string) serviceNamed('Deck / Fence')->id,
            (string) serviceNamed('Exterior Painting')->id,
            (string) serviceNamed('Interior Painting')->id,
        ])
        ->set('primaryServiceId', (string) serviceNamed('Deck / Fence')->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->services->pluck('name')->all())->toBe(['Interior Painting', 'Exterior Painting', 'Deck / Fence']);
    expect($lead->primaryService()->name)->toBe('Deck / Fence');
    expect(DB::table('lead_service')->where('lead_id', $lead->id)->where('is_primary', true)->count())->toBe(1);
});

test('with several services the primary must be named: it is never chosen by list order', function (?Closure $primary) {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', [(string) serviceNamed('Deck / Fence')->id, (string) serviceNamed('Exterior Painting')->id])
        ->set('primaryServiceId', $primary === null ? null : $primary())
        ->call('save')
        ->assertHasErrors(['primaryServiceId' => 'Choose which service is the main one.']);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'none named' => [null],
    'one that was not chosen' => [fn () => (string) serviceNamed('Cabinet Painting')->id],
    'one that does not exist' => [fn () => '999999'],
]);

test('a deactivated service is not offered and is refused if submitted', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $cabinets = serviceNamed('Cabinet Painting');

    $cabinets->forceFill(['is_active' => false])->save();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->assertDontSee('Cabinet Painting')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', [(string) $cabinets->id])
        ->call('save')
        ->assertHasErrors('serviceIds.0');

    expect(Lead::query()->count())->toBe(0);
});

test('a service that does not exist is refused', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', phoneCallId())
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', ['999999'])
        ->call('save')
        ->assertHasErrors('serviceIds.0');

    expect(Lead::query()->count())->toBe(0);
});

test('the database refuses a second primary service for one lead', function () {
    $lead = Lead::factory()->withoutServices()->create();

    $lead->services()->attach(serviceNamed('Interior Painting')->id, ['is_primary' => true]);

    expect(fn () => $lead->services()->attach(serviceNamed('Exterior Painting')->id, ['is_primary' => true]))
        ->toThrow(UniqueConstraintViolationException::class);

    // Any number of services that are not the primary is fine.
    $lead->services()->attach(serviceNamed('Exterior Painting')->id, ['is_primary' => false]);
    $lead->services()->attach(serviceNamed('Deck / Fence')->id, ['is_primary' => false]);

    expect($lead->services()->count())->toBe(3);
});

test('the services of a lead can be changed, and removing the primary means another must be named', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $lead->services()->sync([
        serviceNamed('Interior Painting')->id => ['is_primary' => true],
        serviceNamed('Cabinet Painting')->id => ['is_primary' => false],
    ]);

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $page->assertSeeInOrder(['Interior Painting', 'Main', 'Cabinet Painting'])
        ->call('edit')
        ->assertSet('serviceIds', [(string) serviceNamed('Interior Painting')->id, (string) serviceNamed('Cabinet Painting')->id])
        ->assertSet('primaryServiceId', (string) serviceNamed('Interior Painting')->id)
        // Interior, the primary, is dropped. Two remain and neither has been named.
        ->set('serviceIds', [(string) serviceNamed('Cabinet Painting')->id, (string) serviceNamed('Deck / Fence')->id])
        ->call('update')
        ->assertHasErrors(['primaryServiceId' => 'Choose which service is the main one.']);

    expect($lead->fresh()->services->pluck('name')->all())->toBe(['Interior Painting', 'Cabinet Painting']);

    $page->set('primaryServiceId', (string) serviceNamed('Deck / Fence')->id)
        ->call('update')
        ->assertHasNoErrors();

    $lead->refresh();

    expect($lead->services->pluck('name')->all())->toBe(['Cabinet Painting', 'Deck / Fence']);
    expect($lead->primaryService()->name)->toBe('Deck / Fence');
});

test('a lead cannot be corrected down to no services', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('serviceIds', [])
        ->call('update')
        ->assertHasErrors(['serviceIds' => 'Choose at least one service.']);

    expect($lead->fresh()->services)->toHaveCount(1);
});

test('a lead recorded before services existed must be given one when corrected', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->withoutServices()->create();

    expect($lead->services()->count())->toBe(0);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->call('update')
        ->assertHasErrors('serviceIds')
        ->set('serviceIds', oneService('Exterior Painting'))
        ->call('update')
        ->assertHasNoErrors();

    expect($lead->fresh()->primaryService()->name)->toBe('Exterior Painting');
});

test('a lead keeps a service that was deactivated after it was chosen, but cannot be given another deactivated one', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopes']);

    $cabinets = serviceNamed('Cabinet Painting');
    $decks = serviceNamed('Deck / Fence');

    $lead->services()->sync([$cabinets->id => ['is_primary' => true]]);

    $cabinets->forceFill(['is_active' => false])->save();
    $decks->forceFill(['is_active' => false])->save();

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $page->assertSee('Cabinet Painting')
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->call('update')
        ->assertHasNoErrors();

    expect($lead->fresh()->services->pluck('name')->all())->toBe(['Cabinet Painting']);

    $page->call('edit')
        ->set('serviceIds', [(string) $cabinets->id, (string) $decks->id])
        ->set('primaryServiceId', (string) $cabinets->id)
        ->call('update')
        ->assertHasErrors('serviceIds');

    expect($lead->fresh()->services->pluck('name')->all())->toBe(['Cabinet Painting']);
});

test('the list shows each lead\'s main service and size', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = Lead::factory()->at($lakeview)->sized(ProjectSize::LARGE)->create(['inquiry_name' => 'Maria Lopez']);

    $lead->services()->sync([
        serviceNamed('Exterior Painting')->id => ['is_primary' => true],
        serviceNamed('Deck / Fence')->id => ['is_primary' => false],
    ]);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Maria Lopez', 'Exterior Painting', '+1', 'Large']);
});

test('a service cannot be deleted while leads point at it, or at all', function () {
    Lead::factory()->create();

    $service = serviceNamed('Interior Painting');

    expect(fn () => $service->delete())->toThrow(LogicException::class);
    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
});
