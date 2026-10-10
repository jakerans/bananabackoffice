# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `checkpoint-agent-queue-manager-switch` (three steps, each on top of the one before)

**State:** three approved steps are built and waiting for Jacob's word to merge.
Each is its own commit, stacked in this order, and each lower branch can be
merged without the ones above it:

1. **The MySQL deadlock fix** (decision 87): branch
   `checkpoint-queue-deadlock-fix`. Nothing visible changes.
2. **The three display faults** found in the browser review of the Call Queue:
   branch `checkpoint-call-queue-review-fixes`.
3. **The manager-view switch for Call Center Agents** (decision 85): this
   branch. It adds one section to the Contact Center settings page, and it
   needs a browser look (steps under "Step 3" below).

Nothing else is approved to build.

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
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)", deadlock fix alone (`bc8e5a9`) | **Both passed** (run 38014935318). This is the proof on MySQL: the forced deadlocks, the three raced pairs and every earlier real-process test, with the stricter error check. |
| `composer test` with the display fixes on top, Windows development machine | **Passed:** 1,984 tests (1,983 passed, 1 skipped, 9,958 assertions) in 277 seconds. |
| The same two GitHub jobs with the display fixes on top (`a59807c`) | **Both passed** (run 38015705071). A second pass on MySQL 8.4 for the deadlock tests. |
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
faults were found, and all three are fixed on this branch** (see "The display
fixes" below).

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

Faults, as first seen:

- **A. Skip dialog:** each error message appears twice, and "choose a reason"
  stays on screen after a reason is chosen.
- **B. Put back in queue:** an empty note is refused, but a few seconds later
  no message is on screen.
- **C. Lead page queue history:** an entry with no reason starts with a stray
  "·" before the person's name.

### The display fixes

- **A.** On the Call Queue page the Skip and Disqualify dialogs printed each
  field's error twice: a labelled field shows its own error, and a second copy
  had been placed beside it. The four second copies are removed, and choosing
  a skip reason now clears the earlier message.
- **B.** The real cause, found on a second look: the message did appear, and
  the Call Queue page's ten-second refresh then wiped it. The framework keeps
  an error from one request to the next only when it is named after a field of
  the page, and this one was named "note". It is now the note field's own
  error on both the Call Queue page and the Lead page, shown beside the note
  and cleared before each save. (The Lead page has no refresh, so there the
  message had stayed; it moved from the top of the dialog to beside the note.)
- **C.** A separator is now printed only between two things.

Tests, all in `tests/Feature/Queue/QueueScreenTest.php`: each message is in the
page exactly once; choosing a skip reason clears the message; the put-back
message is still there after the page's refresh; and a history entry reads
"Avery Agent · attempt 1", never "· Avery Agent · attempt 1". Two existing
assertions changed with the behaviour: the put-back error is now expected under
the note field's name on both pages. Nothing was removed.

**One more of the same kind, not fixed:** on the Call Queue page, a refusal that
belongs to no field also disappears at the next refresh. The one a person can
meet is in the Disqualify dialog: "This lead was just changed by someone else,
so nothing was saved." It shows for up to ten seconds and then goes. Nothing
is saved wrongly; the person just loses the explanation. See "Decisions needed".

How it was tested, where that differs from a person at two browsers: the second
person in steps 7 and 12 was the application's own actions run from the command
line; one hold was made to run out by changing its end time instead of waiting;
and "a Location Manager sees only their own Locations' numbers" could not be
told apart that evening because the other Location was closed. The automated
tests cover each of these.

Left on the local database only: seven seeded fictitious staff, three Locations
(one set to Pacific) and 14 fictitious Leads named "Queue Review … (Sample)".

## Step 3: the manager-view switch for Call Center Agents (decision 85)

Objective: a Call Center Agent sees the manager part of the call queue only
when Owner or Leadership has switched it on for that one person.

### What changed

- **One rule, in one place** (`User::managesQueueAt` and
  `managesQueueAcrossAllLocations`): the person may assign Leads there, and
  where that comes from Call Center Agent access their switch must be on. The
  two policies (`LeadPolicy::manageQueue`, `returnToQueue`) and the manager
  summary's scope (`DueQueue::managerScope`) use it.
- **What an agent with the switch off no longer has:** on the Call Queue page
  the summary, the timer warning, the "Cadence finished, no contact" list and
  Put back in queue; on a Lead the "Call queue" panel, the queue history and
  Put back in queue. Everything else is as before: Next lead, the card, Log
  attempt, Skip, Disqualify, and assigning Leads.
- **Who is not affected:** Owner, Leadership and Location Manager, and a person
  who is an agent and also a Location Manager at the same Location.
- **The switch itself:** a yes or no on the person (`users.queue_manager_view`,
  one new migration, off by default). Not a permission; no role grant changed.
- **Where it is changed:** Company > Settings > Contact Center, a new last
  section "Manager view for Call Center Agents". It lists every active person
  with Call Center Agent access, with where they work, and one switch each. A
  switch saves by itself when it is changed
  (`app/Actions/ContactCenter/SetQueueManagerView.php`).
- **Audit:** each change writes `staff.queue_manager_view_changed`, about the
  agent, with the person who changed it.
- **Documents:** decision 85, the permission model, the slice 4 plan, the
  roadmap, the security notes and the README's "Where things live".
- **No** new permission, role grant, navigation item, route or package.

### When this is deployed

**Every agent's switch starts off.** From the deployment, Call Center Agents
stop seeing the manager part until someone switches it on for them. That is
what decision 85 asks for; it is here so nobody is surprised.

### Tests

New `tests/Feature/Queue/QueueManagerViewTest.php`:

| What | Test |
| --- | --- |
| The rule, as a matrix of twelve people against both policies | "who has the manager part of the queue for a lead" |
| The summary's scope, including an agent who also manages one Location | "the manager part covers only the locations the person has it for" |
| The Call Queue page with the switch off and then on, and that the agent can still work a Lead | "an agent works the queue without the manager part until it is switched on" |
| Switching it off under an open dialog | "switching it off while a put-back dialog is open refuses the save" |
| The Lead page panel | "the call queue panel on a lead follows the switch" |
| Who may change it, the audit event, and no event when nothing changes | "owner and leadership switch it on and off…", "nobody else may change the switch, including the agent themselves" |
| Refusing a person who is not an agent | "a person who is not a call center agent cannot be switched" |
| The settings page: the list, the empty state, saving at once, a made-up or wrong ID, and a person who lost the settings permission | the five "settings page" tests |

`composer test` with this step on top, on the Windows development machine:
**passed**, 2,015 tests (2,014 passed, 1 skipped, 10,091 assertions) in 276
seconds. The two GitHub jobs for this step are reported with the branch and not
recorded here; this step has a migration, so the MySQL 8.4 job is the one that
shows it applies there.

Three existing tests changed with the rule, as decision 85 requires; none was
removed. In `ReturnLeadToQueueTest`, "a call center agent" may no longer put a
Lead back (three new rows cover an agent switched on, switched on elsewhere,
and one who is also the Location Manager), and the unrouted test's agent now
has the switch on, with a new line that an agent with it off is refused. In
`QueueScreenTest`, "a location manager sees only their locations numbers" now
checks the agent with the switch off and then on.

### Architectural decisions made while building

- **A yes or no on the person, not a permission.** A permission here belongs to
  a role, and this is decided person by person. Making it a permission would
  have needed a new kind of grant.
- **The switches live on the Contact Center settings page,** which Owner and
  Leadership already manage, not on each person's page in Users and Access.
  One list, one place.
- **A person who may assign through another role keeps the manager part.** The
  switch only narrows what Call Center Agent access gives.

### Browser review for Jacob

Not looked at in a browser yet (each browser action needs an approval click, so
this one is left for you or for a go-ahead). On the local site, signed in as an
Owner:

1. Open Company > Settings > Contact Center and scroll to the last section,
   **Manager view for Call Center Agents**. It lists Casey Callcenter with "All
   locations" and a switch that is off. Managers and other roles are not
   listed.
2. View Backoffice as Casey Callcenter and open Marketing > Contact Center >
   Call Queue. You see the due count and Next lead, and none of: the four
   summary numbers, the yellow timer warning, the "Cadence finished, no
   contact" list.
3. Return to the Owner, switch Casey on. A message confirms it. View as Casey
   again: the summary, the warning and the list are back.
4. As Casey, open a Lead whose cadence finished: the "Call queue" panel shows.
   Switch Casey off and reload the Lead: the panel is gone.
5. View as Morgan Manager: the manager part shows whatever Casey's switch says.

### Known limitations

- The switch can be changed for an agent who is deactivated only once they are
  active again: the list shows active people. A deactivated agent keeps
  whatever their switch was.
- A person given Call Center Agent access later starts with the switch off,
  and a person who loses it keeps the stored value, which then means nothing.
- The settings page does not say who last changed a switch; the audit event
  does, and there is still no screen for reading audit events.

## A timing-dependent test to watch

During one local run of the queue tests on this branch, the existing step 4b
test "a change that takes a lead away while it is being claimed…" (the access
revoked case) failed with "The claim never got in first". That test needs both
orders of a real race to happen at least once within its rounds, and in that run
one order never did. It passed when run again, in every full local run, and in
all GitHub runs. It asserts nothing about correctness when it fails this way,
but it can fail a run on a busy machine. Not changed; mentioned so a red run is
recognised.

## Decisions needed from Jacob

1. **Merge?** Say which: only the deadlock fix, the deadlock fix and the
   display fixes, or all three. Each merge redeploys staging, and the third
   switches every agent's manager part off until it is switched on.
2. **The disappearing "changed by someone else" message on the Call Queue
   page.** Keep it as is, or show it in a way that stays until the person has
   read it. Recommendation: show it as the same kind of notice the page
   already uses when a card goes away, which stays until the next action. It
   is a small change and was not in the three faults, so it waits for your yes.
3. **What to plan next:** building appointments from the rulebook, Projects and
   the sales outcome, or production readiness. Each needs its own plan and
   approval. Nothing is being built until you choose.

## Known limitations carried forward

- The other follow-ups from the job #12 handoff are still open and none is
  authorized: the held and cadence-finished lists stop at 25 with no notice; the
  card shows the Lead's inquiry phone while the attempt form pre-fills the linked
  contact's phone; losing access while the Skip dialog is open shows a
  "forbidden" page.
- The device-time formatter test skips itself on the Windows development
  machine, because it looks for Node with a command Windows does not have.
- Staging runs PHP 8.5 while local development and both GitHub jobs use PHP 8.4.
