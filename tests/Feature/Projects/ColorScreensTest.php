<?php

use App\Actions\Projects\ChangeColorSpecification;
use App\Actions\Projects\RecordColorApproval;
use App\Actions\Projects\TakeProject;
use App\Enums\ColorReadiness;
use App\Enums\Role;
use App\Models\ColorSpecificationLine;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Where people meet the Color Specification, step 10b
 * (docs/slice-10-pre-production-plan.md): the Colors part of the Project
 * page, the Project's own badges, and Production > Workspace.
 *
 * At Lakeview, "Oak St" was sold by Sam Sales and handed over with "The
 * customer will choose" for colors, and Pat Production took it on Thursday 8
 * October 2026 at 3:00 pm Eastern. Tests start at that moment.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    $this->project = $this->project->fresh();

    $this->walls = [
        'line.area' => 'Living room',
        'line.surface' => 'Walls',
        'line.manufacturer' => 'Sherwin-Williams',
        'line.colorName' => 'Agreeable Gray',
        'line.colorCode' => 'SW 7029',
        'line.sheen' => 'eggshell',
        'line.product' => 'Duration Home',
    ];
});

/**
 * The Colors part of the Project page, as a staff member sees it.
 */
function colorsPart(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.colors', ['project' => $project]);
}

/**
 * Write the living room walls through the action.
 */
function wallsWritten($test): ColorSpecificationLine
{
    return app(ChangeColorSpecification::class)->addLine($test->project, [
        'area' => 'Living room', 'surface' => 'Walls', 'manufacturer' => 'Sherwin-Williams', 'colorName' => 'Agreeable Gray',
        'colorCode' => 'SW 7029', 'sheen' => 'eggshell', 'product' => 'Duration Home', 'note' => '',
    ], $test->pat);
}

test('the project page has a colors part once a production manager has the job, starting from the handoff', function () {
    $waiting = soldProject($this->lakeview, $this->sam);
    completeHandoff($waiting, $this->sam);

    Livewire::actingAs($this->manager)->test('pages::projects.show', ['project' => $waiting])
        ->assertDontSeeHtml('data-test="project-colors"')
        ->assertDontSeeHtml('data-test="project-color-readiness"');

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="project-colors"')
        ->assertSee('Colors: not started')
        ->assertSee('From the handoff:')
        ->assertSee('The customer will choose')
        ->assertSee('No colors are written down yet.')
        ->assertSeeHtml('data-test="add-color-line"');

    // When the salesperson said which colors were chosen, their words are there to work from.
    $chosen = soldProject($this->lakeview, $this->sam);
    completeHandoff($chosen, $this->sam, ['colorsStatus' => 'chosen', 'colorsDetails' => 'Agreeable Gray on the walls, white trim.']);
    app(TakeProject::class)->handle($chosen, $this->pat);

    colorsPart($this->pat, $chosen->fresh())
        ->assertSee('Chosen')
        ->assertSee('Agreeable Gray on the walls, white trim.');
});

test('the production manager writes a color, sends it to the customer and records the approval', function () {
    $part = colorsPart($this->pat, $this->project)
        ->call('openAdd')
        ->call('saveLine')
        ->assertHasErrors(['line.area', 'line.surface', 'line.manufacturer', 'line.colorName', 'line.sheen'])
        ->assertSee('Say which room or area.');

    foreach ($this->walls as $field => $value) {
        $part->set($field, $value);
    }

    $part->call('saveLine')
        ->assertHasNoErrors()
        ->assertDispatched('project-colors-changed')
        ->assertSee('Living room')
        ->assertSee('Sherwin-Williams · Agreeable Gray (SW 7029) · Eggshell · Duration Home')
        ->assertSee('In progress')
        ->assertSeeHtml('data-test="send-colors-for-approval"')
        ->call('sendForApproval')
        ->assertHasNoErrors()
        ->assertSee('Waiting for the customer\'s approval')
        ->assertSee('With the customer for approval since')
        ->assertDontSeeHtml('data-test="send-colors-for-approval"')
        ->call('openApproval')
        // It starts on today, in the Project's own Location.
        ->assertSet('approvedOn', '2026-10-08')
        ->call('saveApproval')
        ->assertHasErrors(['approvalMethod'])
        ->set('approvedBy', 'Pat Customer')
        ->set('approvalMethod', 'phone')
        ->call('saveApproval')
        ->assertHasNoErrors()
        ->assertSee('Approved by Pat Customer, by phone, on Oct 8, 2026.')
        ->assertSee('Recorded by')
        ->assertSee('Changing these colors starts version 2, which needs the customer\'s approval again.')
        ->assertDontSeeHtml('data-test="record-color-approval"');

    expect($this->project->fresh()->color_readiness)->toBe(ColorReadiness::Approved);
});

