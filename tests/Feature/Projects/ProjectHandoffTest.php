<?php

use App\Actions\Projects\AssignProductionManager;
use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\CompleteProjectHandoff;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AuditAction;
use App\Enums\HandoffColors;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectHandoff;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The Sales-to-Production handoff: the questions the salesperson has to
 * answer before Production can take a Project
 * (docs/slice-8-projects-plan.md, step 8b; decisions 130 and 132).
 *
 * Sam Sales sold a job at Lakeview on Thursday 8 October 2026 at 3:00 pm
 * Eastern (19:00 UTC). Its handoff is not done yet.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Exterior Painting — 123 Oak St']);
    $this->project->account->forceFill(['display_name' => 'Pat Customer'])->save();
});

/**
 * The handoff page for a Project, as a staff member opens it.
 */
function handoffPage(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.handoff', ['project' => $project]);
}

/*
|--------------------------------------------------------------------------
| Completing it
|--------------------------------------------------------------------------
*/

test('the salesperson completes the handoff: the answers are kept and Production is told', function () {
    Notification::fake();

    expect($this->project->hasHandoff())->toBeFalse();

    $handoff = completeHandoff($this->project, $this->sam, ['colorsStatus' => 'chosen', 'colorsDetails' => 'SW 7005 Pure White, satin', 'otherNotes' => 'They work nights.']);

    expect($handoff->only(['included_scope', 'exclusions', 'promises', 'timing', 'access', 'colors_details', 'condition_prep', 'customer_priorities', 'other_notes']))->toBe([
        'included_scope' => 'All four sides, trim and both doors. Two coats.',
        'exclusions' => 'None',
        'promises' => 'Done before the 24th.',
        'timing' => 'The week of the 19th. Not the 21st.',
        'access' => 'Gate code 4411. Dog in the yard.',
        'colors_details' => 'SW 7005 Pure White, satin',
        'condition_prep' => 'Peeling on the south side.',
        'customer_priorities' => 'A tidy site each evening matters most to them.',
        'other_notes' => 'They work nights.',
    ]);
    expect($handoff->colors_status)->toBe(HandoffColors::Chosen);
    expect($handoff->only(['completed_by', 'completed_by_name', 'updated_by']))->toBe(['completed_by' => $this->sam->id, 'completed_by_name' => 'Sam Sales', 'updated_by' => null]);
    expect($handoff->completed_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect($this->project->fresh()->hasHandoff())->toBeTrue();

    $event = AuditEvent::query()->where('action', AuditAction::ProjectHandoffCompleted)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->subject_id)->toBe($this->project->id);
    expect($event->values_after['answers'])->toMatchArray(['exclusions' => 'None', 'colors_status' => 'chosen']);
    expect(CustomerActivity::label(AuditAction::ProjectHandoffCompleted))->toBe('Sales handoff completed');

    // Every Production Manager at the Location is told it can be taken; nobody else is.
    foreach ([$this->pat, $this->pam] as $manager) {
        Notification::assertSentTo($manager, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::READY_FOR_PRODUCTION
            && $notice->channel === ProjectNotice::IN_APP
            && $notice->toArray($manager)['message'] === 'A sold job at Lakeview is ready for Production.'
            && $notice->toArray($manager)['url'] === route('projects.show', $this->project->id));
    }

    Notification::assertNotSentTo([$this->sam, $this->manager, $this->riley], ProjectNotice::class);
});

test('"None" is an answer, and the optional questions can be left empty', function () {
    $handoff = completeHandoff($this->project, $this->sam, ['includedScope' => 'None', 'exclusions' => 'none', 'promises' => 'None', 'timing' => 'None', 'access' => 'None', 'conditionPrep' => 'None', 'customerPriorities' => 'None']);

    expect($handoff->exists)->toBeTrue();
    expect($handoff->only(['colors_details', 'other_notes']))->toBe(['colors_details' => null, 'other_notes' => null]);
});

