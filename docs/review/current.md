# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `office/11-slice-4b-scheduling-engine-and-atomic-du`
**State:** Job #11 (Build, step 4b) is built and committed on its branch, awaiting
review after **fix round 1** (see "Fix round 1" below). It is **not pushed, has no pull request, and is not merged.** The two
GitHub jobs have not run on it. Nothing is deployed to staging. Production is not
touched and waits for Jacob.

## Step

Slice 4, step 4b: the scheduling engine and the atomic due queue. Plan:
[../slice-4-plan.md](../slice-4-plan.md) (contracts 1 to 6, "Data model > Step 4b",
"Step 4b — The engine"); decisions 65 to 74 in [../decisions.md](../decisions.md).

## Objective

Build the engine that reads the 4a settings, with no screen. After 4b: every newly
captured Lead enters the phone cadence; a logged contact attempt advances it; one
setter can atomically be given one due Lead under a timed hold; skip, Put back in
queue and the every-minute background timer exist as actions and a command.
Nothing is visible to staff until step 4c.

## Fix round 1

The first review did not approve the work. Three things were fixed, and nothing
else was changed.

1. **The SQLite concurrency proof now runs on the application's own settings.**
   The test processes used to change SQLite for the two crowd tests (write
   transactions that queue, "IMMEDIATE", and a five-second wait). Both are gone:
   `tests/Support/queue-process.php` no longer touches the database
   configuration at all, on either engine. To make the eight-process test
   reliable that way, one thing changed in the application, in `ClaimNextLead`:
   after the locked read of the candidate's cadence row, a candidate that the
   row already shows as taken, not due or out of the queue is passed over
   **without issuing the update that could not have won.** The conditional
   update still decides every claim that can succeed. On SQLite a beaten
   caller therefore no longer competes for the write lock on its way to the
   next candidate, which is what used up the three retries. Nothing particular
   to one database is used. Measured both ways, same machine, same tests:
   without the change 14 of 80 runs failed (always the eight-process test,
   always one process told "try again", never a Lead given twice or lost);
   with it, see the results table.
2. **A logged attempt always ends a hold with `hold_ended`.** `EndQueueHold`
   used to write `hold_expired` when the hold's time had already run out. For a
   logged attempt it now writes exactly one `hold_ended`, reason
   `attempt_logged` (the holder logged it) or `attempt_logged_by_other`, also
   when the hold had lapsed but was still recorded, as contract 4 says. The
   regression test that asserted the old behaviour now asserts the rule, and a
   second one covers someone else's attempt on a lapsed hold.
