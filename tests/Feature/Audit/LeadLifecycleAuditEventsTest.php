<?php

use App\Actions\Leads\CorrectLeadDisqualification;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\QualifyLead;
use App\Actions\Leads\ReopenLead;
use App\Actions\Leads\ReturnLeadToWorking;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The one audit event of a kind about a lead's lifecycle.
 */
function lifecycleEvent(AuditAction $action): AuditEvent
{
    return AuditEvent::query()->where('action', $action)->sole();
}

function disqualificationReason(string $name): LeadDisqualificationReason
{
    return LeadDisqualificationReason::query()->where('name', $name)->sole();
}

beforeEach(function () {
    $this->travelTo('2026-10-06 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Mona Manager']);
    $this->consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
});

test('qualifying a lead is recorded', function () {
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($this->lakeview)->working()->assignedTo($this->consultant)
        ->from($account, $account->primaryContact(), Property::factory()->of($account)->create())
        ->create();

    app(QualifyLead::class)->handle($lead, $this->consultant);

    $event = lifecycleEvent(AuditAction::LeadQualified);

    expect($event->actor->is($this->consultant))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($this->lakeview))->toBeTrue();
    expect($event->before())->toBe(['status' => 'working']);
    expect($event->after())->toBe(['status' => 'qualified']);
});

test('returning a qualified lead to working is recorded', function () {
    $lead = Lead::factory()->at($this->lakeview)->qualified()->assignedTo($this->consultant)->create();

    app(ReturnLeadToWorking::class)->handle($lead, $this->manager);

    $event = lifecycleEvent(AuditAction::LeadReturnedToWorking);

    expect($event->actor->is($this->manager))->toBeTrue();
    expect($event->before())->toBe(['status' => 'qualified']);
    expect($event->after())->toBe(['status' => 'working']);
});

test('moving a lead to nurture, rescheduling it and resuming it are each recorded', function () {
    $lead = Lead::factory()->at($this->lakeview)->working()->assignedTo($this->consultant)->create();

    app(MoveLeadToNurture::class)->handle($lead, '2026-11-02', 'Wants a quote after the roof.', $this->consultant);

    $moved = lifecycleEvent(AuditAction::LeadMovedToNurture);

    expect($moved->before()['status'])->toBe('working');
    expect($moved->after()['status'])->toBe('nurture');
    expect($moved->after()['follow_up_on'])->toBe('2026-11-02');
    expect($moved->reason)->toBe('Wants a quote after the roof.');

    app(MoveLeadToNurture::class)->handle($lead, '2026-11-20', 'Roof delayed.', $this->consultant);

    $rescheduled = lifecycleEvent(AuditAction::LeadFollowUpRescheduled);

    expect($rescheduled->before()['follow_up_on'])->toBe('2026-11-02');
    expect($rescheduled->after()['follow_up_on'])->toBe('2026-11-20');
    expect($rescheduled->reason)->toBe('Roof delayed.');

    app(ReturnLeadToWorking::class)->handle($lead, $this->consultant);

    $resumed = lifecycleEvent(AuditAction::LeadResumedFromNurture);

    expect($resumed->before()['status'])->toBe('nurture');
    expect($resumed->before()['follow_up_on'])->toBe('2026-11-20');
    expect($resumed->after())->toBe(['status' => 'working']);
});

test('disqualifying a lead records the reason, its category and the handoff it ended', function () {
    $lead = Lead::factory()->at($this->lakeview)->working()->assignedTo($this->consultant)->create();

    app(DisqualifyLead::class)->handle($lead, disqualificationReason('Other'), 'A film set for one day.', $this->consultant);

    $event = lifecycleEvent(AuditAction::LeadDisqualified);

    expect($event->actor->is($this->consultant))->toBeTrue();
    expect($event->location->is($this->lakeview))->toBeTrue();
    expect($event->before()['status'])->toBe('working');
    expect($event->before()['assignee'])->toBe('Sam Sales');
    expect($event->after()['status'])->toBe('disqualified');
    expect($event->after()['reason'])->toBe('Other');
    expect($event->after()['category'])->toBe('other');
    expect($event->reason)->toBe('A film set for one day.');

    // Ending the assignment is its own event, with why it ended.
    $unassigned = lifecycleEvent(AuditAction::LeadUnassigned);

    expect($unassigned->before()['assignee'])->toBe('Sam Sales');
    expect($unassigned->after()['cause'])->toBe('lead_disqualified');
});

