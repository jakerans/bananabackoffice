<?php

use App\Actions\Subcontractors\ChangeComplianceRequirements;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\Subcontractor;
use App\Models\SubcontractorComplianceRecord;
use App\Models\User;
use App\Support\Subcontractors\Compliance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Subcontractor compliance, step 12b (docs/slice-12-subcontractors-plan.md;
 * product-architecture.md, 7.2 and 7.3; decision 148, questions 5 to 8).
 *
 * It is Wednesday 14 October 2026 at Lakeview. Sandia Brush Co. works there
 * and is Active.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->hal = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);

    $this->subcontractor = app(ChangeSubcontractor::class)->add([
        'businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142',
        'locationIds' => [(string) $this->lakeview->id],
    ], $this->pat);
    $this->subcontractor = app(ChangeSubcontractor::class)->moveTo($this->subcontractor, 'active', null, $this->pat);

    $this->base = ComplianceRequirement::query()->whereNotNull('base_key')->pluck('id', 'base_key');
});

/**
 * Record an item for Sandia as Pat.
 */
function complianceRecorded($test, string $baseKey, ?string $received = '2026-10-01', ?string $expires = null, bool $coverage = false, ?User $by = null): SubcontractorComplianceRecord
{
    return app(RecordCompliance::class)->handle($test->subcontractor, $test->base[$baseKey], $received, $expires, $coverage, null, $by ?? $test->pat);
}

function complianceOf($test): Compliance
{
    return Compliance::for(Subcontractor::query()->with(['locations', 'complianceRecords'])->findOrFail($test->subcontractor->id));
}

/*
|--------------------------------------------------------------------------
| What is required, and where it stands
|--------------------------------------------------------------------------
*/

test('the base checklist applies to everyone, and nothing is recorded yet', function () {
    $compliance = complianceOf($this);

    expect(array_map(fn (array $item): string => $item['requirement']->name, $compliance->items))->toBe([
        'W-9',
        'General liability insurance',
        'Workers\' compensation with waiver of subrogation',
        'Signed subcontractor agreement',
    ]);
    expect(array_column($compliance->items, 'state'))->toBe(['missing', 'missing', 'missing', 'missing']);
    expect($compliance->isCompliant())->toBeFalse();
});

test('a subcontractor is compliant once every item is received and none has expired', function () {
    complianceRecorded($this, 'w9');
    complianceRecorded($this, 'general_liability', expires: '2027-06-30');
    complianceRecorded($this, 'workers_comp', coverage: true);

    expect(complianceOf($this)->missing())->toBe(['Signed subcontractor agreement']);

    complianceRecorded($this, 'agreement');

    $compliance = complianceOf($this);

    expect($compliance->isCompliant())->toBeTrue();
    expect(array_column($compliance->items, 'state'))->toBe(['current', 'current', 'covered', 'current']);
});

test('an item expiring within 30 days is marked, and one that has expired is missing again', function () {
    complianceRecorded($this, 'w9');
    complianceRecorded($this, 'agreement');
    complianceRecorded($this, 'workers_comp', coverage: true);
    complianceRecorded($this, 'general_liability', expires: '2026-11-12');

    // 29 days before it expires.
    expect(complianceOf($this)->expiringSoon())->toBe(['General liability insurance']);
    expect(complianceOf($this)->isCompliant())->toBeTrue();

    $this->travelTo('2026-10-12 15:00:00');

    expect(complianceOf($this)->expiringSoon())->toBe([]);

    // The day after it expires.
    $this->travelTo('2026-11-13 15:00:00');

    expect(complianceOf($this)->missing())->toBe(['General liability insurance']);
    expect(complianceOf($this)->items[1]['state'])->toBe(Compliance::EXPIRED);

    // Renewed, it is current again, and the earlier record is kept as it was.
    complianceRecorded($this, 'general_liability', received: '2026-11-13', expires: '2027-11-12');

    expect(complianceOf($this)->isCompliant())->toBeTrue();
    expect(SubcontractorComplianceRecord::query()->where('compliance_requirement_id', $this->base['general_liability'])->count())->toBe(2);
});

test('a Location\'s own requirement applies only to the subcontractors who work there', function () {
    $waiver = app(ChangeComplianceRequirements::class)->add($this->lakeview, ComplianceRequirement::NEW_MEXICO_WAIVER, true, $this->owner);

    expect(array_map(fn (array $item): string => $item['requirement']->name, complianceOf($this)->items))->toContain(ComplianceRequirement::NEW_MEXICO_WAIVER);

    $elsewhere = app(ChangeSubcontractor::class)->add(['businessName' => 'Harbor Hues', 'contactName' => 'Lee Park', 'email' => 'lee@example.com', 'locationIds' => [(string) $this->harbor->id]], $this->hal);

    expect(Compliance::for($elsewhere->fresh(['locations', 'complianceRecords']))->applies($waiver->id))->toBeFalse();

    // Taken away, it no longer counts; what was recorded against it is kept.
    app(RecordCompliance::class)->handle($this->subcontractor, $waiver->id, '2026-10-01', '2027-09-30', false, null, $this->pat);
    app(ChangeComplianceRequirements::class)->remove($this->lakeview, $waiver->id, $this->owner);

    expect(complianceOf($this)->applies($waiver->id))->toBeFalse();
    expect(SubcontractorComplianceRecord::query()->where('compliance_requirement_id', $waiver->id)->count())->toBe(1);
    expect(fn () => $waiver->fresh()->delete())->toThrow(LogicException::class);
});