3. **Put back in queue reads the person again.** `ReturnLeadToQueue` now reads
   the person's `users` row and their access grants again inside its
   transaction (in the claim's lock order: person, grants, then the Lead) and
   authorizes that current person. A copy loaded before they were deactivated
   or lost their grant no longer decides anything. New tests pass a stale copy
   after each of: deactivated, grant revoked, grant moved to another Location,
   person removed; each is refused and the cadence row, the queue history and
   the audit history are unchanged.

### Found while checking fix round 1, and fixed: a MySQL deadlock when a claimer is deactivated

This was not one of the three review points. It is reported here because it
failed a required test and could not honestly be left.

The first full run on MySQL in this round failed once, in the real-concurrency
test "the claimer is deactivated": the deactivating process ended in MySQL's
"Deadlock found" error (nothing was saved wrongly; the deactivation was rolled
back and would have had to be pressed again). MySQL's own deadlock report showed
the cause. `DeactivateStaffMember` (and `RevokeAccess`) locked the held Lead's
cadence row and then wrote the history entry, and that insert needs the Lead
row. A second setter's claim holds the Lead row and waits for the cadence row.
Each waited for the other. The plan's "every path takes its locks in the same
order" did not count the lock the history insert takes on the Lead row.

The fix is in `EndQueueHold::forStaffMember` only: it now locks the Lead row
before the cadence row, the same order as the claim. The hold is still found by
a locking read of `lead_cadences`, as the plan requires; a plain read before it
only says which Lead row to lock first. No behaviour changed. The earlier
builder runs (4 of 4, and one full suite) had simply not met it.

**Still open, for the Architect (see "Decisions needed"):** the same kind of
hidden lock exists in rarer pairs that no test races, and the plan's stated
remedy ("if the database reports a deadlock anyway, the transaction is
retried") is implemented for the claim only, not for the hooked actions.

## What changed

- **Four additive migrations.** `lead_cadences` (one row per Lead that has entered;
  `lead_id` unique, `held_by_user_id` unique, so one cadence per Lead and one held
  Lead per person); `lead_queue_events` (append-only history, every foreign key
  with its own index, plus the cooldown index the claim reads);
  `scheduler_heartbeats`; and two empty-by-default columns on `contact_attempts`
  (`cadence_cycle`, `cadence_step`) with a unique index on
  `(lead_id, cadence_cycle, cadence_step)`. The migrations create no cadence rows:
  Leads that already exist never enter.
- **Calculators** in `app/Support/Queue/`: `CallingHours` (windows, next opening,
  the Eastern fallback for unrouted Leads as its own constant `UNROUTED_TIMEZONE`,
  not the company display timezone) and `CadenceSchedule` (when attempt 1 and each
  later attempt is due, one attempt at a time).
- **The one eligibility rule**, `QueueEligibility`: reads the person, their grants,
  the current settings, the Lead and its Location through row locks, in that fixed
  order, and answers with one of: eligible, access lost, outside calling hours,
  skip cooldown. It is the only code outside the enum and role lists that reads
  `leads.work_queue`.
- **Actions** in `app/Actions/Queue/`: `EnterLeadCadence`, `AdvanceLeadCadence`,
  `EndLeadCadence`, `ClaimNextLead`, `SkipHeldLead`, `EndQueueHold`,
  `ReturnLeadToQueue`, `RunQueueTick`.
  - *Claim:* the existing hold is rechecked first (returned again only if still
    eligible; otherwise ended with its reason). Then up to ten candidates are read
    as a hint, and each is tried in its own short transaction: lock and read the
    current rows, run the eligibility check, then one conditional `UPDATE` decides
    (a candidate the locked row already shows as taken is passed over without it).
    Deadlocks and a busy database are retried three times, then answered "try
    again". No `SKIP LOCKED`, advisory lock or anything one database alone offers.
  - *Skip:* fixed enum `QueueSkipReason` (four values; Other needs a note). Releases
    the hold, leaves `due_at` alone and stores that setter's `cooldown_until` on the
    `skipped` event. Nothing counts skips.
  - *Put back in queue:* needs a note; restarts (new cycle) a finished cadence or
    one with no attempts left, otherwise resumes; the person, their grants and
    the Lead are read again and authorized as they are now; a conditional update
    so a double submit changes nothing; the change, its queue event and its audit event
    `lead.returned_to_queue` save together or not at all. New
    `LeadPolicy::returnToQueue`.
  - *Tick:* heartbeat, release expired holds, repair what a crash left behind. It
    never schedules, finishes or contacts.
- **The command and its schedule.** `php artisan backoffice:queue-tick`, registered
  in `routes/console.php` every minute with `withoutOverlapping(5)`, run inline. No
  queue worker, daemon, queued job or Redis.
- **Hooks in existing actions, nothing else changed in them.** `CaptureLead` enters
  the cadence in the Lead's own transaction. `RecordManualContactAttempt` follows
  contract 4's five steps (lock, replay check before any cadence read, decide,
  insert once with the cadence columns, then advance and end the hold).
  `DisqualifyLead` and `MoveLeadToNurture` end the cadence. `RouteLead` and
  `ReturnLeadToCentral` now lock the Lead row first and end any hold at the end of
  the same transaction. `RevokeAccess` locks the staff member's `users` row first
  and ends their hold if they may no longer work that Lead. `DeactivateStaffMember`
  ends their hold. `DeactivateLocation` locks the Location row first and ends every
  hold on a Lead there. `ChangeContactCenterSettings` locks the settings row it
  replaces.
- **Supporting pieces not named one by one in the work order:** enums
  `CadenceState`, `CadenceEndReason`, `QueueEventType`, `QueueHoldEndReason`; small
  value objects `AttemptEffect`, `QueueClaim`, `QueueEligibilityResult`; and two
  helpers, `QueueHistory` (writes queue events, with impersonation recorded as
  everywhere else) and `QueueTransaction` (the retry). `AppServiceProvider` was not
  changed: the audit event is about the Lead, which already has its short name.
- **No page, Livewire component, route, navigation item, card, summary or list.**
  No package added. No `LeadStatus` case, `lead_status_changes` row or
  `lifecycle_version` change from any cadence code. `assigned_user_id` is neither
  read nor changed by holding, skipping or logging.
- **Documents:** roadmap, slice-4-plan (status lines and the scheduler note),
  permission-model, navigation-map (wording only), security-notes (new "Call queue
  engine" entry), README. This file replaced.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` (style, static analysis, every test), run by the builder on SQLite, on the final code of fix round 1 | **Passed.** Pint passed, PHPStan 0 errors, 1,932 tests passed (9,334 assertions), 4 minutes 42 seconds |
| Whole suite on MySQL, run by the builder (a private throwaway MySQL 8.4.11 under `/tmp` on the office machine; not staging, not the GitHub database; this is not the GitHub job) | **First run of this round: 1 failed of 1,932**, the real-concurrency test "the claimer is deactivated", with a MySQL deadlock in the deactivating process (see "Found while checking fix round 1"). **After that fix, on the final code: passed**, 1,932 tests (9,334 assertions) |
| Migrations on SQLite: fresh, roll back the four 4b migrations, re-apply | **Passed in the first build. Not run again in fix round 1:** no migration was changed |
| Migrations on MySQL 8.4.11 (same private instance) | Apply, roll back the four, re-apply: **passed in the first build, not run again.** In fix round 1 every migration was applied to a new empty database: **passed** |
| `php artisan schedule:list` | **Shows** `* * * * *  php artisan backoffice:queue-tick` |
| SQLite real-concurrency crowd tests (eight processes; four tabs) on the application's own settings, **without** the fix-round-1 change to the claim | **14 of 80 runs failed**, every time the eight-process test with one process told "try again"; no Lead given twice or lost. (A first 25 runs happened to pass; the next 80 did not.) |
| SQLite real-concurrency tests on the final code, application's own settings, nothing changed for the test | **Crowd tests alone: 200 of 200 runs passed** (110 on a quiet machine; 90 with three copies running at once to load it). **Whole file: 18 of 18 passed** (4 of them after the last code change, plus the `composer test` run). No "try again", "database is locked" or deadlock in any |
| MySQL 8.4.11 real-concurrency tests | Before the deadlock fix: whole file 4 of 4 passed, then the one failure above inside the full suite. **On the final code: the three "change takes a Lead away" races 25 of 25 passed; whole file 6 of 6 passed; and once more inside the full suite** |
| Rows left in the shared MySQL database after the concurrency tests | **None** (Leads, cadences, queue events, users all at zero; only the migration's settings row) |
| `composer test` run by the office | **Not yet** |
| GitHub: "Checks and tests (SQLite)" | **Not yet** (not pushed) |
| GitHub: "Migrations and tests (MySQL 8.4)" | **Not yet** (not pushed) |
| Merge into `main` | **Not yet** |
| Staging deployment | **Not yet** |
| Staging scheduler | **Could not be checked.** See "Staging timer" below |
| Click-through testing in a browser | **Does not apply:** 4b has no screen |

Which test file covers which item of the plan's "Step 4b" > "Tests" list:

| Plan item | Test file |
| --- | --- |
| Calendar | `tests/Unit/Support/Queue/CadenceCalendarTest.php`; the "unrouted uses Eastern, then its Location, `due_at` unchanged" case with real rows is in `tests/Feature/Queue/EnterCadenceTest.php` |
| Entering | `tests/Feature/Queue/EnterCadenceTest.php` |
| Advancing | `tests/Feature/Queue/AdvanceCadenceTest.php` |
| Leaving | `tests/Feature/Queue/LeaveCadenceTest.php` |
| Claim, deterministic | `tests/Feature/Queue/ClaimNextLeadTest.php` |
| Claim, real concurrency (eight processes, five Leads) | `tests/Feature/Queue/QueueConcurrencyTest.php` |
| Claim, a change during the claim (deterministic seam) | `tests/Feature/Queue/ClaimDuringChangeTest.php` |
| Claim, the existing hold is rechecked | `tests/Feature/Queue/ClaimDuringChangeTest.php` |
| Claim, a change during the claim (real concurrency, cases a to e) | `tests/Feature/Queue/QueueConcurrencyTest.php` |
| Eligibility | `tests/Feature/Queue/ClaimNextLeadTest.php` |
| Skip; Skip, two setters | `tests/Feature/Queue/SkipHeldLeadTest.php` |
| Hold ends at once (including `attempt_logged` and `attempt_logged_by_other`) | `tests/Feature/Queue/QueueHoldEndsTest.php` |
| Deactivating a Location | `tests/Feature/Queue/QueueHoldEndsTest.php` |
| Put back | `tests/Feature/Queue/ReturnLeadToQueueTest.php` |
| Tick (and the schedule entry) | `tests/Feature/Queue/QueueTickTest.php` |
| Outage | `tests/Feature/Queue/ClaimNextLeadTest.php` ("after a day with no timer…") |
| Append-only; no cadence rows from the migrations; every foreign key indexed | `tests/Feature/Queue/QueueAppendOnlyTest.php` |

The concurrent processes are started by `tests/Support/QueueProcesses.php` running
`tests/Support/queue-process.php` through Laravel's `Process` facade. On MySQL they
share the suite's own database, commit their rows and remove them afterwards; on
SQLite they share a temporary file-backed database that is deleted afterwards. The
script refuses to run outside the testing environment and is not an application
command or route.

## Architectural decisions made while building

None that needs a new entry in `decisions.md`. Implementation choices a reviewer
should know about:

- **"Lock my own row first" is done with a write that changes nothing** (the row's
  ID set to itself) in `RouteLead`, `ReturnLeadToCentral`, `RevokeAccess`,
  `DeactivateLocation` and the settings save. On MySQL that takes the row lock; on
  SQLite it takes the single write lock at the start of the transaction, which
  avoids a stale-read refusal later. Existing behaviour of those actions is
  otherwise unchanged.
- **A hold whose time had already run out is recorded as `hold_expired` when the
  claim, the tick or a change to the Lead, the person or the Location tidies it
  up. A logged attempt is the exception** (contract 4): it always writes exactly
  one `hold_ended`, `attempt_logged` or `attempt_logged_by_other`, naming the
  holder, whether or not the hold's time had run out. One event either way.
- **The tick does not end a hold because calling hours closed.** The plan gives the
  tick two repairs only (a Lead that left; access lost). A hold outside calling
  hours ends at its own time or on the setter's next Next lead.
- **A skip is accepted outside calling hours** as long as the setter still holds
  the Lead and may still work it: giving a Lead back is never refused for the hour.
- **Next lead by someone who may not work the queue anywhere is refused as not
  authorized**, rather than answered "nothing due".
- **The concurrency test processes change no database setting.** On SQLite they
  run with the application's own transaction mode and no wait, so a write that
  meets another process's write is refused at once and the application's three
  retries have to cope; they do, since fix round 1 (above).
- **A candidate the locked row shows as taken is passed over without the
  update** (fix round 1). The plan's step 3 says "claim with one conditional
  update; zero rows means someone else did". The update is still the only thing
  that gives a Lead; what is skipped is an update whose `WHERE` the row just
  read under its lock already fails. The outcome is the same on both engines.

## Deviations from the plan

- None in behaviour.
- **One existing test updated, not weakened:**
  `tests/Feature/Settings/ContactCenterSettingsTest.php`. The 4a test "nothing but
  the enum and the role lists reads the queue permission" could not stay true once
  the engine uses it. It is now two tests: one pins the exact three files that may
  mention it (`Permission.php`, `Role.php`, `QueueEligibility.php`), and one
  asserts that no route, page, navigation, provider or policy code mentions it and
  that no route has a queue URL. `NavigationTest` is untouched.
- **No slice 3 contact-attempt test needed changing.** The work order allowed the
  "no follow-up side effect" expectation to change; those tests use Leads with no
  cadence, which behave exactly as before, so they pass as they were.
- `tests/Pest.php` gained shared helpers for the queue tests only.
- **One file outside the work order's list was changed: `composer.json`, one
  line in the `test` script** (`Composer\Config::disableProcessTimeout`, the line
  the `ci:check` and `dev` scripts already have). Composer stops any script step
  after 300 seconds. With the real-concurrency tests the suite now takes about
  five minutes on the office machine, and `composer test` was stopped by that
  limit with every test passing. No package was added or changed and
  `composer.lock` is untouched. The GitHub jobs were not affected either way:
  they run `composer ci:check` and `php artisan test`.

## Known limitations

- **There is no screen until 4c.** Nobody can open a queue, press Next lead, skip
  or put a Lead back from the application. The engine is reachable only from code
  and tests.
- **New Leads start entering the cadence the moment 4b is deployed**, and a logged
  contact attempt on such a Lead starts moving its cadence. Leads captured before
  deployment never enter. With no screen, those cadence rows simply accumulate
  with their attempt 1 due until 4c gives staff a way to work them.
- **Staging timer: not known to be running** (below). Without it the queue is still
  correct; only the heartbeat and the tidy-up pass are missing.
- **On MySQL, two staff actions on the same hold in the same instant can end in
  a deadlock error for one of them** (not the claim, which retries). Nothing is
  half-saved; the action is pressed again. See "Decisions needed".
- A Lead that leaves the queue does not come back by itself (resume and reopen do
  not put it back).
- Queue history and the cadence columns on contact attempts are append-only in the
  application, not enforced by the database, like audit events.
- The MySQL result above is the builder's, on MySQL 8.4.11 on the office machine.
  The GitHub MySQL 8.4 job is the one that counts and has not run yet.
- The real-concurrency tests add roughly 70 to 80 seconds to each full run.
- On SQLite, as the application is configured, a claim that collides four times
  in a row is still answered "try again", as the plan says. In the repeat runs
  of fix round 1 that never happened with eight processes at once. SQLite is
  used only for tests and local development; staging is MySQL.

### Staging timer

**It could not be checked.** The builder works on the office machine (host name
`agent-office`), which is not the staging server, and was given no access to the
staging server or to Forge; it did not go looking for any.
What was looked at, read-only: the office machine's own scheduled tasks for user
`forge` (`crontab -l`: none) and its home folder (the staging site is not on it).
Neither says anything about staging. **So the timer is not known to be running on
staging.** Nothing was changed anywhere.

What Jacob needs to do, once, in Forge (from the plan):

1. Open the server "BackOffice" and the staging site
   (`bananabackoffice-xg8eqczb.on-forge.com`).
2. If the site has a "Laravel scheduler" switch, turn it on. That is the whole
   step.
3. Otherwise add a scheduled job: user `forge`, frequency "Every Minute", command

   ```
   php /home/forge/bananabackoffice-xg8eqczb.on-forge.com/artisan schedule:run
   ```

**Unverified, please check against what Forge shows:** the site folder
`/home/forge/bananabackoffice-xg8eqczb.on-forge.com` is Forge's usual location for a
site of that name and was not seen (if the site uses Forge's zero-downtime
deployments, the path ends in `/current/artisan`); and the labels "Laravel
scheduler", "Every Minute" and the server name "BackOffice" are taken from the
plan, not read from Forge.

How to tell it worked, after 4b is deployed: on the server,
`php artisan schedule:list` lists `backoffice:queue-tick` every minute, and the
`scheduler_heartbeats` table has one row, `queue_tick`, whose time is less than two
minutes old. (The on-screen warning arrives with 4c.)

## Decisions needed from Jacob

- **None that blocks this step.** One point for the Architect, with a
  recommendation:
  **Deadlocks between two changes to the same hold on MySQL.** Every history
  entry carries links to the Lead, the cadence, the Location, the person who
  acted and the person affected, and MySQL briefly locks each of those rows when
  the entry is written. The plan's lock order does not count those locks. The
  one pair the tests race (a claim against deactivating or revoking the
  claimer) is fixed in this round. Pairs that remain possible, all needing two
  staff actions on the same hold in the same few milliseconds: deactivating a
  person while a manager routes the Lead that person holds, or while another
  setter takes over that person's expired hold; deactivating a Location while a
  Lead there is being claimed. What happens then: MySQL stops one of the two
  with an error, nothing is half-saved, and the person presses the button
  again. A claim is retried by itself; the other actions are not.
  Options: (a) accept and record it as a known limit for the pilot
  (recommended: it is rare, loses nothing and the fix is not small);
  (b) in a later step, make the hooked actions safe to run twice and retry
  them on a deadlock, as the plan already says the claim does; (c) drop the
  database links from `lead_queue_events` to people, keeping the IDs, which
  removes most of these waits but weakens the database's own checking.
  Nothing was done about (b) or (c) here.
- **An action, not a decision:** enable the scheduler for the staging site (above).
  It can be done before or after 4b is merged.
- Still open from [../security-notes.md](../security-notes.md): the `vite-plus` pin
  (0.3.0 to 0.3.3). Not changed here.

## What Jacob can and cannot observe on staging after this step

There is nothing to click for 4b.

- **Can observe:** every existing screen works as before. Capturing a Lead, logging
  a contact attempt, disqualifying, scheduling a follow-up, routing, returning to
  central, revoking access and deactivating a person or Location all behave and
  look the same.
- **Cannot observe:** the cadence, due times, holds, skips, the queue history or
  the heartbeat. They are stored but shown nowhere. There is no Call Queue item in
  any menu, and typing a queue address finds no page.
- **The one new audit event**, `lead.returned_to_queue`, cannot be produced from
  any screen yet.
- If someone with server access wants proof the engine is alive: after a new Lead
  is captured on staging, `lead_cadences` gains one row for it and
  `lead_queue_events` one `entered` row; once the scheduler is on,
  `scheduler_heartbeats` shows `queue_tick` moving every minute.
