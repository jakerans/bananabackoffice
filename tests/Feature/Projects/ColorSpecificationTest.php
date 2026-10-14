<?php

use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\ChangeColorSpecification;
use App\Actions\Projects\RecordColorApproval;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\ColorReadiness;
use App\Enums\Role;
use App\Enums\Sheen;
use App\Models\AuditEvent;
use App\Models\ColorSpecification;
use App\Models\ColorSpecificationLine;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * The Color Specification, step 10b (docs/slice-10-pre-production-plan.md;
 * product-architecture.md, 2, 6.4 and 6.8; decision 141): colors by area and
 * surface, the customer's approval, a new version when approved colors
 * change, and Color Readiness as a state of its own.
 *
 * At Lakeview, "Oak St" was sold by Sam Sales and handed over, and Pat
 * Production took it on Thursday 8 October 2026 at 3:00 pm Eastern. Tests
 * start at that moment.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    $this->project = $this->project->fresh();
});

/**
 * A line as a person types it: the living room walls, unless told otherwise.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function colorLine(array $with = []): array
{
    return [
        'area' => 'Living room',
        'surface' => 'Walls',
        'manufacturer' => 'Sherwin-Williams',
        'colorName' => 'Agreeable Gray',
        'colorCode' => 'SW 7029',
        'sheen' => 'eggshell',
        'product' => 'Duration Home',
        'note' => '',
        ...$with,
    ];
}

/**
 * Write the living room walls and trim, and record the customer's approval.
 */
function approvedColors($test): ColorSpecification
{
    app(ChangeColorSpecification::class)->addLine($test->project, colorLine(), $test->pat);
    app(ChangeColorSpecification::class)->addLine($test->project, colorLine(['surface' => 'Trim', 'colorName' => 'Pure White', 'colorCode' => 'SW 7005', 'sheen' => 'semi_gloss']), $test->pat);
    app(RecordColorApproval::class)->handle($test->project, 'Pat Customer', 'phone', '2026-10-08', $test->pat);

    return $test->project->colorSpecifications()->firstOrFail();
}

