# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `office/11-slice-4b-scheduling-engine-and-atomic-du`
**State:** Job #11 (Build, step 4b) is built and committed on its branch, awaiting
review. It is **not pushed, has no pull request, and is not merged.** The two
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
    current rows, run the eligibility check, then one conditional `UPDATE` decides.
    Deadlocks and a busy database are retried three times, then answered "try
    again". No `SKIP LOCKED`, advisory lock or anything one database alone offers.
  - *Skip:* fixed enum `QueueSkipReason` (four values; Other needs a note). Releases
    the hold, leaves `due_at` alone and stores that setter's `cooldown_until` on the
    `skipped` event. Nothing counts skips.
  - *Put back in queue:* needs a note; restarts (new cycle) a finished cadence or
    one with no attempts left, otherwise resumes; a conditional update so a double
    submit changes nothing; the change, its queue event and its audit event
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
| `composer test` (style, static analysis, every test), run by the builder on SQLite, on the final code | **Passed.** Pint passed, PHPStan 0 errors, 1,927 tests passed (9,287 assertions), 5 minutes 23 seconds. An earlier run the same day was **stopped by Composer's 300-second limit** with style and static analysis passed and the tests still running; the same tests run directly (`php artisan test`) passed, 1,927. See "Deviations" for the one-line fix |
| Whole suite on MySQL, run by the builder, on the final code | **Passed**, 1,927 tests (9,287 assertions), on a private throwaway MySQL 8.4.11 started under `/tmp` on the office machine for this purpose (not staging, not the GitHub database). This is not the GitHub job |
| Migrations on SQLite: fresh, roll back the four 4b migrations, re-apply | **Passed**, on a temporary SQLite file. `lead_cadences` empty afterwards |
| Migrations on MySQL 8.4.11 (same private instance): apply to an empty database, roll back the four, re-apply | **Passed** |
| `php artisan schedule:list` | **Shows** `* * * * *  php artisan backoffice:queue-tick` |
| Real-concurrency tests repeated on their own, before the fix below | **SQLite: 2 of 12 runs failed**, both times the eight-process test, both times because one process was told "try again"; no Lead was given twice or lost. MySQL: 4 of 4 passed |
| Real-concurrency tests repeated on their own, on the final code | **SQLite: 8 of 8 runs of the whole file passed, and the two crowd tests alone passed 30 of 30. MySQL 8.4.11: 4 of 4 runs of the whole file passed.** No deadlock, "database is locked" or "try again" in any of them |
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
- **A hold whose time had already run out is recorded as `hold_expired`, whoever
  tidies it up** (the claim, the tick, or an action that would otherwise have ended
  it). So a logged attempt writes exactly one `hold_ended` (`attempt_logged` or
  `attempt_logged_by_other`) when the hold was still running, and one
  `hold_expired` when it had already lapsed. One event either way, always naming
  the holder.
- **The tick does not end a hold because calling hours closed.** The plan gives the
  tick two repairs only (a Lead that left; access lost). A hold outside calling
  hours ends at its own time or on the setter's next Next lead.
- **A skip is accepted outside calling hours** as long as the setter still holds
  the Lead and may still work it: giving a Lead back is never refused for the hour.
- **Next lead by someone who may not work the queue anywhere is refused as not
  authorized**, rather than answered "nothing due".
- **SQLite settings of the concurrency test processes.** The application's own
  SQLite settings are unchanged. The test processes give their temporary database
  a five-second wait. In the two tests that start a crowd (eight setters; one
  setter in four tabs) the processes also take SQLite's write lock as each
  transaction begins, so they wait their turn. Why: with the application's
  default, SQLite refuses at once a transaction whose earlier read has gone
  stale, the claim retries three times as the plan says, and eight processes on
  one file sometimes used all three up, so one was told "try again" and the test
  failed (seen in 2 of 12 repeat runs before this was set). The five
  change-during-a-claim tests keep the application's default, so real processes
  still meet that refusal and the retry. No assertion was changed and MySQL is
  the same in every test. This matters only to the SQLite test run: staging and
  production are MySQL. See "Known limitations".

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
- A Lead that leaves the queue does not come back by itself (resume and reopen do
  not put it back).
- Queue history and the cadence columns on contact attempts are append-only in the
  application, not enforced by the database, like audit events.
- The MySQL result above is the builder's, on MySQL 8.4.11 on the office machine.
  The GitHub MySQL 8.4 job is the one that counts and has not run yet.
- The real-concurrency tests add roughly 80 seconds to each full run.
- **On SQLite, eight people pressing Next lead in the same instant can
  occasionally get "try again" from the application as it is configured**, after
  its three retries (see "SQLite settings of the concurrency test processes").
  No Lead was ever given twice or lost when that happened. It does not arise on
  MySQL, which staging uses, and SQLite is used only for tests and local
  development. Nothing was changed in the application for it.

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

- **None for the code.** Nothing needed a product or architecture decision the
  documents do not hold.
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
