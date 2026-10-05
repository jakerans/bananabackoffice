<?php

use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Locations\DeactivateLocation;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('a location with active leads cannot be deactivated, and says how many are in the way', function (int $leads, string $count) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Lead::factory()->count($leads)->at($lakeview)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('deactivate', $lakeview->id)
        ->assertHasErrors('deactivation')
        ->assertSee('This location cannot be deactivated yet')
        ->assertSee("Lakeview still has {$count}");

    expect($lakeview->fresh()->is_active)->toBeTrue();
    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'one new lead' => [1, '1 active lead.'],
    'several leads' => [3, '3 active leads.'],
]);

test('a lead being worked blocks deactivation too, and nothing overrides the refusal', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    Lead::factory()->at($lakeview)->working()->create();

    $this->actingAs(User::factory()->withRoleEverywhere(Role::Owner)->create());

    expect(fn () => app(DeactivateLocation::class)->handle($lakeview))->toThrow(ValidationException::class);

    expect($lakeview->fresh()->is_active)->toBeTrue();
});

test('a location can be deactivated once its leads have been moved away', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $lead = Lead::factory()->at($lakeview)->create();
    // Leads elsewhere, and unrouted ones, are not in the way.
    Lead::factory()->at($northside)->create();
    Lead::factory()->create();

    $this->actingAs($owner);

    app(ReturnLeadToCentral::class)->handle($lead, 'Closing the Lakeview branch.', $owner);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('deactivate', $lakeview->id)
        ->assertHasNoErrors();

    expect($lakeview->fresh()->is_active)->toBeFalse();
});
