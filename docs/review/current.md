# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `office/11-slice-4b-scheduling-engine-and-atomic-du`
· **Pull request:** <https://github.com/jakerans/bananabackoffice/pull/5>

**State:** Job #11 (Build, step 4b) is built, reviewed and approved by both
reviewers after one fix round (1 of at most 3 used). The office's full local check
passed. The branch is pushed and both GitHub jobs passed. **It is not merged yet:**
the office merges into `main` after this report, only if every GitHub check passes
on the final commit. Staging deploys by itself after a merge; **no deployment has
been verified.** Production is not touched and waits for Jacob. The office records
Jacob's approval for this job: in the office, Oct 8, 6:18 pm.

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
    current rows, run the eligibility check, then one conditional `UPDATE` decides
    (a candidate the locked row already shows as taken, not due or out of the
    queue is passed over without it). Deadlocks and a busy database are retried
    three times, then answered "try again". No `SKIP LOCKED`, advisory lock or
    anything one database alone offers.
  - *Skip:* fixed enum `QueueSkipReason` (four values; Other needs a note). Releases
    the hold, leaves `due_at` alone and stores that setter's `cooldown_until` on the
    `skipped` event. Nothing counts skips.
  - *Put back in queue:* needs a note; restarts (new cycle) a finished cadence or
    one with no attempts left, otherwise resumes; the person, their grants and
    the Lead are read again inside the transaction and authorized as they are
    now; a conditional update so a double submit changes nothing; the change, its
    queue event and its audit event `lead.returned_to_queue` save together or not
    at all. New `LeadPolicy::returnToQueue`.
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
  changed.
- **No page, Livewire component, route, navigation item, card, summary or list.**
  No package added. No `LeadStatus` case, `lead_status_changes` row or
  `lifecycle_version` change from any cadence code. `assigned_user_id` is neither
  read nor changed by holding, skipping or logging.