dataset('people around a project\'s colors', [
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a manager at the location' => [fn ($test) => $test->manager, true],
    'an Owner' => [fn ($test) => $test->owner, true],
    'another Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson who sold it' => [fn ($test) => $test->sam, false],
    'a manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

/*
|--------------------------------------------------------------------------
| Writing the colors
|--------------------------------------------------------------------------
*/

test('the first line starts version 1, and the colors are in progress', function () {
    expect($this->project->color_readiness)->toBe(ColorReadiness::NotStarted);
    expect(ChangeColorSpecification::readiness($this->project))->toBe(ColorReadiness::NotStarted);

    $line = app(ChangeColorSpecification::class)->addLine($this->project, colorLine(['note' => '  Two coats.  ']), $this->pat);

    $version = ColorSpecification::query()->sole();

    expect($version->only(['project_id', 'version', 'created_by', 'created_by_name']))
        ->toBe(['project_id' => $this->project->id, 'version' => 1, 'created_by' => $this->pat->id, 'created_by_name' => 'Pat Production']);
    expect($version->isApproved())->toBeFalse();

    expect($line->only(['color_specification_id', 'position', 'area', 'surface', 'manufacturer', 'color_name', 'color_code', 'product', 'note']))->toBe([
        'color_specification_id' => $version->id,
        'position' => 1,
        'area' => 'Living room',
        'surface' => 'Walls',
        'manufacturer' => 'Sherwin-Williams',
        'color_name' => 'Agreeable Gray',
        'color_code' => 'SW 7029',
        'product' => 'Duration Home',
        'note' => 'Two coats.',
    ]);
    expect($line->sheen)->toBe(Sheen::Eggshell);
    expect($line->color())->toBe('Agreeable Gray (SW 7029)');
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::InProgress);

    // Starting is in the customer's Activity; each line is in the audit record only.
    $started = AuditEvent::query()->where('action', AuditAction::ProjectColorsStarted)->sole();

    expect($started->subject_id)->toBe($this->project->id);
    expect($started->actor_id)->toBe($this->pat->id);
    expect($started->values_after)->toEqual(['version' => 1]);
    expect(CustomerActivity::label(AuditAction::ProjectColorsStarted))->toBe('Colors started');

    $added = AuditEvent::query()->where('action', AuditAction::ProjectColorLineAdded)->sole();

    expect($added->values_after)->toEqual(['line' => 'Living room walls, Sherwin-Williams, Agreeable Gray (SW 7029), Eggshell, Duration Home', 'version' => 1]);
    expect(CustomerActivity::actions())->not->toContain(AuditAction::ProjectColorLineAdded);

    // The code, the product and the note are optional.
    $bare = app(ChangeColorSpecification::class)->addLine($this->project, colorLine(['surface' => 'Ceiling', 'colorCode' => '', 'product' => '', 'sheen' => 'flat']), $this->pat);

    expect($bare->only(['position', 'color_code', 'product', 'note']))->toBe(['position' => 2, 'color_code' => null, 'product' => null, 'note' => null]);
    expect(ColorSpecification::query()->count())->toBe(1);
});

test('a line is changed and removed while the colors are being written, in the same version', function () {
    $walls = app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $this->pat);
    $trim = app(ChangeColorSpecification::class)->addLine($this->project, colorLine(['surface' => 'Trim', 'sheen' => 'semi_gloss']), $this->pat);

    app(ChangeColorSpecification::class)->changeLine($this->project, $walls->id, colorLine(['colorName' => 'Repose Gray', 'colorCode' => 'SW 7015']), $this->manager);

    expect($walls->fresh()->color())->toBe('Repose Gray (SW 7015)');

    $changed = AuditEvent::query()->where('action', AuditAction::ProjectColorLineChanged)->sole();

    expect($changed->actor_id)->toBe($this->manager->id);
    expect($changed->values_before)->toEqual(['line' => 'Living room walls, Sherwin-Williams, Agreeable Gray (SW 7029), Eggshell, Duration Home', 'note' => null]);
    expect($changed->values_after)->toEqual(['line' => 'Living room walls, Sherwin-Williams, Repose Gray (SW 7015), Eggshell, Duration Home', 'note' => null, 'version' => 1]);

    // Saved unchanged, nothing is written.
    app(ChangeColorSpecification::class)->changeLine($this->project, $walls->id, colorLine(['colorName' => 'Repose Gray', 'colorCode' => 'SW 7015']), $this->pat);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectColorLineChanged)->count())->toBe(1);

    app(ChangeColorSpecification::class)->removeLine($this->project, $trim->id, $this->pat);

    expect(ColorSpecificationLine::query()->pluck('id')->all())->toBe([$walls->id]);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectColorLineRemoved)->sole()->values_before)->toEqual(['line' => 'Living room trim, Sherwin-Williams, Agreeable Gray (SW 7029), Semi-gloss, Duration Home']);
    expect(ColorSpecification::query()->count())->toBe(1);

    // With every line of a first version taken out again, it is as if nothing was written.
    app(ChangeColorSpecification::class)->removeLine($this->project, $walls->id, $this->pat);

    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::NotStarted);
});

test('a line is refused when something it needs is missing or too long, and nothing is started', function (array $with, string $field, string $message) {
    try {
        app(ChangeColorSpecification::class)->addLine($this->project, colorLine($with), $this->pat);

        $this->fail('The line was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$message]]);
    }

    // Not even an empty version 1.
    expect(ColorSpecification::query()->count())->toBe(0);
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::NotStarted);
    expect(AuditEvent::query()->whereIn('action', [AuditAction::ProjectColorsStarted, AuditAction::ProjectColorLineAdded])->count())->toBe(0);
})->with([
    'no area' => [['area' => '  '], 'line.area', 'Say which room or area.'],
    'no surface' => [['surface' => ''], 'line.surface', 'Say which surface: walls, trim, ceiling...'],
    'no manufacturer' => [['manufacturer' => ''], 'line.manufacturer', 'Say who makes the paint.'],
    'no color name' => [['colorName' => ''], 'line.colorName', 'Give the color\'s name.'],
    'no sheen' => [['sheen' => ''], 'line.sheen', 'Choose the sheen.'],
    'a sheen that is not on the list' => [['sheen' => 'shiny'], 'line.sheen', 'Choose the sheen.'],
    'an area of 121 characters' => [['area' => str_repeat('a', 121)], 'line.area', 'Keep this to 120 characters or fewer.'],
    'a code of 61 characters' => [['colorCode' => str_repeat('1', 61)], 'line.colorCode', 'Keep this to 60 characters or fewer.'],
    'a note of 501 characters' => [['note' => str_repeat('a', 501)], 'line.note', 'Keep this to 500 characters or fewer.'],
]);

