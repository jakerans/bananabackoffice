<?php

use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectTimeframe;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * Pre-production, step 10a (docs/slice-10-pre-production-plan.md;
 * product-architecture.md, 6.3 and 6.5; decision 141): the welcome call as a
 * checklist item, a job nobody has called falling behind after two business
 * days, and the rough timeframe.
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
 * The people of the "who may" matrices.
 */
dataset('people around a project in pre-production', [
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a manager at the location' => [fn ($test) => $test->manager, true],
    'an Owner' => [fn ($test) => $test->owner, true],
    'another Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson who sold it' => [fn ($test) => $test->sam, false],
    'a manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

/*
|--------------------------------------------------------------------------
| The welcome call
|--------------------------------------------------------------------------
*/

test('the welcome call is a checklist item: ticked with the day it was made and an optional note', function () {
    expect($this->project->welcome_call_on)->toBeNull();

    $this->travelTo('2026-10-09 15:00:00');
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-09', '  Spoke to both owners.  ', $this->pat);

    $project = $this->project->fresh();

    expect($project->welcome_call_on->toDateString())->toBe('2026-10-09');
    expect($project->only(['welcome_call_note', 'welcome_call_by', 'welcome_call_by_name']))
        ->toBe(['welcome_call_note' => 'Spoke to both owners.', 'welcome_call_by' => $this->pat->id, 'welcome_call_by_name' => 'Pat Production']);
    expect($project->welcome_call_recorded_at->toDateTimeString())->toBe('2026-10-09 15:00:00');

    $event = AuditEvent::query()->where('action', AuditAction::ProjectWelcomeCallRecorded)->sole();

    expect($event->subject_id)->toBe($project->id);
    expect($event->actor_id)->toBe($this->pat->id);
    expect($event->location_id)->toBe($this->lakeview->id);
    expect($event->values_before)->toBeNull();
    expect($event->values_after)->toEqual(['made_on' => '2026-10-09', 'note' => 'Spoke to both owners.']);
    expect(CustomerActivity::label(AuditAction::ProjectWelcomeCallRecorded))->toBe('Welcome call made');

    // The note is optional, and the call can have been yesterday.
    app(RecordWelcomeCall::class)->handle($project, '2026-10-08', null, $this->manager);

    expect($project->fresh()->only(['welcome_call_note', 'welcome_call_by_name']))->toBe(['welcome_call_note' => null, 'welcome_call_by_name' => 'Morgan Manager']);
    expect($project->fresh()->welcome_call_on->toDateString())->toBe('2026-10-08');

    $changed = AuditEvent::query()->where('action', AuditAction::ProjectWelcomeCallRecorded)->orderByDesc('id')->first();

    expect($changed->values_before)->toEqual(['made_on' => '2026-10-09', 'note' => 'Spoke to both owners.']);

    // The same again writes nothing.
    app(RecordWelcomeCall::class)->handle($project->fresh(), '2026-10-08', '', $this->manager);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectWelcomeCallRecorded)->count())->toBe(2);
});

test('who may tick the welcome call', function (Closure $who, bool $allowed) {
    $tick = fn () => app(RecordWelcomeCall::class)->handle($this->project, '2026-10-08', null, $who($this));

    if ($allowed) {
        $tick();

        expect($this->project->fresh()->welcome_call_on)->not->toBeNull();

        return;
    }

    expect($tick)->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->welcome_call_on)->toBeNull();
})->with('people around a project in pre-production');