/*
|--------------------------------------------------------------------------
| Recording
|--------------------------------------------------------------------------
*/

test('recording an item keeps the days, who recorded it and the note, and is in the history', function () {
    $record = app(RecordCompliance::class)->handle($this->subcontractor, $this->base['general_liability'], '2026-10-01', '2027-06-30', false, '  Acme Mutual, policy GL-1  ', $this->pat);

    expect([$record->received_on->toDateString(), $record->expires_on->toDateString()])->toBe(['2026-10-01', '2027-06-30']);
    expect($record->only(['uses_banana_coverage', 'note', 'recorded_by', 'recorded_by_name']))->toBe(['uses_banana_coverage' => false, 'note' => 'Acme Mutual, policy GL-1', 'recorded_by' => $this->pat->id, 'recorded_by_name' => 'Pat Production']);

    $event = AuditEvent::query()->where('action', AuditAction::SubcontractorComplianceRecorded)->sole();

    expect($event->values_after)->toEqual(['requirement' => 'General liability insurance', 'received_on' => '2026-10-01', 'expires_on' => '2027-06-30', 'uses_banana_coverage' => false]);
    expect($event->reason)->toBe('Acme Mutual, policy GL-1');

    // A record is kept as it was.
    expect(fn () => $record->forceFill(['note' => 'Changed'])->save())->toThrow(LogicException::class);
    expect(fn () => $record->delete())->toThrow(LogicException::class);

    // An item that does not expire keeps no expiry day.
    expect(complianceRecorded($this, 'w9', expires: '2027-01-01')->expires_on)->toBeNull();
});

test('what cannot be recorded is refused, and nothing is saved', function (string $baseKey, ?string $received, ?string $expires, bool $coverage, string $field, string $message) {
    try {
        complianceRecorded($this, $baseKey, $received, $expires, $coverage);
        $this->fail('It was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$message]]);
    }

    expect(SubcontractorComplianceRecord::query()->count())->toBe(0);
})->with([
    'no day received' => ['w9', '', null, false, 'complianceReceivedOn', 'Choose the day it was received.'],
    'received tomorrow' => ['w9', '2026-10-15', null, false, 'complianceReceivedOn', 'The day it was received cannot be in the future.'],
    'insurance with no expiry' => ['general_liability', '2026-10-01', '', false, 'complianceExpiresOn', 'Choose the day it expires.'],
    'insurance already expired' => ['general_liability', '2025-10-01', '2026-10-13', false, 'complianceExpiresOn', 'This has already expired. Record the renewed one.'],
    'Banana\'s coverage for a W-9' => ['w9', null, null, true, 'complianceCoverage', 'Banana\'s coverage cannot stand in for this.'],
]);

test('a requirement of another Location is refused', function () {
    $harborWaiver = app(ChangeComplianceRequirements::class)->add($this->harbor, 'Harbor license', false, $this->owner);

    expect(fn () => app(RecordCompliance::class)->handle($this->subcontractor, $harborWaiver->id, '2026-10-01', null, false, null, $this->pat))
        ->toThrow(ValidationException::class, 'This is not one of their requirements. Reload the page.');
});

test('who may record compliance', function (Closure $person, bool $may) {
    $person = $person($this);

    if ($may) {
        expect(complianceRecorded($this, 'w9', by: $person)->exists)->toBeTrue();

        return;
    }

    expect(fn () => complianceRecorded($this, 'w9', by: $person))->toThrow(AuthorizationException::class);
    expect(SubcontractorComplianceRecord::query()->count())->toBe(0);
})->with([
    'the Production Manager there' => [fn ($test) => $test->pat, true],
    'a Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => $test->owner, true],
    'a Production Manager elsewhere' => [fn ($test) => $test->hal, false],
    'a Sales Consultant there' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->create(), false],
]);

/*
|--------------------------------------------------------------------------
| A Location's requirements
|--------------------------------------------------------------------------
*/

