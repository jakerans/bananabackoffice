<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

/*
 * Settings > Audit Log, step 29a (docs/slice-29-audit-log-plan.md; decision
 * 191, questions 1, 2 and 4): every recorded change, newest first, for the
 * Owner only.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
});

test('only the Owner opens the Audit Log', function () {
    $this->actingAs($this->owner)->get(route('settings.audit_log'))->assertOk()->assertSee('Nothing has been recorded yet.');

    foreach ([
        User::factory()->withRoleEverywhere(Role::Leadership)->create(),
        User::factory()->withRoleEverywhere(Role::Finance)->create(),
        User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(),
    ] as $someoneElse) {
        $this->actingAs($someoneElse)->get(route('settings.audit_log'))->assertForbidden();
    }
});

test('each change shows what was done, to which record, by whom, where, why and what changed, newest first', function () {
    $morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $project = soldProject($this->lakeview, $morgan, ['title' => 'Smith exterior']);

    $renamed = AuditEvent::factory()->about($this->lakeview)->create([
        'actor_id' => $this->owner->id, 'actor_name' => 'Olive Owner', 'action' => AuditAction::LocationRenamed,
        'location_id' => $this->lakeview->id, 'values_before' => ['name' => 'Lakeside'], 'values_after' => ['name' => 'Lakeview'],
    ]);
    $deposit = AuditEvent::factory()->about($project)->create([
        'actor_id' => $morgan->id, 'actor_name' => 'Morgan Manager', 'impersonator_id' => $this->owner->id, 'impersonator_name' => 'Olive Owner',
        'action' => AuditAction::ProjectDepositChanged, 'location_id' => $this->lakeview->id, 'reason' => 'Long-standing customer',
        'values_before' => ['deposit_cents' => 250000, 'waived' => false], 'values_after' => ['deposit_cents' => 125050, 'waived' => true],
    ]);

    $this->actingAs($this->owner)->get(route('settings.audit_log'))->assertOk()
        ->assertSeeInOrder([
            'Project · Deposit changed', 'Smith exterior', 'Olive Owner, impersonating Morgan Manager', 'Lakeview', 'Why: Long-standing customer', 'What changed',
            'Location · Renamed', 'Lakeview', 'Olive Owner', 'Name', 'Lakeside', 'Lakeview',
        ])
        // MySQL keeps a change's fields in its own order, so each field is checked on its own.
        ->assertSeeInOrder(['Deposit', '$2,500', '$1,250.50'])
        ->assertSeeInOrder(['Waived', 'No', 'Yes'])
        ->assertSee(route('projects.show', $project), false)
        ->assertSee('data-test="audit-event-'.$renamed->id.'"', false)
        ->assertSee('data-test="audit-event-'.$deposit->id.'"', false);
});

test('a change from the command line, or to a record that is gone, still reads plainly', function () {
    AuditEvent::factory()->fromCommandLine()->create(['action' => AuditAction::StaffDeactivated, 'subject_type' => 'user', 'subject_id' => 999999]);

    $this->actingAs($this->owner)->get(route('settings.audit_log'))->assertOk()
        ->assertSeeInOrder(['Staff member · Deactivated', 'User #999999', 'The command line']);
});

test('the log shows fifty changes to a page', function () {
    AuditEvent::factory()->count(51)->fromCommandLine()->about($this->lakeview)->create(['action' => AuditAction::LocationTimezoneChanged]);
    $oldest = AuditEvent::query()->min('id');

    $this->actingAs($this->owner)->get(route('settings.audit_log'))->assertOk()->assertDontSee('data-test="audit-event-'.$oldest.'"', false);
    $this->actingAs($this->owner)->get(route('settings.audit_log', ['page' => 2]))->assertOk()->assertSee('data-test="audit-event-'.$oldest.'"', false);
});

test('every kind of change has words and an area', function (AuditAction $action) {
    expect($action->label())->toContain(' · ')
        ->and(AuditAction::AREAS)->toHaveKey($action->area());
})->with(AuditAction::cases());

/*
 * Finding a change, step 29b (decision 191, question 3).
 */

test('the log narrows to an area, a Location, a person, a range of days, or one record', function () {
    $this->travelTo('2026-10-26 15:00:00');
    $harbor = Location::factory()->create(['name' => 'Harbor']);
    $morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $project = soldProject($this->lakeview, $morgan, ['title' => 'Smith exterior']);

    $deposit = AuditEvent::factory()->about($project)->create([
        'actor_id' => $morgan->id, 'actor_name' => 'Morgan Manager', 'action' => AuditAction::ProjectDepositChanged,
        'location_id' => $this->lakeview->id, 'created_at' => '2026-10-20 15:00:00',
    ]);
    $deactivated = AuditEvent::factory()->about($morgan)->create([
        'actor_id' => $this->owner->id, 'actor_name' => 'Olive Owner', 'action' => AuditAction::StaffDeactivated, 'created_at' => '2026-10-25 15:00:00',
    ]);
    $renamed = AuditEvent::factory()->about($harbor)->create([
        'actor_id' => $this->owner->id, 'actor_name' => 'Olive Owner', 'action' => AuditAction::LocationRenamed,
        'location_id' => $harbor->id, 'created_at' => '2026-10-26 15:00:00',
    ]);

    $shown = fn (array $filters): array => collect([$deposit, $deactivated, $renamed])
        ->filter(fn (AuditEvent $event): bool => str_contains(
            $this->actingAs($this->owner)->get(route('settings.audit_log', $filters))->assertOk()->getContent(),
            'data-test="audit-event-'.$event->id.'"',
        ))
        ->map(fn (AuditEvent $event): int => $event->id)
        ->values()
        ->all();

    expect($shown([]))->toBe([$deposit->id, $deactivated->id, $renamed->id])
        ->and($shown(['area' => 'projects']))->toBe([$deposit->id])
        ->and($shown(['area' => 'staff']))->toBe([$deactivated->id])
        ->and($shown(['location' => $harbor->id]))->toBe([$renamed->id])
        ->and($shown(['person' => $this->owner->id]))->toBe([$deactivated->id, $renamed->id])
        ->and($shown(['from' => '2026-10-21', 'to' => '2026-10-25']))->toBe([$deactivated->id])
        ->and($shown(['record' => 'project:'.$project->id]))->toBe([$deposit->id])
        ->and($shown(['area' => 'nonsense', 'from' => '2026-02-31', 'record' => 'nothing:1']))->toBe([$deposit->id, $deactivated->id, $renamed->id]);

    $this->actingAs($this->owner)->get(route('settings.audit_log', ['record' => 'project:'.$project->id]))
        ->assertSeeInOrder(['The history of', 'Smith exterior'])
        ->assertSee('Show everything');

    Livewire::actingAs($this->owner)->test('pages::settings.audit-log', ['area' => 'staff'])
        ->set('location', (string) $harbor->id)
        ->assertSee('No recorded change matches.')
        ->call('showEverything')
        ->assertSet('area', '')
        ->assertSet('location', '')
        ->assertSee('data-test="audit-event-'.$deposit->id.'"', false);
});

test('a staff member\'s page and each Location link to their changes', function () {
    $morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);

    $this->actingAs($this->owner)->get(route('settings.users.show', $morgan))->assertOk()
        ->assertSee(route('settings.audit_log', ['person' => $morgan->id]), false)
        ->assertSee(route('settings.audit_log', ['record' => 'user:'.$morgan->id]), false);
    $this->actingAs($this->owner)->get(route('settings.locations'))->assertOk()
        ->assertSee(route('settings.audit_log', ['location' => $this->lakeview->id]), false);
});