test('who may write the colors', function (Closure $who, bool $allowed) {
    $add = fn () => app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $who($this));

    if ($allowed) {
        $add();

        expect(ColorSpecificationLine::query()->count())->toBe(1);

        return;
    }

    expect($add)->toThrow(AuthorizationException::class);
    expect(ColorSpecification::query()->count())->toBe(0);
})->with('people around a project\'s colors');

test('a line that is not one of this project\'s is refused', function () {
    app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $this->pat);

    $other = soldProject($this->lakeview, $this->sam);
    completeHandoff($other, $this->sam);
    app(TakeProject::class)->handle($other, $this->pat);
    $theirs = app(ChangeColorSpecification::class)->addLine($other->fresh(), colorLine(['area' => 'Kitchen']), $this->pat);

    expect(fn () => app(ChangeColorSpecification::class)->changeLine($this->project, $theirs->id, colorLine(), $this->pat))->toThrow(ValidationException::class, 'This line is no longer here.');
    expect(fn () => app(ChangeColorSpecification::class)->removeLine($this->project, $theirs->id, $this->pat))->toThrow(ValidationException::class, 'This line is no longer here.');
    expect(fn () => app(ChangeColorSpecification::class)->removeLine($this->project, 987654, $this->pat))->toThrow(ValidationException::class, 'This line is no longer here.');

    expect($theirs->fresh()->area)->toBe('Kitchen');
});

test('a project with no production manager, or whose sale was corrected, has no colors to write', function () {
    $waiting = soldProject($this->lakeview, $this->sam);
    completeHandoff($waiting, $this->sam);

    expect(fn () => app(ChangeColorSpecification::class)->addLine($waiting, colorLine(), $this->manager))->toThrow(AuthorizationException::class);

    app(CancelProjectForCorrectedSale::class)->apply($this->project->proposal, 'Wrong lead.', $this->manager);

    expect(fn () => app(ChangeColorSpecification::class)->addLine($this->project->fresh(), colorLine(), $this->pat))->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| With the customer, and approved
|--------------------------------------------------------------------------
*/

test('colors that are written can be marked as with the customer, and changing them takes that back', function () {
    expect(fn () => app(ChangeColorSpecification::class)->sendForApproval($this->project, $this->pat))->toThrow(ValidationException::class, 'Write the colors down before they go to the customer.');

    $walls = app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $this->pat);

    $this->travelTo('2026-10-09 15:00:00');
    app(ChangeColorSpecification::class)->sendForApproval($this->project, $this->pat);

    $version = ColorSpecification::query()->sole();

    expect($version->sent_for_approval_at->toDateTimeString())->toBe('2026-10-09 15:00:00');
    expect($version->sent_for_approval_by_name)->toBe('Pat Production');
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::AwaitingApproval);
    expect(CustomerActivity::label(AuditAction::ProjectColorsSentForApproval))->toBe('Colors with the customer for approval');

    // Said twice, it is recorded once.
    app(ChangeColorSpecification::class)->sendForApproval($this->project, $this->manager);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectColorsSentForApproval)->count())->toBe(1);
    expect($version->fresh()->sent_for_approval_by_name)->toBe('Pat Production');

    // A change means the customer was asked about something else.
    app(ChangeColorSpecification::class)->changeLine($this->project, $walls->id, colorLine(['sheen' => 'satin']), $this->pat);

    expect($version->fresh()->sent_for_approval_at)->toBeNull();
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::InProgress);
});