test('a Location\'s requirements are added and taken away by whoever manages Locations', function () {
    $added = app(ChangeComplianceRequirements::class)->add($this->lakeview, '  City business license ', false, $this->owner);

    expect($added->only(['location_id', 'name', 'expires', 'allows_banana_coverage', 'is_active']))->toBe(['location_id' => $this->lakeview->id, 'name' => 'City business license', 'expires' => false, 'allows_banana_coverage' => false, 'is_active' => true]);
    expect(AuditEvent::query()->where('action', AuditAction::LocationComplianceRequirementAdded)->sole()->values_after)->toEqual(['requirement' => 'City business license', 'expires' => false]);

    expect(fn () => app(ChangeComplianceRequirements::class)->add($this->lakeview, 'city business LICENSE', false, $this->owner))->toThrow(ValidationException::class, 'This is already a requirement here.');
    expect(fn () => app(ChangeComplianceRequirements::class)->add($this->lakeview, 'W-9', false, $this->owner))->toThrow(ValidationException::class, 'This is already a requirement here.');
    expect(fn () => app(ChangeComplianceRequirements::class)->add($this->lakeview, ' ', false, $this->owner))->toThrow(ValidationException::class, 'Say what they must have.');

    // A Location Manager does not manage Locations.
    expect(fn () => app(ChangeComplianceRequirements::class)->add($this->lakeview, 'Another', false, $this->manager))->toThrow(AuthorizationException::class);
    expect(fn () => app(ChangeComplianceRequirements::class)->remove($this->lakeview, $added->id, $this->manager))->toThrow(AuthorizationException::class);

    // The base checklist cannot be taken away from a Location.
    expect(fn () => app(ChangeComplianceRequirements::class)->remove($this->lakeview, $this->base['w9'], $this->owner))->toThrow(ValidationException::class);

    app(ChangeComplianceRequirements::class)->remove($this->lakeview, $added->id, $this->owner);

    expect($added->fresh()->is_active)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::LocationComplianceRequirementRemoved)->count())->toBe(1);
});

test('settings adds the New Mexico waiver to a Location', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->call('editRequirements', $this->lakeview->id)
        ->assertSee('W-9')
        ->call('useNewMexicoWaiver')
        ->assertSet('requirementName', ComplianceRequirement::NEW_MEXICO_WAIVER)
        ->assertSet('requirementExpires', true)
        ->call('addRequirement')
        ->assertHasNoErrors()
        ->assertSee(ComplianceRequirement::NEW_MEXICO_WAIVER)
        ->call('addRequirement')
        ->assertHasErrors(['requirementName']);

    $waiver = ComplianceRequirement::query()->where('location_id', $this->lakeview->id)->sole();

    expect($waiver->expires)->toBeTrue();

    // Taking it away asks first, on the page itself.
    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->call('editRequirements', $this->lakeview->id)
        ->call('confirmRemoveRequirement', $waiver->id)
        ->assertSeeHtml('data-test="confirm-remove-location-requirement"')
        ->call('confirmRemoveRequirement', null)
        ->assertDontSeeHtml('data-test="confirm-remove-location-requirement"')
        ->call('confirmRemoveRequirement', $waiver->id)
        ->call('removeRequirement')
        ->assertHasNoErrors();

    expect($waiver->fresh()->is_active)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Where people see it
|--------------------------------------------------------------------------
*/

test('the page shows what is missing, and recording from it makes them compliant', function () {
    complianceRecorded($this, 'w9');
    complianceRecorded($this, 'agreement');
    complianceRecorded($this, 'workers_comp', coverage: true);

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->assertSeeHtml('data-test="subcontractor-pending-compliance"')
        ->assertSee('Still needs: General liability insurance.')
        ->assertSee('Uses Banana\'s coverage')
        ->call('openCompliance', $this->base['general_liability'])
        ->assertSet('complianceReceivedOn', '2026-10-14')
        ->call('saveCompliance')
        ->assertHasErrors(['complianceExpiresOn'])
        ->set('complianceExpiresOn', '2026-11-01')
        ->call('saveCompliance')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="subcontractor-compliant"')
        ->assertSee('Expires soon')
        ->assertSee('expires on Nov 1, 2026')
        ->assertSee('General liability insurance recorded')
        ->assertSee('Workers\' compensation with waiver of subrogation: uses Banana\'s coverage');

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])->call('openCompliance', 987654)->assertStatus(409);
});

test('the roster marks who is pending compliance and whose items expire soon', function () {
    $list = fn () => (string) str(Livewire::actingAs($this->pat)->test('pages::production.subcontractors')->html())->after('data-test="subcontractors-roster"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();

    expect($list())->toContain('Sandia Brush Co.')->toContain('Pending compliance');

    complianceRecorded($this, 'w9');
    complianceRecorded($this, 'agreement');
    complianceRecorded($this, 'workers_comp', coverage: true);
    complianceRecorded($this, 'general_liability', expires: '2026-11-01');

    expect($list())->not->toContain('Pending compliance')->toContain('Expires soon');
});
