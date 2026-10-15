<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\RecordWork;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Actions\Subcontractors\ScoreSubcontractor;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\Project;
use App\Models\SubcontractorScorecard;
use App\Models\User;
use App\Models\WorkPackage;
use App\Support\Customers\CustomerActivity;
use App\Support\Subcontractors\TrackRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The scorecard, step 14c (docs/slice-14-work-and-closeout-plan.md;
 * product-architecture.md, 7.10 and 7.11; decision 155, question 9).
 *
 * At Lakeview, Sandia Brush Co. finished jobs for Pat Production.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->sandia = app(ChangeSubcontractor::class)->add(['businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'locationIds' => [(string) $this->lakeview->id]], $this->pat);
    app(ChangeSubcontractor::class)->moveTo($this->sandia, 'active', null, $this->pat);
    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        app(RecordCompliance::class)->handle($this->sandia, $requirement->id, '2026-10-01', $requirement->expires ? '2027-09-30' : null, false, null, $this->pat);
    }
});

/**
 * A job at Lakeview whose one package Sandia painted and finished.
 *
 * @return array{0: Project, 1: WorkPackage}
 */
function scoredJob($test, string $title, bool $finish = true): array
{
    test()->travelTo('2026-10-14 15:00:00');

    $project = soldProject($test->lakeview, $test->sam, ['title' => $title]);
    completeHandoff($project, $test->sam);
    app(TakeProject::class)->handle($project, $test->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-14', null, $test->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-16', '2026-11-27', null, null, $test->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $test->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $test->pat);
    $window = app(ChangeWorkPackages::class)->proposeWindow($project, $package->id, '2026-11-16', '2026-11-20', $test->pat);
    app(AwardWorkPackage::class)->award($project, $package->id, $test->sandia->id, $window->id, 'Rosa Alvarez', 'phone', '2026-10-14', $test->pat);
    app(ChangeWorkPackages::class)->confirmWindow($project, $window->id, 'Pat Customer', 'phone', '2026-10-14', $test->pat);

    test()->travelTo('2026-11-23 14:00:00');
    app(RecordWork::class)->start($project, $package->id, '2026-11-16', $test->pat);

    if ($finish) {
        app(RecordWork::class)->finish($project, $package->id, '2026-11-20', $test->pat);
    }

    return [$project->fresh(), $package->fresh()];
}

/**
 * @return array<string, mixed>
 */
function scores(int $overall = 5, array $with = []): array
{
    return ['quality' => '5', 'schedule' => '4', 'communication' => '5', 'cleanup' => '5', 'scope' => '5', 'punch' => '4', 'overall' => (string) $overall, 'review_obtained' => true, 'yard_signs' => false, 'progress_photos' => true, 'feedback' => 'Clean lines.', 'internalNotes' => 'Late twice on day one.', ...$with];
}

test('a subcontractor is scored once for finished work, and the scorecard is kept as it was', function () {
    [$project, $package] = scoredJob($this, 'Whole exterior — 1 Oak St');

    $scorecard = app(ScoreSubcontractor::class)->score($project, $package->id, scores(), $this->pat);

    expect($scorecard->only(['subcontractor_id', 'quality', 'schedule', 'punch', 'overall', 'review_obtained', 'yard_signs', 'feedback', 'internal_notes', 'filled_by']))->toBe([
        'subcontractor_id' => $this->sandia->id, 'quality' => 5, 'schedule' => 4, 'punch' => 4, 'overall' => 5, 'review_obtained' => true, 'yard_signs' => false,
        'feedback' => 'Clean lines.', 'internal_notes' => 'Late twice on day one.', 'filled_by' => $this->pat->id,
    ]);
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorScored)->sole()->values_after)->toEqual(['project' => 'Whole exterior — 1 Oak St', 'package' => 'Whole exterior', 'overall' => 5]);
    expect(CustomerActivity::actions())->not->toContain(AuditAction::SubcontractorScored);

    expect(fn () => app(ScoreSubcontractor::class)->score($project, $package->id, scores(), $this->pat))->toThrow(ValidationException::class, 'This work is scored already.');
    expect(fn () => $scorecard->forceFill(['overall' => 1])->save())->toThrow(LogicException::class);

    $note = app(ScoreSubcontractor::class)->addNote($scorecard, 'Schedule should have been 5: the customer delayed.', $this->pat);

    expect($note->written_by_name)->toBe('Pat Production');
});

test('work not finished is not scored, a score must be 1 to 5, and the salesperson may not score', function () {
    [$project, $package] = scoredJob($this, 'Whole exterior — 1 Oak St', finish: false);

    expect(fn () => app(ScoreSubcontractor::class)->score($project, $package->id, scores(), $this->pat))->toThrow(ValidationException::class, 'A subcontractor is scored once their work has finished.');

    app(RecordWork::class)->finish($project, $package->id, '2026-11-20', $this->pat);

    try {
        app(ScoreSubcontractor::class)->score($project, $package->id, scores(with: ['quality' => '6', 'overall' => '']), $this->pat);
        $this->fail('It was not refused.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe(['scores.quality', 'scores.overall']);
    }

    expect(fn () => app(ScoreSubcontractor::class)->score($project, $package->id, scores(), $this->sam))->toThrow(AuthorizationException::class);
    expect(SubcontractorScorecard::query()->count())->toBe(0);
});

test('the track record counts finished jobs and averages the overall rating, and five jobs at 4.5 meet the bar', function () {
    expect(TrackRecord::for($this->sandia)->words())->toBe('0 jobs completed · not scored yet');

    foreach ([5, 4, 5, 5] as $index => $overall) {
        [$project, $package] = scoredJob($this, 'Job '.$index);
        app(ScoreSubcontractor::class)->score($project, $package->id, scores($overall), $this->pat);
    }

    $record = TrackRecord::for($this->sandia->fresh());

    expect([$record->completedJobs, $record->averageRating, $record->meetsTheBar()])->toBe([4, 4.8, false]);

    [$project, $package] = scoredJob($this, 'Job 5');
    app(ScoreSubcontractor::class)->score($project, $package->id, scores(4), $this->pat);

    $record = TrackRecord::for($this->sandia->fresh());

    expect([$record->completedJobs, $record->averageRating, $record->meetsTheBar()])->toBe([5, 4.6, true]);
    expect($record->words())->toBe('5 jobs completed · rating 4.6 from 5 scorecards');
});

test('the job page scores the subcontractor, and their page shows the scorecard and track record', function () {
    [$project, $package] = scoredJob($this, 'Whole exterior — 1 Oak St');

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $project])
        ->assertSee('Sandia Brush Co. is not scored for this work yet.')
        ->call('openScore', $package->id)
        ->call('saveScore')
        ->assertHasErrors(['scores.quality', 'scores.overall'])
        ->set('scores', scores(4))
        ->call('saveScore')
        ->assertHasNoErrors()
        ->assertSee('Scored 4 of 5 overall by Pat Production.')
        ->assertDontSeeHtml('data-test="open-score"');

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->sandia->fresh()])
        ->assertSee('1 job completed · rating 4.0 from 1 scorecard')
        ->assertSee('Whole exterior · Whole exterior — 1 Oak St')
        ->assertSee('Feedback for them:')
        ->assertSee('Internal notes, never shown to them:')
        ->assertSee('Scored for Whole exterior (Whole exterior — 1 Oak St): overall 4 of 5');

    // The salesperson sees the job, not the scorecard.
    Livewire::actingAs($this->sam)->test('pages::projects.work', ['project' => $project->fresh()])
        ->assertDontSeeHtml('data-test="work-scorecard"');
});