test('the tick is refused without a day, for a day that has not come, and with too long a note', function (?string $day, ?string $note, string $field, string $message) {
    try {
        app(RecordWelcomeCall::class)->handle($this->project, $day, $note, $this->pat);

        $this->fail('The tick was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$message]]);
    }

    expect($this->project->fresh()->welcome_call_on)->toBeNull();
    expect(AuditEvent::query()->where('action', AuditAction::ProjectWelcomeCallRecorded)->count())->toBe(0);
})->with([
    'no day' => ['', null, 'welcomeCallOn', 'Choose the day the call was made.'],
    'words' => ['yesterday', null, 'welcomeCallOn', 'Choose the day the call was made.'],
    'tomorrow' => ['2026-10-09', null, 'welcomeCallOn', 'The call cannot be on a day that has not come yet.'],
    'a note of 1,001 characters' => ['2026-10-08', str_repeat('a', 1001), 'welcomeCallNote', 'Keep the note to 1000 characters or fewer.'],
]);

test('a project with no production manager yet, or one whose sale was corrected, has no welcome call to tick', function () {
    $waiting = soldProject($this->lakeview, $this->sam);
    completeHandoff($waiting, $this->sam);

    expect(fn () => app(RecordWelcomeCall::class)->handle($waiting, '2026-10-08', null, $this->manager))->toThrow(AuthorizationException::class);

    app(CancelProjectForCorrectedSale::class)->apply($this->project->proposal, 'Wrong lead.', $this->manager);

    expect(fn () => app(RecordWelcomeCall::class)->handle($this->project->fresh(), '2026-10-08', null, $this->pat))->toThrow(AuthorizationException::class);
});

test('a tick made by mistake is undone with a note, and the project waits for its call again', function () {
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-08', 'Left a message.', $this->pat);

    expect(fn () => app(RecordWelcomeCall::class)->undo($this->project->fresh(), '  ', $this->pat))->toThrow(ValidationException::class, 'Say why it is being unticked.');
    expect(fn () => app(RecordWelcomeCall::class)->undo($this->project->fresh(), 'Wrong job.', $this->sam))->toThrow(AuthorizationException::class);

    app(RecordWelcomeCall::class)->undo($this->project->fresh(), 'Ticked on the wrong job.', $this->manager);

    $project = $this->project->fresh();

    expect($project->only(['welcome_call_on', 'welcome_call_note', 'welcome_call_by', 'welcome_call_by_name', 'welcome_call_recorded_at']))
        ->toBe(['welcome_call_on' => null, 'welcome_call_note' => null, 'welcome_call_by' => null, 'welcome_call_by_name' => null, 'welcome_call_recorded_at' => null]);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectWelcomeCallUndone)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->values_before)->toEqual(['made_on' => '2026-10-08', 'note' => 'Left a message.']);
    expect($event->reason)->toBe('Ticked on the wrong job.');

    // There is nothing to undo twice.
    expect(fn () => app(RecordWelcomeCall::class)->undo($project, 'Again.', $this->manager))->toThrow(ValidationException::class, 'The welcome call is not ticked.');
});

/*
|--------------------------------------------------------------------------
| Falling behind
|--------------------------------------------------------------------------
*/

test('a job nobody has called falls behind two business days after it was taken (decision 141)', function () {
    // Taken on Thursday at 3:00 pm Eastern: Friday and Monday are the two business days.
    expect(RoughTimeframe::welcomeCallDueAt($this->project)->toDateTimeString())->toBe('2026-10-12 19:00:00');

    // The weekend does not count.
    $this->travelTo('2026-10-11 19:00:00');

    expect(RoughTimeframe::welcomeCallIsBehind($this->project))->toBeFalse();

    $this->travelTo('2026-10-12 18:59:00');

    expect(RoughTimeframe::welcomeCallIsBehind($this->project))->toBeFalse();

    $this->travelTo('2026-10-12 19:00:00');

    expect(RoughTimeframe::welcomeCallIsBehind($this->project))->toBeTrue();

    // Ticked, it is no longer waiting, however late the tick.
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-12', null, $this->pat);

    expect(RoughTimeframe::welcomeCallDueAt($this->project->fresh()))->toBeNull();
    expect(RoughTimeframe::welcomeCallIsBehind($this->project->fresh()))->toBeFalse();

    // Unticked, it is behind again at once: the two days ran from when the job was taken.
    app(RecordWelcomeCall::class)->undo($this->project->fresh(), 'Wrong job.', $this->pat);

    expect(RoughTimeframe::welcomeCallIsBehind($this->project->fresh()))->toBeTrue();
});

