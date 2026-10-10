# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `checkpoint-queue-deadlock-fix`

**State:** the MySQL deadlock fix (decision 87) is built on its own branch and
waiting for Jacob's word to merge. Nothing visible changed. The browser review
of the Call Queue (slice 4, step 4c) was done the same day and is reported
below; it found three display faults that are not fixed yet.

**Approved and not yet built:** the three display faults, and the switch that
lets a Call Center Agent see the manager part of the Call Queue (decision 85).

## Step

The MySQL deadlock fix. Plan: [../slice-4-plan.md](../slice-4-plan.md),
contract 5 ("Locks") and "Known limits and recorded follow-ups". Decision 87 in
[../decisions.md](../decisions.md).

Objective: two staff actions on the same held Lead within the same few
milliseconds must both finish, with nothing half-saved and nobody shown a
database error.

## What changed

- **Lock order.** Ending the holds at a Location that is being deactivated
  (`EndQueueHold::atLocation`) and the timer's release of a hold that ran out
  (`RunQueueTick::releaseExpiredHold`) now lock the Lead row before the cadence
  row, the order a claim uses.
- **Run again on a deadlock.** A new `QueueTransaction::attempt` runs a whole
  action again from the start, up to three more times, when the database reports
  a deadlock or says it is busy. Before each new try the action's records are
  read again, because a failed try is undone in the database but not in memory.
  It is used by `RouteLead`, `ReturnLeadToCentral`, `RevokeAccess`,
  `DeactivateStaffMember`, `DeactivateLocation` and `RecordManualContactAttempt`.
- **Disqualify and Deferred Follow-up** go through the shared
  `ChangeLeadLifecycle`. It gained an opt-in "run again when busy" that only
  those two use. Every try checks the Lead against what the person saw when
  they opened the action, so a Lead someone else really changed is still
  refused as stale.
- **An action called inside another transaction is not run again.** A deadlock
  undoes the outer transaction too, so its owner has to decide.
- **Tests.** A new `tests/Feature/Queue/DeadlockRetryTest.php`, and two new
  tests in `tests/Feature/Queue/QueueConcurrencyTest.php` that use real separate
  processes (below).
- **Documents.** Decisions 85 and 87, the slice 4 plan, the roadmap and the
  security notes.
- **No** new table, migration, permission, role grant, audit event, screen or
  package.

## A fault found in the existing tests, and corrected

The real-process queue tests from step 4b say "no process may end in an error".
The line that checked it never failed when a process did end in an error: the
test library's "has this key" check was given a second argument, which it
treats as a value to compare, so the "not" version passed whenever an error was
present. Six such checks are corrected to fail on any error. This makes the
tests stricter; none was removed or weakened. With the stricter check, all the
existing real-process tests still pass.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 1,983 tests (1,982 passed, 1 skipped, 9,947 assertions) in 282 seconds. Before this step it was 1,973 tests in about 220 to 235 seconds. The skipped test is the device-time formatter (see the end of this file). |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| A private MySQL 8.4 instance | **Not run.** None is installed on the development machine. The GitHub MySQL 8.4 job is the proof on MySQL. |
| Browser review | Not applicable to this step: nothing visible changed. |
| Merge into `main`, staging | **Not done.** Waiting for Jacob. |

What the new tests prove:

| Test | What it shows |
| --- | --- |
| "an action that meets a deadlock once runs again from the start and saves everything exactly once" (real processes) | Each of the eight actions is given a real deadlock error on a statement near its end, after its earlier writes. The database really rolls the transaction back. Each action then finishes by itself, and afterwards there is exactly one of everything: one move, one audit entry, one ended assignment, one history entry, one contact attempt, one lifecycle step. |
| "two changes to the same hold at the same moment both finish and the hold is ended once" (real processes, ten staggered rounds each) | Three pairs raced for real: the holder is deactivated while a manager moves the Lead; the holder is deactivated while another setter takes over their hold that ran out; the timer releases a hold that ran out while another setter claims the Lead. No process ends in an error, both changes are saved, and the hold is ended exactly once. |
| `DeadlockRetryTest`: three tests of `QueueTransaction::attempt` | Run again with the records put back first; give up after three more tries; never run again for any other error; never run again inside another transaction. |
| `DeadlockRetryTest`: two lock-order tests | The Lead row is read (and on MySQL locked) before the cadence row when a Location is deactivated and when the timer releases a hold. |

## Architectural decisions made while building

- **The whole action is run again, checks included,** not just its transaction.
  The checks ("is this Lead still active?", "is this the only Owner?") then see
  what the other person just saved.