test('every question but the last needs an answer', function (array $with, string $field, string $message) {
    try {
        completeHandoff($this->project, $this->sam, $with);

        $this->fail('The handoff was not refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toHaveKey($field);
        expect($refusal->errors()[$field][0])->toBe($message);
    }

    expect($this->project->fresh()->hasHandoff())->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::ProjectHandoffCompleted)->count())->toBe(0);
})->with([
    'what is included' => [['includedScope' => '  '], 'handoff.includedScope', 'Answer this, or write "None".'],
    'exclusions' => [['exclusions' => ''], 'handoff.exclusions', 'Answer this, or write "None".'],
    'promises' => [['promises' => ''], 'handoff.promises', 'Answer this, or write "None".'],
    'timing' => [['timing' => ''], 'handoff.timing', 'Answer this, or write "None".'],
    'access' => [['access' => ''], 'handoff.access', 'Answer this, or write "None".'],
    'condition and prep' => [['conditionPrep' => ''], 'handoff.conditionPrep', 'Answer this, or write "None".'],
    'the customer\'s priorities' => [['customerPriorities' => ''], 'handoff.customerPriorities', 'Answer this, or write "None".'],
    'where the colors stand' => [['colorsStatus' => ''], 'handoff.colorsStatus', 'Say where the colors stand.'],
    'a colors answer that is not offered' => [['colorsStatus' => 'rainbow'], 'handoff.colorsStatus', 'Say where the colors stand.'],
    'chosen colors, not named' => [['colorsStatus' => 'chosen', 'colorsDetails' => ' '], 'handoff.colorsDetails', 'Say which colors were chosen.'],
    'an answer that is far too long' => [['access' => str_repeat('a', 2001)], 'handoff.access', 'Keep this to 2000 characters or fewer.'],
]);

test('a refusal names every question still missing its answer at once', function () {
    try {
        app(CompleteProjectHandoff::class)->handle($this->project, [], $this->sam);

        $this->fail('The handoff was not refused.');
    } catch (ValidationException $refusal) {
        expect(array_keys($refusal->errors()))->toBe([
            'handoff.includedScope', 'handoff.exclusions', 'handoff.promises', 'handoff.timing', 'handoff.access',
            'handoff.conditionPrep', 'handoff.customerPriorities', 'handoff.colorsStatus',
        ]);
    }
});

test('who may complete the handoff', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        expect(completeHandoff($this->project, $actor)->completed_by)->toBe($actor->id);

        return;
    }

    expect(fn () => completeHandoff($this->project, $actor))->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->hasHandoff())->toBeFalse();
})->with([
    'the salesperson who sold it' => [fn ($test) => $test->sam, true],
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a Production Manager there' => [fn ($test) => $test->pat, false],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('the answers can be corrected: what they were is kept, and the production manager is told', function () {
    completeHandoff($this->project, $this->sam);
    app(AssignProductionManager::class)->handle($this->project->fresh(), $this->pat->id, null, $this->manager);

    Notification::fake();

    $this->travelTo('2026-10-09 15:00:00');
    $changed = completeHandoff($this->project->fresh(), $this->manager, ['access' => 'Gate code is 7788 now.']);

    expect($changed->access)->toBe('Gate code is 7788 now.');
    // Who completed it first stays; who changed it is added.
    expect($changed->only(['completed_by_name', 'updated_by_name']))->toBe(['completed_by_name' => 'Sam Sales', 'updated_by_name' => 'Morgan Manager']);
    expect($changed->completed_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect(ProjectHandoff::query()->count())->toBe(1);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectHandoffChanged)->sole();

    expect($event->values_before['answers']['access'])->toBe('Gate code 4411. Dog in the yard.');
    expect($event->values_after['answers']['access'])->toBe('Gate code is 7788 now.');

    Notification::assertSentToTimes($this->pat, ProjectNotice::class, 1);
    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::HANDOFF_CHANGED);
    // It was already ready: the other Production Managers are not told again.
    Notification::assertNotSentTo($this->pam, ProjectNotice::class);

    // Saved unchanged, it records nothing and tells nobody.
    completeHandoff($this->project->fresh(), $this->manager, ['access' => 'Gate code is 7788 now.']);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectHandoffChanged)->count())->toBe(1);
    Notification::assertSentToTimes($this->pat, ProjectNotice::class, 1);
});