- **Documents:** roadmap, slice-4-plan (status lines and the scheduler note),
  permission-model, navigation-map (wording only), security-notes (new "Call queue
  engine" entry), README. This file replaced.

### Fix round 1

The first review did not approve the work. Three things were fixed; both reviewers
confirmed each one by reading the code and tests.

1. **The SQLite concurrency proof now runs on the application's own settings.**
   The test processes used to change SQLite for the two crowd tests ("IMMEDIATE"
   transactions and a five-second wait). That is gone:
   `tests/Support/queue-process.php` changes no database setting. To make the
   eight-process test reliable that way, `ClaimNextLead` now passes over a
   candidate that the locked row already shows as taken, not due or out of the
   queue, without issuing an update that could not have won. The conditional
   update still decides every claim that can succeed. The test keeps every
   assertion, including that no process is told "try again".
2. **A logged attempt always ends a hold with `hold_ended`.** Exactly one event,
   reason `attempt_logged` (the holder logged it) or `attempt_logged_by_other`,
   also when the hold's time had already run out, as contract 4 says.
3. **Put back in queue reads the person again.** `ReturnLeadToQueue` re-reads the
   person's `users` row and access grants inside its transaction, in the claim's
   order (person, grants, then Lead). A stale copy of someone since deactivated,
   revoked, moved to another Location or removed is refused, with the cadence
   row, the queue history and the audit history unchanged.

**Also found and fixed in that round: a MySQL deadlock when a claimer is
deactivated.** Not one of the three review points; it failed a required test.
`DeactivateStaffMember` and `RevokeAccess` locked the held Lead's cadence row and
then wrote the history entry, which needs the Lead row, while a second setter's
claim held the Lead row and waited for the cadence row. `EndQueueHold::forStaffMember`
now locks the Lead row before the cadence row, the same order as the claim. No
behaviour changed.

## Tests and checks, with their real results

Recorded by the office:

| Check | Result |
| --- | --- |
| `composer test` (style, static analysis, every test), run by the office | **Passed** |
| Pushed to GitHub, pull request opened | **Yes**, [pull request 5](https://github.com/jakerans/bananabackoffice/pull/5) |
| GitHub: "Checks and tests (SQLite)" | **Passed** ([job](https://github.com/jakerans/bananabackoffice/actions/runs/37892066458/job/113694985993)) |
| GitHub: "Migrations and tests (MySQL 8.4)" | **Passed** ([job](https://github.com/jakerans/bananabackoffice/actions/runs/37892066458/job/113694985856)) |
| Code review (reviewer) | **Approved.** Read-only inspection; the reviewer ran no tests or migrations |
| Architect review | **Approved.** Read-only, from the diff, the tests and the handoff; ran nothing |
| Click-through testing in a browser | **Not run.** The office has no test browser or test environment for this project yet, and 4b has no screen to click |
| Merge into `main` | **Not yet** |
| Staging deployment | **Not yet. Not verified by the office** |
| Staging scheduler | **Could not be checked.** See "Staging timer" below |
| Production | **Not touched** |

Reported by the builder from its own runs on the office machine, as recorded by
the reviews. The office did not repeat these counts:

| Check | Builder's reported result |
| --- | --- |
| SQLite crowd tests (eight processes; four tabs) on the application's own settings, before the fix-round-1 change to the claim | 14 of 80 runs failed: one process told "try again"; no Lead given twice or lost |
| The same, on the final code | 200 of 200 runs passed |
| The "claimer is deactivated" race on a private MySQL 8.4.11 instance, after the deadlock fix | 25 of 25 passed. The GitHub MySQL 8.4 job above is the proof that counts |

The builder's earlier handoff also reported, from its own runs, that
`php artisan schedule:list` shows `backoffice:queue-tick` every minute and that
the migrations ran fresh, rolled back and re-applied on SQLite. **Neither was run
separately by the office**, so they are reported here as the builder's statements
only. What the office's record does show: the schedule entry has a test in
`tests/Feature/Queue/QueueTickTest.php`, which is part of the passing suite, and
the migrations ran on MySQL 8.4 in the passing GitHub job.

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
| Put back (including the stale-person cases) | `tests/Feature/Queue/ReturnLeadToQueueTest.php` |
| Tick (and the schedule entry) | `tests/Feature/Queue/QueueTickTest.php` |
| Outage | `tests/Feature/Queue/ClaimNextLeadTest.php` |
| Append-only; no cadence rows from the migrations; every foreign key indexed | `tests/Feature/Queue/QueueAppendOnlyTest.php` |

The concurrent processes are started by `tests/Support/QueueProcesses.php` running
`tests/Support/queue-process.php`. On MySQL they share the suite's own database,
commit their rows and remove them afterwards; on SQLite they share a temporary
file-backed database that is deleted afterwards. The script refuses to run outside
the testing environment and is not an application command or route.

## Architectural decisions made while building

None that needs a new entry in `decisions.md`. Implementation choices and one
Architect ruling:

- **Architect's ruling on the remaining MySQL deadlock pairs: accept for this
  step and record as a known limit; fix in a small follow-up in or beside 4c.**
  Details under "Known limitations" and "Open follow-ups". The database links
  (foreign keys) on the queue history stay.
- **"Lock my own row first" is done with a write that changes nothing** in
  `RouteLead`, `ReturnLeadToCentral`, `RevokeAccess`, `DeactivateLocation` and the
  settings save. Existing behaviour of those actions is otherwise unchanged.
- **`hold_expired` means only "time ran out and the claim, the tick or another
  change tidied it up".** A logged attempt is the exception (contract 4): always
  one `hold_ended`.
- **The tick does not end a hold because calling hours closed.** The plan gives the
  tick two repairs only (a Lead that left; access lost).
- **A skip is accepted outside calling hours** as long as the setter still holds
  the Lead and may still work it.
- **Next lead by someone who may not work the queue anywhere is refused as not
  authorized**, rather than answered "nothing due".
- **A candidate the locked row shows as taken is passed over without the update**
  (fix round 1). The update is still the only thing that gives a Lead.
- **`EndQueueHold::forStaffMember` locks the Lead row before the cadence row.** The
  unlocked first read is only a hint; the locking read still decides.

## Deviations from the plan

- None in behaviour.
- **One existing test updated, not weakened:**
  `tests/Feature/Settings/ContactCenterSettingsTest.php`. The 4a test "nothing but
  the enum and the role lists reads the queue permission" could not stay true once
  the engine uses it. It is now two tests: one pins the exact three files that may
  mention it (`Permission.php`, `Role.php`, `QueueEligibility.php`), and one
  asserts that no route, page, navigation, provider or policy code mentions it and
  that no route has a queue URL. `NavigationTest` is untouched.
- **No slice 3 contact-attempt test needed changing.** Those tests use Leads with
  no cadence, which behave exactly as before.
- `tests/Pest.php` gained shared helpers for the queue tests only.
- **One file outside the work order's list was changed: `composer.json`, one
  line in the `test` script** (`Composer\Config::disableProcessTimeout`). Composer
  stops a script step after 300 seconds and the suite now takes about five
  minutes. No package was added or changed and the lock file is untouched.

## Known limitations

- **There is no screen until 4c.** Nobody can open a queue, press Next lead, skip
  or put a Lead back from the application.
- **New Leads start entering the cadence the moment 4b is deployed**, and a logged
  contact attempt on such a Lead moves its cadence. Leads captured before
  deployment never enter. Until 4c, those cadence rows accumulate with attempt 1
  due; when 4c ships, the never-called-newest-first order will surface that
  backlog in that order.
- **Staging timer: not known to be running** (below). Without it the queue is still
  correct; only the heartbeat and the tidy-up pass are missing.
- **On MySQL, two staff actions on the same hold within the same few milliseconds
  can end in a deadlock error for one of them.** The pairs: deactivating a person
  while a manager routes the Lead that person holds, or while another setter takes
  over that person's expired hold; deactivating a Location while a Lead there is
  being claimed. MySQL stops one of the two, nothing is half-saved, and the person
  presses again. The claim retries by itself; the hooked actions do not yet. The
  tick logs it and tries again the next minute.
- **A grant whose role is changed in place has no hook**, because the plan lists
  only `RevokeAccess`. A hold kept that way is ended by the tick's access-lost
  repair or the holder's next Next lead.
- A Lead that leaves the queue does not come back by itself (resume and reopen do
  not put it back).
- Queue history and the cadence columns on contact attempts are append-only in the
  application, not enforced by the database, like audit events.
- On SQLite, a claim that collides four times in a row is still answered "try
  again", as the plan says. SQLite is used only for tests and local development;
  staging is MySQL.
- The suite now takes about five minutes; the real-concurrency tests are a large
  part of that.

### Staging timer

**It could not be checked.** The builder worked on the office machine, which is not
the staging server, and had no access to the staging server or to Forge. **The
timer is not known to be running on staging.** Nothing was changed anywhere.

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
site of that name and was not seen (if the site uses zero-downtime deployments,
the path ends in `/current/artisan`); and the labels "Laravel scheduler", "Every
Minute" and the server name "BackOffice" are taken from the plan, not read from
Forge.

How to tell it worked, after 4b is deployed: on the server,
`php artisan schedule:list` lists `backoffice:queue-tick` every minute, and the
`scheduler_heartbeats` table has one row, `queue_tick`, whose time is less than two
minutes old. (The on-screen warning arrives with 4c.)

## Decisions needed from Jacob

- **None that blocks this step.**
- **For information, already ruled by the Architect:** the rare MySQL deadlock
  between two staff actions on the same hold is accepted as a known limit for this
  step, with the fix scheduled in or beside 4c. Jacob may overrule this and ask
  for the fix before 4c.
- **An action, not a decision:** enable the scheduler for the staging site (above).
  It can be done before or after 4b is merged.
- Still open from [../security-notes.md](../security-notes.md): the `vite-plus` pin
  (0.3.0 to 0.3.3). Not changed here.

## Open follow-ups for the next step

Review notes that did not block. None is done yet.

1. **Deadlock follow-up, in or beside 4c:** take the same Lead-before-cadence lock
   order in `EndQueueHold::atLocation` and `RunQueueTick::releaseExpiredHold`, and
   add a deadlock retry around the hooked actions. Do not drop the foreign keys.
2. **Plan wording, when [../slice-4-plan.md](../slice-4-plan.md) is next touched:**
   - the sentence "if the database reports a deadlock anyway, the transaction is
     retried" should say it applies to the claim only until follow-up 1 is done;
   - contract 5, step 3 should gain a sentence about the pre-check that passes
     over a candidate the locked row already shows as taken.
3. **4c's "told at once" card** must treat `hold_expired` as "time ran out" only;
   a logged attempt always arrives as `hold_ended`.
4. **Confirm three choices when 4c puts a screen on them:** the tick does not end a
   hold because calling hours closed; a skip is accepted outside calling hours;
   Next lead by someone with no queue access anywhere is an authorization error,
   not "nothing due".
5. **Role changed in place:** if an edit-grant action exists or is added, give it
   the same hook as `RevokeAccess`.
6. **Staging scheduler:** still unknown until Jacob enables or confirms it. 4c must
   repeat the check, and the Forge path and labels above stay unverified until
   someone reads them from Forge.
7. **Suite duration:** about five minutes, and `composer.json` carries the one-line
   time-limit change. Watch this as 4c adds tests.
8. **Backlog on arrival of 4c:** cadence rows for Leads captured between the 4b and
   4c deployments will all be due at once.

Raised in the first review and closed by fix round 1, listed so nobody reopens
them: the SQLite crowd tests no longer run with special database settings; a late
attempt on a lapsed hold now writes `hold_ended`, not `hold_expired`; the office's
`composer test` result is now filled in above.

## What Jacob can and cannot observe on staging after this step

There is nothing to click for 4b, so there are no browser-review steps.

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