test('a job taken at the weekend has until the end of tuesday, by the location\'s clock', function () {
    $weekend = soldProject($this->lakeview, $this->sam);
    completeHandoff($weekend, $this->sam);

    // Saturday 10 October, 10:00 am Eastern.
    $this->travelTo('2026-10-10 14:00:00');
    app(TakeProject::class)->handle($weekend, $this->pat);

    expect(RoughTimeframe::welcomeCallDueAt($weekend->fresh())->toDateTimeString())->toBe('2026-10-13 14:00:00');
});

test('a project that is not waiting for a call is never behind', function () {
    $this->travelTo('2026-10-20 19:00:00');

    // No Production Manager yet.
    $waiting = soldProject($this->lakeview, $this->sam);

    expect(RoughTimeframe::welcomeCallIsBehind($waiting))->toBeFalse();

    // Its sale was corrected.
    expect(RoughTimeframe::welcomeCallIsBehind($this->project))->toBeTrue();

    app(CancelProjectForCorrectedSale::class)->apply($this->project->proposal, 'Wrong lead.', $this->manager);

    expect(RoughTimeframe::welcomeCallIsBehind($this->project->fresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The rough timeframe
|--------------------------------------------------------------------------
*/

test('the rough timeframe is an earliest and a latest start, kept with who set it', function () {
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-24', '2026-12-12', '  After the roof is done.  ', null, $this->pat);

    $project = $this->project->fresh();

    expect([$project->timeframe_earliest->toDateString(), $project->timeframe_latest->toDateString(), $project->timeframe_note])
        ->toBe(['2026-11-24', '2026-12-12', 'After the roof is done.']);
    expect(RoughTimeframe::of($project))->toBe('Late November to mid December');

    $kept = ProjectTimeframe::query()->sole();

    expect([$kept->project_id, $kept->earliest->toDateString(), $kept->latest->toDateString(), $kept->note, $kept->reason, $kept->set_by, $kept->set_by_name])
        ->toBe([$project->id, '2026-11-24', '2026-12-12', 'After the roof is done.', null, $this->pat->id, 'Pat Production']);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectTimeframeSet)->sole();

    expect($event->actor_id)->toBe($this->pat->id);
    expect($event->values_before)->toBeNull();
    expect($event->values_after)->toEqual(['earliest' => '2026-11-24', 'latest' => '2026-12-12', 'note' => 'After the roof is done.', 'in_words' => 'Late November to mid December']);
    expect(CustomerActivity::label(AuditAction::ProjectTimeframeSet))->toBe('Rough timeframe set');
});

test('changing the timeframe needs a reason and keeps what it was', function () {
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-24', '2026-12-12', null, null, $this->pat);

    try {
        app(SetProjectTimeframe::class)->handle($this->project->fresh(), '2027-01-11', '2027-01-29', null, '  ', $this->pat);

        $this->fail('It was changed without a reason.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['timeframeReason' => ['Say why the timeframe is being changed.']]);
    }

    $this->travelTo('2026-10-20 15:00:00');
    app(SetProjectTimeframe::class)->handle($this->project->fresh(), '2027-01-11', '2027-01-29', null, 'The customer asked to wait until after the holidays.', $this->manager);

    $project = $this->project->fresh();

    expect(RoughTimeframe::of($project))->toBe('Mid to late January 2027');

    // Both are kept, the one in force first.
    $kept = $project->timeframes;

    expect($kept->map(fn (ProjectTimeframe $timeframe): string => $timeframe->earliest->toDateString())->all())->toBe(['2027-01-11', '2026-11-24']);
    expect($kept->first()->only(['reason', 'set_by_name']))->toBe(['reason' => 'The customer asked to wait until after the holidays.', 'set_by_name' => 'Morgan Manager']);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectTimeframeChanged)->sole();

    expect($event->values_before)->toEqual(['earliest' => '2026-11-24', 'latest' => '2026-12-12', 'note' => null]);
    expect($event->values_after)->toMatchArray(['earliest' => '2027-01-11', 'latest' => '2027-01-29']);
    expect($event->reason)->toBe('The customer asked to wait until after the holidays.');

    // Saved unchanged, nothing is written and no reason is asked for.
    app(SetProjectTimeframe::class)->handle($project, '2027-01-11', '2027-01-29', '', null, $this->pat);

    expect(ProjectTimeframe::query()->count())->toBe(2);
    expect(AuditEvent::query()->whereIn('action', [AuditAction::ProjectTimeframeSet, AuditAction::ProjectTimeframeChanged])->count())->toBe(2);
});

test('a timeframe is refused when a day is missing, the days are the wrong way round, or it has already passed', function (string $earliest, string $latest, ?string $note, string $field, string $message) {
    try {
        app(SetProjectTimeframe::class)->handle($this->project, $earliest, $latest, $note, null, $this->pat);

        $this->fail('The timeframe was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$message]]);
    }

    expect($this->project->fresh()->timeframe_earliest)->toBeNull();
    expect(ProjectTimeframe::query()->count())->toBe(0);
})->with([
    'no earliest start' => ['', '2026-12-12', null, 'timeframeEarliest', 'Choose the earliest day the work could start.'],
    'no latest start' => ['2026-11-24', '', null, 'timeframeLatest', 'Choose the latest day the work is expected to start.'],
    'a day that does not exist' => ['2026-11-31', '2026-12-12', null, 'timeframeEarliest', 'Choose the earliest day the work could start.'],
    'the latest before the earliest' => ['2026-12-12', '2026-11-24', null, 'timeframeLatest', 'The latest start cannot be before the earliest.'],
    'wholly in the past' => ['2026-09-01', '2026-10-07', null, 'timeframeLatest', 'The latest start has already passed. Choose today or a later day.'],
    'a note of 1,001 characters' => ['2026-11-24', '2026-12-12', str_repeat('a', 1001), 'timeframeNote', 'Keep this to 1000 characters or fewer.'],
]);

test('who may set the rough timeframe', function (Closure $who, bool $allowed) {
    $set = fn () => app(SetProjectTimeframe::class)->handle($this->project, '2026-11-24', '2026-12-12', null, null, $who($this));

    if ($allowed) {
        $set();

        expect($this->project->fresh()->timeframe_earliest)->not->toBeNull();

        return;
    }

    expect($set)->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->timeframe_earliest)->toBeNull();
})->with('people around a project in pre-production');

test('a timeframe is said in thirds of a month, and the year only when it is not this year', function (string $earliest, string $latest, string $words) {
    expect(RoughTimeframe::words(CarbonImmutable::parse($earliest), CarbonImmutable::parse($latest), $this->lakeview))->toBe($words);
})->with([
    'one third of one month' => ['2026-11-21', '2026-11-30', 'Late November'],
    'two thirds of one month' => ['2026-11-03', '2026-11-25', 'Early to late November'],
    'two months' => ['2026-11-24', '2026-12-12', 'Late November to mid December'],
    'into next year' => ['2026-12-28', '2027-01-08', 'Late December to early January 2027'],
    'wholly next year' => ['2027-01-04', '2027-02-26', 'Early January to late February 2027'],
    'the 10th and the 11th' => ['2026-11-10', '2026-11-11', 'Early to mid November'],
]);

test('a timeframe that is kept cannot be edited or deleted', function () {
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-24', '2026-12-12', null, null, $this->pat);

    $kept = ProjectTimeframe::query()->sole();

    expect(fn () => $kept->forceFill(['note' => 'Changed'])->save())->toThrow(LogicException::class, 'A timeframe is history and cannot be edited.');
    expect(fn () => $kept->delete())->toThrow(LogicException::class, 'A timeframe cannot be deleted.');
    expect(Project::query()->findOrFail($this->project->id)->timeframes)->toHaveCount(1);
});
