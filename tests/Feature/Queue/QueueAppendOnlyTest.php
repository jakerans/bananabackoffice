<?php

use App\Enums\Role;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\Location;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The queue history and an attempt's cadence position are only ever added,
 * in the application, like audit events. The migrations are additive and
 * create no cadence rows.
 */

test('a queue history entry cannot be changed', function () {
    $lead = dueLead(Location::factory()->create());
    $event = LeadQueueEvent::query()->sole();

    expect(fn () => $event->forceFill(['reason' => 'edited'])->save())->toThrow(LogicException::class, 'cannot be changed');
    expect(fn () => $event->forceFill(['affected_user_id' => 1, 'cooldown_until' => now()])->save())->toThrow(LogicException::class);

    expect(queueHistory($lead))->toBe(['entered']);
    expect($event->fresh()->reason)->toBeNull();
});

test('a queue history entry cannot be deleted', function () {
    dueLead(Location::factory()->create());
    $event = LeadQueueEvent::query()->sole();

    expect(fn () => $event->delete())->toThrow(LogicException::class, 'cannot be deleted');
    expect(LeadQueueEvent::query()->count())->toBe(1);
});

test('a queue history entry has no updated-at column', function () {
    expect(Schema::hasColumn('lead_queue_events', 'updated_at'))->toBeFalse();
    expect(Schema::hasColumn('lead_queue_events', 'created_at'))->toBeTrue();
});

test('an attempt\'s cadence position cannot be changed after the insert', function () {
    $this->travelTo('2026-10-07 14:00:00');

    $lakeview = Location::factory()->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = dueLead($lakeview);

    $counted = logAttempt($lead, $agent);
    $evidence = ContactAttempt::factory()->forLead($lead)->create();

    expect(fn () => $counted->forceFill(['cadence_step' => 2])->save())->toThrow(LogicException::class);
    expect(fn () => $counted->forceFill(['cadence_cycle' => null, 'cadence_step' => null])->save())->toThrow(LogicException::class);
    expect(fn () => $evidence->forceFill(['cadence_cycle' => 1, 'cadence_step' => 5])->save())->toThrow(LogicException::class);
    expect(fn () => $counted->delete())->toThrow(LogicException::class);

    expect($counted->fresh()->only(['cadence_cycle', 'cadence_step']))->toBe(['cadence_cycle' => 1, 'cadence_step' => 1]);
    expect($evidence->fresh()->only(['cadence_cycle', 'cadence_step']))->toBe(['cadence_cycle' => null, 'cadence_step' => null]);
});

test('a cadence is ended, never deleted', function () {
    dueLead(Location::factory()->create());

    expect(fn () => LeadCadence::query()->sole()->delete())->toThrow(LogicException::class);
    expect(LeadCadence::query()->count())->toBe(1);
});

test('the migrations create the tables and columns and no cadence rows', function () {
    foreach (['lead_cadences', 'lead_queue_events', 'scheduler_heartbeats'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
        expect(DB::table($table)->count())->toBe(0);
    }

    expect(Schema::hasColumns('contact_attempts', ['cadence_cycle', 'cadence_step']))->toBeTrue();

    // A Lead that exists without a cadence stays that way.
    Lead::factory()->count(3)->create();

    expect(LeadCadence::query()->count())->toBe(0);
    expect(SchedulerHeartbeat::query()->count())->toBe(0);
});

test('every foreign key in the new tables has an index of its own', function () {
    $leading = fn (string $table): array => collect(Schema::getIndexes($table))->map(fn (array $index): string => $index['columns'][0])->unique()->values()->all();

    expect($leading('lead_cadences'))->toContain('lead_id', 'held_by_user_id');
    expect($leading('lead_queue_events'))->toContain('lead_id', 'lead_cadence_id', 'contact_attempt_id', 'location_id', 'actor_id', 'impersonator_id', 'affected_user_id');

    $single = collect(Schema::getIndexes('lead_queue_events'))->filter(fn (array $index): bool => count($index['columns']) === 1)->map(fn (array $index): string => $index['columns'][0])->all();

    expect($single)->toContain('lead_id', 'lead_cadence_id', 'contact_attempt_id', 'location_id', 'actor_id', 'impersonator_id', 'affected_user_id');

    $unique = collect(Schema::getIndexes('lead_cadences'))->filter(fn (array $index): bool => $index['unique'])->map(fn (array $index): array => $index['columns'])->values()->all();

    expect($unique)->toContain(['lead_id'], ['held_by_user_id']);

    $attemptUnique = collect(Schema::getIndexes('contact_attempts'))->filter(fn (array $index): bool => $index['unique'])->map(fn (array $index): array => $index['columns'])->values()->all();

    expect($attemptUnique)->toContain(['lead_id', 'cadence_cycle', 'cadence_step']);

    $cooldown = collect(Schema::getIndexes('lead_queue_events'))->firstWhere('name', 'lead_queue_events_cooldown_index');

    expect($cooldown['columns'])->toBe(['lead_cadence_id', 'affected_user_id', 'type', 'cooldown_until']);
});