test('a refused nonresponse closure records no audit or status event and keeps the assignment', function () {
    $lead = Lead::factory()->at($this->lakeview)->working()->assignedTo($this->consultant)->create(['work_started_at' => now()->subYear()]);
    $auditCount = AuditEvent::query()->count();

    expect(fn () => app(DisqualifyLead::class)->handle($lead, disqualificationReason('Unable to Contact'), 'No response.', $this->manager))
        ->toThrow(ValidationException::class);

    expect(AuditEvent::query()->count())->toBe($auditCount);
    expect(LeadStatusChange::query()->count())->toBe(0);
    expect($lead->fresh()->assignee->is($this->consultant))->toBeTrue();
});

test('correcting a disqualification records what it said before and after', function () {
    $original = Lead::factory()->at($this->lakeview)->create();
    $lead = Lead::factory()->at($this->lakeview)->working()->create();

    app(DisqualifyLead::class)->handle($lead, disqualificationReason('Spam / Solicitation'), null, $this->manager);
    app(CorrectLeadDisqualification::class)->handle($lead->fresh(), disqualificationReason('Duplicate Lead'), null, $this->manager, $original);

    $event = lifecycleEvent(AuditAction::LeadDisqualificationCorrected);

    expect($event->before()['reason'])->toBe('Spam / Solicitation');
    expect($event->before()['category'])->toBe('invalid');
    expect($event->before()['duplicate_of_lead_id'])->toBeNull();
    expect($event->after()['reason'])->toBe('Duplicate Lead');
    expect($event->after()['duplicate_of_lead_id'])->toBe($original->id);
});

test('reopening a lead records why, and what it had been disqualified for', function () {
    $lead = Lead::factory()->at($this->lakeview)->working()->assignedTo($this->consultant)->create();

    app(DisqualifyLead::class)->handle($lead, disqualificationReason('Service Not Offered'), null, $this->consultant);
    app(ReopenLead::class)->handle($lead->fresh(), 'We now offer cabinet refinishing.', $this->manager);

    $event = lifecycleEvent(AuditAction::LeadReopened);

    expect($event->actor->is($this->manager))->toBeTrue();
    expect($event->before()['status'])->toBe('disqualified');
    expect($event->before()['reason'])->toBe('Service Not Offered');
    expect($event->before()['category'])->toBe('serviceability');
    expect($event->after()['status'])->toBe('working');
    expect($event->after()['assignee'])->toBeNull();
    expect($event->reason)->toBe('We now offer cabinet refinishing.');
});

/*
|--------------------------------------------------------------------------
| Through impersonation
|--------------------------------------------------------------------------
*/

test('a status change made while viewing Backoffice as someone names them on the lead and the Owner in the audit history', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $lead = Lead::factory()->at($this->lakeview)->working()->assignedTo($this->consultant)->create();

    impersonate($owner, $this->consultant);

    $page = livewireSnapshot($this->get(route('leads.show', $lead))->assertOk());
    $dialog = json_decode(sendLivewireAction($page, 'openDisqualify')->assertOk()->json('components.0.snapshot'), true);

    // Nonresponse remains unavailable during impersonation too.
    sendLivewireAction($dialog, 'disqualify', updates: [
        'disqualificationReasonId' => disqualificationReason('Unable to Contact')->id,
        'disqualificationNote' => 'Called once.',
    ])->assertOk();

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);

    sendLivewireAction($dialog, 'disqualify', updates: [
        'disqualificationReasonId' => disqualificationReason('Service Not Offered')->id,
    ])->assertOk();

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);

    // On the lead and in its history: the staff identity the work was done as.
    expect($lead->fresh()->disqualifiedBy->is($this->consultant))->toBeTrue();
    expect(LeadStatusChange::query()->sole()->changedBy->is($this->consultant))->toBeTrue();

    // In the audit history: both, never the consultant alone.
    $event = lifecycleEvent(AuditAction::LeadDisqualified);

    expect($event->actor->is($this->consultant))->toBeTrue();
    expect($event->impersonator->is($owner))->toBeTrue();
    expect($event->madeBy())->toBe('Jacob Ransom, impersonating Sam Sales');
    expect(lifecycleEvent(AuditAction::LeadUnassigned)->madeBy())->toBe('Jacob Ransom, impersonating Sam Sales');
});