test('the customer\'s approval is recorded with who, how and when, and the colors are approved', function () {
    app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $this->pat);

    $this->travelTo('2026-10-12 15:00:00');
    app(RecordColorApproval::class)->handle($this->project, '  Pat Customer  ', 'phone', '2026-10-10', $this->pat);

    $version = ColorSpecification::query()->sole();

    expect($version->isApproved())->toBeTrue();
    expect($version->approved_at->toDateTimeString())->toBe('2026-10-12 15:00:00');
    expect($version->approved_on->toDateString())->toBe('2026-10-10');
    expect($version->only(['approved_by_customer', 'approval_recorded_by', 'approval_recorded_by_name']))
        ->toBe(['approved_by_customer' => 'Pat Customer', 'approval_recorded_by' => $this->pat->id, 'approval_recorded_by_name' => 'Pat Production']);
    expect($version->approval_method)->toBe(ColorApprovalMethod::Phone);
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::Approved);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectColorsApproved)->sole();

    expect($event->actor_id)->toBe($this->pat->id);
    expect($event->values_before)->toEqual(['color_readiness' => 'in_progress']);
    expect($event->values_after)->toEqual(['color_readiness' => 'approved', 'version' => 1, 'approved_by' => 'Pat Customer', 'how' => 'phone', 'approved_on' => '2026-10-10', 'lines' => 1]);
    expect(CustomerActivity::label(AuditAction::ProjectColorsApproved))->toBe('Colors approved by the customer');

    // There is nothing left to approve.
    expect(fn () => app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-10', $this->pat))
        ->toThrow(ValidationException::class, 'There are no colors waiting to be approved.');
});

test('an approval is refused without who, how or a day that has come, and when nothing is written', function (?string $who, ?string $how, ?string $day, string $field, string $message) {
    app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $this->pat);

    try {
        app(RecordColorApproval::class)->handle($this->project, $who, $how, $day, $this->pat);

        $this->fail('The approval was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$message]]);
    }

    expect(ColorSpecification::query()->sole()->isApproved())->toBeFalse();
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::InProgress);
})->with([
    'nobody named' => ['  ', 'phone', '2026-10-08', 'approvedBy', 'Say who approved the colors.'],
    'no way given' => ['Pat Customer', '', '2026-10-08', 'approvalMethod', 'Say how they approved: in person, by phone, by text or by email.'],
    'a way that is not on the list' => ['Pat Customer', 'carrier pigeon', '2026-10-08', 'approvalMethod', 'Say how they approved: in person, by phone, by text or by email.'],
    'no day' => ['Pat Customer', 'phone', '', 'approvedOn', 'Choose the day they approved.'],
    'tomorrow' => ['Pat Customer', 'phone', '2026-10-09', 'approvedOn', 'The approval cannot be on a day that has not come yet.'],
]);

