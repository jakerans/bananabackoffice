<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

test('it records who did what to which record, where, with the values before and after and the reason', function () {
    $actor = User::factory()->create(['name' => 'Olive Owner']);
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    app(RecordAuditEvent::class)->handle(
        AuditAction::LocationRenamed,
        $lakeview,
        actor: $actor,
        before: ['name' => 'Lake View'],
        after: ['name' => 'Lakeview'],
        location: $lakeview,
        reason: 'Corrected the spelling.',
    );

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LocationRenamed);
    expect($event->actor->is($actor))->toBeTrue();
    expect($event->actor_name)->toBe('Olive Owner');
    expect($event->subject->is($lakeview))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before())->toBe(['name' => 'Lake View']);
    expect($event->after())->toBe(['name' => 'Lakeview']);
    expect($event->reason)->toBe('Corrected the spelling.');
    expect($event->created_at)->not->toBeNull();
});

test('the signed-in person is the actor when none is named', function () {
    $signedIn = User::factory()->create();
    $staff = User::factory()->create();

    $this->actingAs($signedIn);

    app(RecordAuditEvent::class)->handle(AuditAction::StaffDeactivated, $staff);

    expect(AuditEvent::query()->sole()->actor->is($signedIn))->toBeTrue();
});

test('a named actor takes precedence over the signed-in person', function () {
    $signedIn = User::factory()->create();
    $named = User::factory()->create();
    $staff = User::factory()->create();

    $this->actingAs($signedIn);

    app(RecordAuditEvent::class)->handle(AuditAction::StaffDeactivated, $staff, actor: $named);

    expect(AuditEvent::query()->sole()->actor->is($named))->toBeTrue();
});

test('a change made with nobody signed in is recorded with no actor', function () {
    $staff = User::factory()->create();

    app(RecordAuditEvent::class)->handle(AuditAction::StaffDeactivated, $staff);

    $event = AuditEvent::query()->sole();

    expect($event->actor_id)->toBeNull();
    expect($event->actor_name)->toBeNull();
    expect($event->subject->is($staff))->toBeTrue();
});

test('an event with no values or location recorded stores none', function () {
    app(RecordAuditEvent::class)->handle(AuditAction::StaffDeactivated, User::factory()->create());

    $event = AuditEvent::query()->sole();

    expect($event->values_before)->toBeNull();
    expect($event->values_after)->toBeNull();
    expect($event->before())->toBe([]);
    expect($event->after())->toBe([]);
    expect($event->location_id)->toBeNull();
    expect($event->reason)->toBeNull();
});

test('the actor\'s name is kept as it was when they are renamed later', function () {
    $actor = User::factory()->create(['name' => 'Olive Owner']);

    app(RecordAuditEvent::class)->handle(AuditAction::StaffDeactivated, User::factory()->create(), actor: $actor);

    $actor->update(['name' => 'Olive Married-Name']);

    expect(AuditEvent::query()->sole()->actor_name)->toBe('Olive Owner');
});

test('the record is stored under its short name, not its class name', function () {
    app(RecordAuditEvent::class)->handle(AuditAction::LocationDeactivated, Location::factory()->create());

    expect(DB::table('audit_events')->value('subject_type'))->toBe('location');
});

test('a kind of record that has not been given a short name cannot be pointed at', function () {
    expect(fn () => (new AuditEvent)->getMorphClass())
        ->toThrow(ClassMorphViolationException::class);

    expect(Relation::getMorphedModel('user'))->toBe(User::class);
});

test('an audit event cannot be changed', function () {
    $event = AuditEvent::factory()->create();

    expect(fn () => $event->forceFill(['reason' => 'Rewritten afterwards.'])->save())
        ->toThrow(LogicException::class, 'An audit event cannot be changed once it has been recorded.');

    expect($event->fresh()->reason)->toBeNull();
});

test('an audit event cannot be deleted', function () {
    $event = AuditEvent::factory()->create();

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'An audit event cannot be deleted.');

    expect(AuditEvent::query()->count())->toBe(1);
});
