<?php

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\CorrectLeadDisqualification;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\ReopenLead;
use App\Actions\Leads\ReturnLeadToWorking;
use App\Actions\Leads\StartWorkingLead;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->freezeSecond();
    $this->location = Location::factory()->create();
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $this->consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->location)->create();
});

test('a stale action cannot overwrite a follow-up changed in the same second', function () {
    $lead = Lead::factory()->at($this->location)->nurture()->assignedTo($this->consultant)->create();
    $stale = $lead->fresh();

    app(MoveLeadToNurture::class)->handle($lead, now()->addMonth()->toDateString(), 'Winning callback.', $this->owner);
    $before = $lead->fresh()->getAttributes();
    $audits = AuditEvent::query()->count();

    expect(fn () => app(MoveLeadToNurture::class)->handle($stale, now()->addWeek()->toDateString(), 'Stale callback.', $this->owner))
        ->toThrow(ValidationException::class, 'just changed');

    expect($lead->fresh()->getAttributes())->toBe($before);
    expect(LeadStatusChange::query()->sole()->note)->toBe('Winning callback.');
    expect(AuditEvent::query()->count())->toBe($audits);
});

test('a same-status follow-up dialog rejects a stale note-only reschedule and can be reopened', function () {
    $lead = Lead::factory()->at($this->location)->nurture()->assignedTo($this->consultant)->create();
    $date = $lead->follow_up_on->toDateString();
    $page = Livewire::actingAs($this->consultant)->test('pages::leads.show', ['lead' => $lead])->call('openNurture');

    app(MoveLeadToNurture::class)->handle($lead, $date, 'New instructions.', $this->owner);

    $page->set('followUpNote', 'Old instructions.')->call('saveNurture')->assertHasErrors('stale');
    expect($lead->fresh()->follow_up_note)->toBe('New instructions.');
    expect(LeadStatusChange::query()->count())->toBe(1);

    $page->call('openNurture')->assertSet('followUpNote', 'New instructions.')
        ->set('followUpNote', 'Reviewed instructions.')->call('saveNurture')->assertHasNoErrors();
    expect($lead->fresh()->follow_up_note)->toBe('Reviewed instructions.');
});

test('a stale correction cannot overwrite another correction while both remain disqualified', function (bool $throughDialog) {
    $reason = LeadDisqualificationReason::query()->where('name', 'Other')->sole();
    $lead = Lead::factory()->at($this->location)->disqualified($reason)->create(['disqualification_note' => 'Original.']);
    $stale = $lead->fresh();
    $page = $throughDialog
        ? Livewire::actingAs($this->owner)->test('pages::leads.show', ['lead' => $lead])->call('openCorrectDisqualification')
        : null;

    app(CorrectLeadDisqualification::class)->handle($lead, $reason, 'Winning correction.', $this->owner);
    $before = $lead->fresh()->getAttributes();
    $audits = AuditEvent::query()->count();

    if ($page !== null) {
        $page->set('disqualificationNote', 'Stale correction.')->call('disqualify')->assertHasErrors('stale');
    } else {
        expect(fn () => app(CorrectLeadDisqualification::class)->handle($stale, $reason, 'Stale correction.', $this->owner))
            ->toThrow(ValidationException::class, 'just changed');
    }

    expect($lead->fresh()->getAttributes())->toBe($before);
    expect(LeadStatusChange::query()->sole()->note)->toBe('Winning correction.');
    expect(AuditEvent::query()->count())->toBe($audits);
})->with(['action' => false, 'dialog' => true]);

test('a lead returning to the same status still invalidates an earlier dialog', function () {
    $lead = Lead::factory()->at($this->location)->nurture()->assignedTo($this->consultant)->create();
    $page = Livewire::actingAs($this->consultant)->test('pages::leads.show', ['lead' => $lead])->call('openNurture');

    app(ReturnLeadToWorking::class)->handle($lead, $this->owner);
    app(MoveLeadToNurture::class)->handle($lead, now()->addMonth()->toDateString(), 'New callback.', $this->owner);

    $page->set('followUpNote', 'Old callback.')->call('saveNurture')->assertHasErrors('stale');
    expect(LeadStatusChange::query()->count())->toBe(2);
    expect($lead->fresh()->follow_up_note)->toBe('New callback.');
});

test('return to working confirmation cannot discard a follow-up rescheduled while it is open', function () {
    $lead = Lead::factory()->at($this->location)->nurture()->assignedTo($this->consultant)->create();
    $page = Livewire::actingAs($this->consultant)->test('pages::leads.show', ['lead' => $lead])->call('openReturnToWorking');

    app(MoveLeadToNurture::class)->handle($lead, now()->addMonth()->toDateString(), 'Keep this callback.', $this->owner);

    $page->call('resumeWorking')->assertHasErrors('stale');
    expect($lead->fresh()->status)->toBe(LeadStatus::Nurture);
    expect($lead->fresh()->follow_up_note)->toBe('Keep this callback.');
    expect(LeadStatusChange::query()->count())->toBe(1);
});

test('stale assignment context is rejected before a lifecycle write', function () {
    $lead = Lead::factory()->at($this->location)->working()->assignedTo($this->consultant)->create();
    $stale = $lead->fresh();
    $other = User::factory()->withRoleAt(Role::SalesConsultant, $this->location)->create();
    app(AssignLead::class)->handle($lead, $other, $this->owner);

    expect(fn () => app(MoveLeadToNurture::class)->handle($stale, now()->addWeek()->toDateString(), 'Stale callback.', $this->consultant))
        ->toThrow(ValidationException::class, 'just changed');

    expect($lead->fresh()->assigned_user_id)->toBe($other->id);
    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
    expect(LeadStatusChange::query()->count())->toBe(0);
});

test('two snapshots cannot both start, close or reopen the same lead', function (string $operation) {
    $lead = Lead::factory()->at($this->location)->assignedTo($this->consultant)->create();
    $reason = LeadDisqualificationReason::query()->where('name', 'Spam / Solicitation')->sole();
    if ($operation === 'reopen') {
        app(DisqualifyLead::class)->handle($lead, $reason, null, $this->owner);
    }
    $stale = $lead->fresh();
    $change = match ($operation) {
        'start' => fn (Lead $snapshot) => app(StartWorkingLead::class)->handle($snapshot, $this->consultant),
        'close' => fn (Lead $snapshot) => app(DisqualifyLead::class)->handle($snapshot, $reason, null, $this->owner),
        'reopen' => fn (Lead $snapshot) => app(ReopenLead::class)->handle($snapshot, 'A mistake.', $this->owner),
    };
    $change($lead);
    $before = $lead->fresh()->getAttributes();
    $historyCount = LeadStatusChange::query()->count();
    $auditCount = AuditEvent::query()->count();

    expect(fn () => $change($stale))->toThrow(ValidationException::class, 'just changed');

    expect($lead->fresh()->getAttributes())->toBe($before);
    expect(LeadStatusChange::query()->count())->toBe($historyCount);
    expect(AuditEvent::query()->count())->toBe($auditCount);
})->with(['start', 'close', 'reopen']);

test('an unchanged follow-up neither advances the revision nor appends history', function () {
    $lead = Lead::factory()->at($this->location)->nurture()->assignedTo($this->consultant)->create()->fresh();
    $before = $lead->getAttributes();

    app(MoveLeadToNurture::class)->handle($lead, $lead->follow_up_on->toDateString(), $lead->follow_up_note, $this->consultant);

    expect($lead->fresh()->getAttributes())->toBe($before);
    expect(LeadStatusChange::query()->count())->toBe(0);
});