test('colors with no line cannot be approved, and only its production manager or a manager records an approval', function () {
    expect(fn () => app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-08', $this->pat))
        ->toThrow(ValidationException::class, 'There are no colors waiting to be approved.');

    app(ChangeColorSpecification::class)->addLine($this->project, colorLine(), $this->pat);

    foreach ([$this->sam, $this->pam] as $other) {
        expect(fn () => app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-08', $other))->toThrow(AuthorizationException::class);
        expect(fn () => app(ChangeColorSpecification::class)->sendForApproval($this->project, $other))->toThrow(AuthorizationException::class);
    }

    app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'in_person', '2026-10-08', $this->manager);

    expect(ColorSpecification::query()->sole()->approval_recorded_by_name)->toBe('Morgan Manager');
});

/*
|--------------------------------------------------------------------------
| Changing approved colors
|--------------------------------------------------------------------------
*/

test('changing approved colors starts a new version, keeps the approved one exactly, and needs approval again (decision 141)', function () {
    $approved = approvedColors($this);
    $before = $approved->lines->map->only(['id', 'position', 'area', 'surface', 'manufacturer', 'color_name', 'color_code', 'product', 'note'])->all();
    $walls = $approved->lines->firstWhere('surface', 'Walls');

    $this->travelTo('2026-10-20 15:00:00');
    $changed = app(ChangeColorSpecification::class)->changeLine($this->project, $walls->id, colorLine(['colorName' => 'Repose Gray', 'colorCode' => 'SW 7015']), $this->manager);

    $versions = $this->project->colorSpecifications()->with('lines')->get();

    expect($versions->pluck('version')->all())->toBe([2, 1]);

    // Version 1 is as the customer approved it.
    expect($versions[1]->isApproved())->toBeTrue();
    expect($versions[1]->lines->map->only(['id', 'position', 'area', 'surface', 'manufacturer', 'color_name', 'color_code', 'product', 'note'])->all())->toBe($before);

    // Version 2 is its copy with the change, written by whoever changed it, and not approved.
    expect($versions[0]->only(['created_by_name', 'approved_at', 'approved_by_customer']))->toBe(['created_by_name' => 'Morgan Manager', 'approved_at' => null, 'approved_by_customer' => null]);
    expect($versions[0]->lines->map(fn (ColorSpecificationLine $line): string => $line->surface.': '.$line->color())->all())->toBe(['Walls: Repose Gray (SW 7015)', 'Trim: Pure White (SW 7005)']);
    expect($changed->color_specification_id)->toBe($versions[0]->id);
    expect($changed->id)->not->toBe($walls->id);
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::InProgress);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectColorsNewVersion)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->values_before)->toEqual(['version' => 1, 'color_readiness' => 'approved']);
    expect($event->values_after)->toEqual(['version' => 2]);
    expect(CustomerActivity::label(AuditAction::ProjectColorsNewVersion))->toBe('Approved colors changed: a new version');

    // More changes go into the same version 2 until it is approved.
    app(ChangeColorSpecification::class)->addLine($this->project, colorLine(['area' => 'Kitchen']), $this->pat);

    expect($this->project->colorSpecifications()->count())->toBe(2);

    app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'email', '2026-10-20', $this->pat);

    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::Approved);
    expect($this->project->colorSpecifications()->first()->only(['version', 'approved_by_customer']))->toBe(['version' => 2, 'approved_by_customer' => 'Pat Customer']);
});

test('adding to or removing from approved colors starts the next version too', function () {
    $approved = approvedColors($this);
    $trim = $approved->lines->firstWhere('surface', 'Trim');

    app(ChangeColorSpecification::class)->removeLine($this->project, $trim->id, $this->pat);

    $versions = $this->project->colorSpecifications()->with('lines')->get();

    expect($versions->pluck('version')->all())->toBe([2, 1]);
    expect($versions[0]->lines->pluck('surface')->all())->toBe(['Walls']);
    // The approved version still has the line that was removed from the next one.
    expect($versions[1]->lines->pluck('surface')->all())->toBe(['Walls', 'Trim']);
    expect($trim->fresh())->not->toBeNull();

    // With every line taken out of a later version, the colors are still being changed, not "not started".
    app(ChangeColorSpecification::class)->removeLine($this->project, $versions[0]->lines->sole()->id, $this->pat);

    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::InProgress);
    expect(fn () => app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-08', $this->pat))
        ->toThrow(ValidationException::class, 'There are no colors waiting to be approved.');
});

test('saving an approved line unchanged starts nothing', function () {
    $approved = approvedColors($this);
    $walls = $approved->lines->firstWhere('surface', 'Walls');

    $same = app(ChangeColorSpecification::class)->changeLine($this->project, $walls->id, colorLine(), $this->pat);

    expect($same->id)->toBe($walls->id);
    expect($this->project->colorSpecifications()->count())->toBe(1);
    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::Approved);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectColorsNewVersion)->count())->toBe(0);
});

test('approved colors cannot be changed or deleted in place: they are the record', function () {
    $approved = approvedColors($this);
    $walls = $approved->lines->firstWhere('surface', 'Walls');

    expect(fn () => $walls->forceFill(['color_name' => 'Anything'])->save())->toThrow(LogicException::class, 'A line of approved colors cannot be changed.');
    expect(fn () => $walls->delete())->toThrow(LogicException::class, 'A line of approved colors cannot be changed.');
    expect(fn () => $approved->delete())->toThrow(LogicException::class, 'A color specification cannot be deleted.');

    expect($walls->fresh()->color_name)->toBe('Agreeable Gray');
    expect(Project::query()->findOrFail($this->project->id)->colorSpecifications()->sole()->lines)->toHaveCount(2);
});