test('changing an approved color from the page starts version 2 and shows version 1 as it was', function () {
    $walls = wallsWritten($this);
    app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-08', $this->pat);

    colorsPart($this->pat, $this->project->fresh())
        ->assertDontSeeHtml('data-test="colors-earlier"')
        ->call('openChange', $walls->id)
        ->assertSet('line.colorName', 'Agreeable Gray')
        ->assertSee('The customer approved version 1. Saving this starts version 2, which needs their approval again.')
        ->set('line.colorName', 'Repose Gray')
        ->set('line.colorCode', 'SW 7015')
        ->call('saveLine')
        ->assertHasNoErrors()
        ->assertSee('Version 2')
        ->assertSee('In progress')
        ->assertSee('Repose Gray (SW 7015)')
        ->assertSeeHtml('data-test="colors-earlier"')
        ->assertSeeInOrder(['Earlier versions', 'Version 1', 'approved by Pat Customer on Oct 8, 2026', 'Agreeable Gray (SW 7029)'])
        ->assertSeeHtml('data-test="record-color-approval"');

    expect($this->project->colorSpecifications()->count())->toBe(2);
    expect($walls->fresh()->color_name)->toBe('Agreeable Gray');
});

test('a color is removed from the page, and a line the browser made up is refused', function () {
    $walls = wallsWritten($this);

    colorsPart($this->pat, $this->project)->call('openChange', 987654)->assertStatus(409);
    colorsPart($this->pat, $this->project)->call('openRemove', 987654)->assertStatus(409);

    colorsPart($this->pat, $this->project)
        ->call('openRemove', $walls->id)
        ->call('removeLine')
        ->assertHasNoErrors()
        ->assertSee('No colors are written down yet.')
        ->assertSee('Not started');

    expect(ColorSpecificationLine::query()->count())->toBe(0);
});

test('the salesperson and another production manager read the colors and are offered nothing', function () {
    $walls = wallsWritten($this);

    foreach ([$this->sam, $this->pam] as $reader) {
        colorsPart($reader, $this->project)
            ->assertSee('Agreeable Gray (SW 7029)')
            ->assertDontSeeHtml('data-test="add-color-line"')
            ->assertDontSeeHtml('data-test="change-color-line"')
            ->assertDontSeeHtml('data-test="record-color-approval"')
            ->call('openAdd')
            ->assertForbidden();

        colorsPart($reader, $this->project)->call('openChange', $walls->id)->assertForbidden();
        colorsPart($reader, $this->project)->call('openRemove', $walls->id)->assertForbidden();
        colorsPart($reader, $this->project)->call('sendForApproval')->assertForbidden();
        colorsPart($reader, $this->project)->call('openApproval')->assertForbidden();
    }

    // A manager at the Location is offered what the Production Manager is.
    colorsPart($this->manager, $this->project)
        ->assertSeeHtml('data-test="add-color-line"')
        ->assertSeeHtml('data-test="change-color-line"')
        ->assertSeeHtml('data-test="record-color-approval"');

    // Someone who may not see the Project cannot open its colors at all.
    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();

    colorsPart($elsewhere, $this->project)->assertForbidden();
});

test('where the colors stand shows on the project and in the workspace', function () {
    Livewire::actingAs($this->pat)->test('pages::production.workspace')->assertSee('Colors: not started');

    wallsWritten($this);

    Livewire::actingAs($this->pat)->test('pages::production.workspace')->assertSee('Colors: in progress');

    app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-08', $this->pat);

    Livewire::actingAs($this->pat)->test('pages::production.workspace')->assertSee('Colors: approved');
    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSee('Colors: approved')
        ->assertSee('Colors approved by the customer')
        ->assertSee('Colors started');
});
