<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\ProjectSize;
use App\Models\User;
use Livewire\Livewire;

test('there are exactly three sizes, with the approved starting values', function () {
    $sizes = ProjectSize::query()->ordered()->get();

    expect($sizes->map(fn (ProjectSize $size) => [$size->key, $size->name, $size->min_value, $size->max_value])->all())->toBe([
        ['small', 'Small', 0, 1999],
        ['medium', 'Medium', 2000, 9999],
        ['large', 'Large', 10000, null],
    ]);

    expect($sizes->map->rangeLabel()->all())->toBe(['under $2,000', '$2,000 to $9,999', '$10,000 or more']);
});

test('owner and leadership see project sizes on the lead and sales lists page', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)
        ->get(route('settings.lists', ['list' => 'project_sizes']))
        ->assertOk()
        ->assertSee('Project Sizes')
        ->assertSeeInOrder(['Small', 'under $2,000', 'Medium', '$2,000 to $9,999', 'Large', '$10,000 or more'])
        ->assertSee('Medium starts at ($)')
        ->assertSee('Large starts at ($)');
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
]);

test('the project sizes tab can be reached from the other lists and back', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->assertSee('Google Ads')
        ->call('show', 'project_sizes')
        ->assertSet('list', 'project_sizes')
        ->assertSee('Change the values')
        ->assertDontSee('Google Ads')
        ->call('show', 'services')
        ->assertSee('Cabinet Painting')
        ->assertDontSee('Change the values');
});

test('the project sizes editor cannot be used without the lists permission', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    Livewire::actingAs($user)->test('pages::settings.project-sizes')->assertForbidden();
})->with([
    'finance' => Role::Finance,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
    'production manager' => Role::ProductionManager,
]);

test('leadership at a single location cannot use the project sizes editor', function () {
    $leader = User::factory()->withRoleAt(Role::Leadership, Location::factory()->create())->create();

    Livewire::actingAs($leader)->test('pages::settings.project-sizes')->assertForbidden();
});

test('saving is refused once the permission has been removed', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $page = Livewire::actingAs($leader)->test('pages::settings.project-sizes');

    $leader->accessGrants()->delete();
    $leader->refresh();

    $page->set('mediumStartsAt', '3000')->call('save')->assertForbidden();

    expect(ProjectSize::forKey(ProjectSize::MEDIUM)->min_value)->toBe(2000);
});

test('changing where medium and large start keeps the three sizes covering every value with no gap', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.project-sizes')
        ->assertSet('mediumStartsAt', '2000')
        ->assertSet('largeStartsAt', '10000')
        ->set('mediumStartsAt', '3000')
        ->set('largeStartsAt', '15000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['under $3,000', '$3,000 to $14,999', '$15,000 or more']);

    $ranges = ProjectSize::query()->ordered()->get()->map(fn (ProjectSize $size) => [$size->min_value, $size->max_value])->all();

    expect($ranges)->toBe([[0, 2999], [3000, 14999], [15000, null]]);
});

test('values that would leave a size empty or out of order are refused', function (string $medium, string $large, string $field) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.project-sizes')
        ->set('mediumStartsAt', $medium)
        ->set('largeStartsAt', $large)
        ->call('save')
        ->assertHasErrors($field);

    expect(ProjectSize::forKey(ProjectSize::MEDIUM)->min_value)->toBe(2000);
    expect(ProjectSize::forKey(ProjectSize::LARGE)->min_value)->toBe(10000);
})->with([
    'medium at zero' => ['0', '10000', 'mediumStartsAt'],
    'medium blank' => ['', '10000', 'mediumStartsAt'],
    'not a whole number' => ['2000.50', '10000', 'mediumStartsAt'],
    'large the same as medium' => ['5000', '5000', 'largeStartsAt'],
    'large below medium' => ['5000', '4000', 'largeStartsAt'],
    'negative' => ['-1', '10000', 'mediumStartsAt'],
]);

test('a project size cannot be deleted', function () {
    expect(fn () => ProjectSize::forKey(ProjectSize::SMALL)->delete())->toThrow(LogicException::class);
    expect(ProjectSize::query()->count())->toBe(3);
});