- **Disqualify and Deferred Follow-up retry inside `ChangeLeadLifecycle`,** by
  an opt-in flag, so the other lifecycle changes behave exactly as before.
- **`QueueTransaction::run`, which the claim uses, is unchanged.** The new
  "not inside another transaction" rule is on the new method only.

## Deviations from the approved scope

- **The test-check correction** above was not in the scope. It was needed to
  trust the tests this fix relies on.
- **`tests/Support/queue-process.php` gained operations** for the new tests (move
  back to central, deactivate a Location, disqualify, follow-up, log an attempt,
  run the timer, prepare holds, and a report of the other records), and a way to
  answer one insert with a deadlock error. Test support only.

## Known limitations

- **One pair still depends on the retry rather than on lock order:**
  deactivating a Location takes the Location row and then a Lead row; a claim
  takes them the other way round. Changing that order would change the rule
  that `DeactivateLocation` locks its own row first.
- **Not retried, on purpose:** the timer's own work (it logs and its next run
  tries again), Skip and Put back in queue (the page answers "try again"),
  capturing a new Lead, and the lifecycle changes that do not touch the queue.
- **After three more tries the error is still passed on.** On the Call Queue
  and Lead pages that is the existing "try again" message; on the Settings
  pages it is an error page.
- The "deactivate a Location while a Lead there is being claimed" pair cannot
  be raced for real, because a Location with active Leads is refused before it
  reaches the hold. It is covered by the lock-order test and the forced
  deadlock.
- The suite is longer: the real-process file went from seven to eleven tests.

## Browser review of the Call Queue (done 2026-10-09)

Done on the local site by the implementation agent at Jacob's request, signed in
as the seeded fictitious staff. **The queue works as designed. Three display
faults were found; none is fixed yet.**

| Step | Result |
| --- | --- |
| 1. Each role's menu | Pass. Owner, Leadership, Location Manager and Call Center Agent see Leads and Call Queue. Sales Consultant, Production Manager and Finance do not, and the address is refused. Contact Center still opens Leads. |
| 2. Next lead | Pass. A count and one card, never a list; the newest never-called Lead came first. |
| 3. The card and its times | Pass. A Lead at a Pacific Location showed the device's Eastern time beside the Location's Pacific time. The hold counts down. |
| 4. Log attempt | Pass. |
| 5. Skip | Pass, with fault A. |
| 6. Disqualify | Pass, including the Duplicate choice and its refusal without an original or a note. |
| 7. A hold that is gone | Pass. Moving the held Lead replaced the card with the reason within about 15 seconds. A call form left open past the hold still saved. |
| 8. Closing time | Pass, seen live at 9:00 pm Eastern. |
| 9. Manager summary | Pass. A Call Center Agent sees it too, as expected until the switch is built. |
| 10. Cadence-finished list and Put back | Pass, with fault B. |
| 11. Lead page panel | Pass. The Sales Consultant sees none of it. |
| 12. Two people | Partly. A manager in the browser and an agent from the command line were given different Leads. Two buttons were not pressed at the same instant; the eight-process test covers that. |

Faults:

- **A. Skip dialog:** each error message appears twice, and "choose a reason"
  stays on screen after a reason is chosen.
- **B. Put back in queue (both pages):** an empty note is refused but no message
  appears. The error is attached to a field name the screen does not show.
- **C. Lead page queue history:** an entry with no reason starts with a stray
  "·" before the person's name.

How it was tested, where that differs from a person at two browsers: the second
person in steps 7 and 12 was the application's own actions run from the command
line; one hold was made to run out by changing its end time instead of waiting;
and "a Location Manager sees only their own Locations' numbers" could not be
told apart that evening because the other Location was closed. The automated
tests cover each of these.

Left on the local database only: seven seeded fictitious staff, three Locations
(one set to Pacific) and 14 fictitious Leads named "Queue Review … (Sample)".

## Decisions needed from Jacob

1. **Merge the deadlock fix?** It redeploys staging. Nothing visible changes.
2. **What to plan after the three faults and the agent switch:** building
   appointments from the rulebook, Projects and the sales outcome, or production
   readiness. Each needs its own plan and approval.

## Known limitations carried forward

- The other follow-ups from the job #12 handoff are still open and none is
  authorized: the held and cadence-finished lists stop at 25 with no notice; the
  card shows the Lead's inquiry phone while the attempt form pre-fills the linked
  contact's phone; losing access while the Skip dialog is open shows a
  "forbidden" page.
- The device-time formatter test skips itself on the Windows development
  machine, because it looks for Node with a command Windows does not have.
- Staging runs PHP 8.5 while local development and both GitHub jobs use PHP 8.4.