test('a cancelled project\'s handoff is not completed', function () {
    app(CancelProjectForCorrectedSale::class)->apply($this->project->proposal, 'Wrong lead.', $this->manager);

    expect(fn () => completeHandoff($this->project->fresh(), $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => completeHandoff($this->project->fresh(), $this->manager))->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| The reminder, the next morning (decision 132)
|--------------------------------------------------------------------------
|
| Sold at 3:00 pm Eastern on the 8th, so due at 8:00 am Eastern on the 9th
| (12:00 UTC).
*/

test('the morning after the sale the salesperson is reminded once, in the app and by email', function () {
    Notification::fake();

    $this->travelTo('2026-10-09 11:59:00');
    expect(app(RunQueueTick::class)->handle()['handoffs_reminded'])->toBe(0);
    Notification::assertNothingSent();

    $this->travelTo('2026-10-09 12:00:00');
    expect(app(RunQueueTick::class)->handle()['handoffs_reminded'])->toBe(1);
    expect($this->project->fresh()->handoff_reminded_at?->toDateTimeString())->toBe('2026-10-09 12:00:00');

    Notification::assertSentToTimes($this->sam, ProjectNotice::class, 2);
    Notification::assertSentTo($this->sam, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::HANDOFF_REMINDER && $notice->channel === ProjectNotice::IN_APP);
    Notification::assertSentTo($this->sam, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::HANDOFF_REMINDER && $notice->channel === ProjectNotice::EMAIL);
    Notification::assertNotSentTo([$this->manager, $this->pat], ProjectNotice::class);

    // Never a second time, however long it stays undone.
    $this->travelTo('2026-10-12 14:00:00');
    expect(app(RunQueueTick::class)->handle()['handoffs_reminded'])->toBe(0);
    Notification::assertSentToTimes($this->sam, ProjectNotice::class, 2);
});

test('the reminder is due by the location\'s own morning', function () {
    $harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $harbor)->create();

    // 10:00 pm on the 8th at Harbor is already the 9th in UTC. The next morning there is the 9th.
    $this->travelTo('2026-10-09 05:00:00');
    $late = soldProject($harbor, $harborSales);
    completeHandoff($this->project, $this->sam);

    Notification::fake();

    $this->travelTo('2026-10-09 14:59:00');
    expect(app(RunQueueTick::class)->handle()['handoffs_reminded'])->toBe(0);

    $this->travelTo('2026-10-09 15:00:00');
    expect(app(RunQueueTick::class)->handle()['handoffs_reminded'])->toBe(1);
    expect($late->fresh()->handoff_reminded_at)->not->toBeNull();
});

test('nobody is reminded about a handoff that is done, a project that was cancelled, or a salesperson who has left', function (Closure $change) {
    $change($this);

    Notification::fake();

    $this->travelTo('2026-10-09 13:00:00');

    expect(app(RunQueueTick::class)->handle()['handoffs_reminded'])->toBe(0);
    Notification::assertNotSentTo($this->sam, ProjectNotice::class);
})->with([
    'the handoff is complete' => [fn ($test) => completeHandoff($test->project, $test->sam)],
    'the sale was corrected' => [fn ($test) => app(CancelProjectForCorrectedSale::class)->apply($test->project->proposal, 'Wrong lead.', $test->manager)],
    'the salesperson was deactivated' => [fn ($test) => $test->sam->forceFill(['is_active' => false])->save()],
]);

test('the reminder says what to do and carries nothing about the customer or the address', function () {
    $notice = new ProjectNotice($this->project->id, ProjectNotice::HANDOFF_REMINDER, ProjectNotice::EMAIL);
    $mail = $notice->toMail($this->sam);
    $text = implode(' ', [$mail->subject, $mail->greeting, ...$mail->introLines, ...$mail->outroLines, $mail->actionText, $mail->actionUrl]);

    expect($mail->subject)->toStartWith('A job you sold needs its handoff in ');
    expect($mail->actionText)->toBe('Complete the handoff');
    expect($text)->toContain('Production cannot take it until you answer the handoff questions.');
    expect($text)->toContain(route('projects.handoff', $this->project->id));
    expect($text)->not->toContain('Pat Customer');
    expect($text)->not->toContain('123 Oak St');

    // The same for every notice kept in the app.
    foreach ([ProjectNotice::HANDOFF_REMINDER, ProjectNotice::READY_FOR_PRODUCTION, ProjectNotice::ASSIGNED_TO_YOU, ProjectNotice::GIVEN_TO_ANOTHER, ProjectNotice::HAS_PRODUCTION_MANAGER, ProjectNotice::HANDOFF_CHANGED, ProjectNotice::CANCELLED] as $kind) {
        $saved = json_encode((new ProjectNotice($this->project->id, $kind, name: 'Lakeview'))->toArray($this->sam));

        expect($saved)->not->toContain('Pat Customer')->not->toContain('Oak St');
    }
});

/*
|--------------------------------------------------------------------------
| The prompt on every page
|--------------------------------------------------------------------------
*/

test('every page prompts the salesperson while a job they sold has no handoff', function () {
    $this->actingAs($this->sam)->get(route('sales.calendar'))
        ->assertOk()
        ->assertSee('data-test="handoff-prompt"', false)
        ->assertSee('You have 1 sold job waiting for your handoff.')
        ->assertSee('Production cannot take it until you answer the handoff questions.')
        ->assertSee('href="'.route('projects.handoff', $this->project).'"', false)
        // The prompt names no customer.
        ->assertDontSee('Pat Customer');

    // It is theirs alone.
    $this->actingAs($this->riley)->get(route('sales.calendar'))->assertOk()->assertDontSee('data-test="handoff-prompt"', false);
    $this->actingAs($this->manager)->get(route('sales.calendar'))->assertOk()->assertDontSee('data-test="handoff-prompt"', false);

    // A second sold job is counted, and the oldest comes first.
    $this->travelTo('2026-10-09 15:00:00');
    soldProject($this->lakeview, $this->sam);

    Livewire::actingAs($this->sam)->test('pages::sales.handoff-prompt')
        ->assertSee('You have 2 sold jobs waiting for your handoff.')
        ->assertSeeHtml('href="'.route('projects.handoff', $this->project).'"');

    completeHandoff($this->project, $this->sam);

    Livewire::actingAs($this->sam)->test('pages::sales.handoff-prompt')->assertSee('You have 1 sold job waiting for your handoff.');
});

/*
|--------------------------------------------------------------------------
| The handoff page
|--------------------------------------------------------------------------
*/

test('who may open the handoff page', function (Closure $person, int $status) {
    $this->actingAs($person($this))->get(route('projects.handoff', $this->project))->assertStatus($status);
})->with([
    'the salesperson who sold it' => [fn ($test) => $test->sam, 200],
    'the Location Manager there' => [fn ($test) => $test->manager, 200],
    'another Sales Consultant there' => [fn ($test) => $test->riley, 403],
    'a Production Manager there' => [fn ($test) => $test->pat, 403],
    'a Call Center Agent' => [fn ($test) => $test->agent, 403],
]);

test('the salesperson answers the questions on the page and is taken to the project', function () {
    handoffPage($this->sam, $this->project)
        ->assertSee('Handoff to Production')
        ->assertSee('Production cannot take this project until these are answered.')
        ->assertSeeInOrder(['1. What exactly is included?', '2. What is not included', '3. What did you promise', '4. When does the customer expect the work?', '5. Access', '6. Colors', '7. Condition and prep', '8. What matters most to this customer?', '9. Anything else Production should know?'])
        ->call('save')
        ->assertHasErrors(['handoff.includedScope', 'handoff.exclusions', 'handoff.colorsStatus', 'handoff.customerPriorities'])
        ->assertSee('Answer this, or write "None".')
        ->assertNoRedirect()
        ->set('handoff.includedScope', 'All four sides. Two coats.')
        ->assertHasNoErrors('handoff.includedScope')
        ->set('handoff.exclusions', 'None')
        ->set('handoff.promises', 'None')
        ->set('handoff.timing', 'The week of the 19th.')
        ->set('handoff.access', 'Gate code 4411.')
        ->set('handoff.conditionPrep', 'Peeling on the south side.')
        ->set('handoff.customerPriorities', 'A tidy site.')
        // The colors' names are asked only when they are chosen.
        ->assertDontSeeHtml('data-test="handoff-colorsDetails"')
        ->set('handoff.colorsStatus', 'chosen')
        ->assertSeeHtml('data-test="handoff-colorsDetails"')
        ->call('save')
        ->assertHasErrors('handoff.colorsDetails')
        ->set('handoff.colorsDetails', 'SW 7005 Pure White')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.show', $this->project));

    expect($this->project->fresh()->handoff->colors_details)->toBe('SW 7005 Pure White');
});

test('the page opens on the answers already given, to be changed', function () {
    completeHandoff($this->project, $this->sam, ['colorsStatus' => 'chosen', 'colorsDetails' => 'SW 7005']);

    handoffPage($this->manager, $this->project->fresh())
        ->assertSee('Change the handoff answers')
        ->assertSet('handoff.access', 'Gate code 4411. Dog in the yard.')
        ->assertSet('handoff.colorsStatus', 'chosen')
        ->assertSet('handoff.colorsDetails', 'SW 7005')
        ->set('handoff.access', 'Gate code is 7788 now.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.show', $this->project));

    expect($this->project->fresh()->handoff->access)->toBe('Gate code is 7788 now.');
});

test('the project page says the handoff is waiting, then shows the answers', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSee('Waiting for the salesperson\'s handoff')
        ->assertSee('Sam Sales has not answered the handoff questions yet. Production cannot take this project until they do.')
        ->assertSee('No Production Manager yet.')
        // Production reads the handoff; it does not write it, and cannot take the job yet.
        ->assertDontSeeHtml('data-test="project-handoff-link"')
        ->assertDontSeeHtml('data-test="take-project"');

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('href="'.route('projects.handoff', $this->project).'"')
        ->assertSee('Complete the handoff');

    completeHandoff($this->project, $this->sam, ['colorsStatus' => 'chosen', 'colorsDetails' => 'SW 7005']);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertDontSee('Waiting for the salesperson\'s handoff')
        ->assertSeeInOrder(['What is included', 'All four sides, trim and both doors. Two coats.', 'What is not included', 'None', 'Access', 'Gate code 4411. Dog in the yard.', 'Colors', 'Chosen: SW 7005', 'What matters most to the customer', 'A tidy site each evening matters most to them.'])
        ->assertSee('Completed by Sam Sales')
        ->assertSee('Sales handoff completed')
        ->assertSeeHtml('data-test="take-project"');
});

test('the lead\'s proposals card says the handoff is needed, with a link for whoever may complete it', function () {
    $lead = $this->project->lead;

    proposalsCard($this->sam, $lead)
        ->assertSeeHtml('data-test="proposal-handoff-needed"')
        ->assertSee('Complete the handoff for Production')
        ->assertSeeHtml('href="'.route('projects.handoff', $this->project).'"');

    proposalsCard($this->agent, $lead)
        ->assertSee('Waiting for the salesperson\'s handoff.')
        ->assertDontSeeHtml(route('projects.handoff', $this->project));

    completeHandoff($this->project, $this->sam);

    proposalsCard($this->sam, $lead->fresh())->assertDontSeeHtml('data-test="proposal-handoff-needed"');
});
