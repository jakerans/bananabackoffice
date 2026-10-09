# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `office/12-slice-4c-contact-center-queue-screen-and`
· **Pull request:** [#6](https://github.com/jakerans/bananabackoffice/pull/6)

**State:** Job #12 (Build, step 4c: the Call Queue screen and manager tools) is
built, reviewed twice (one fix round of at most three) and approved by both
reviewers. Jacob approved it in the office on Oct 8, 6:18 pm. The branch is pushed
and both GitHub jobs passed. It is **not merged yet**: the office merges into
`main` only if every GitHub check passes on the final commit. Staging deploys by
itself after a merge; **no deployment has been verified.** Production is not
touched. **No one has looked at this screen in a browser yet.**

## Step

Slice 4, step 4c. Plan: [../slice-4-plan.md](../slice-4-plan.md), "Step 4c" and
"Architect's readings for the 4c build (job #12)". Decisions 61 and 65 to 74 in
[../decisions.md](../decisions.md).

Objective: let setters work due Leads one at a time from a Call Queue page, with
no two setters ever getting the same Lead, and let managers see the queue's health
and restart finished Leads.

## What changed

- **The page.** `/marketing/contact-center/queue`, route and ID
  `marketing.call_center.queue`, live, gated by `leads.work_queue` (on the route,
  again when the page opens, and again in every action). It is a third-row item
  after Leads in Marketing > Contact Center only; Contact Center's primary is still
  `shared.leads`. Sales has no queue item.
  `resources/views/pages/marketing/⚡call-queue.blade.php`.
- **The setter's view.** A "due now" count and **Next lead** (`ClaimNextLead`). One
  card for the held Lead: name, phone, Location, "Attempt N of M", what was asked
  for and what they wrote, earlier attempts the person may see (up to five), the
  time left on the hold (counted down in the browser), and a link to the full
  Lead. **Log attempt**, **Skip** (reason required, Other needs a note, through
  `SkipHeldLead`) and **Disqualify** (`changeStatus`, the existing reasons,
  duplicate rule and lifecycle-version check, through `DisqualifyLead`). Never a
  list of due Leads. When nothing is due it says so, and when every Location the
  person may work is closed it says when calling hours open.
- **Queue times.** New `components/queue-time.blade.php`: the browser formats the
  device-timezone time with `Intl.DateTimeFormat` from a server-rendered ISO
  timestamp; the Location's local time is rendered by the server beside it. The
  device timezone is never sent to or read by the server. `local-time` is unchanged.
- **One shared attempt form.** `app/Livewire/Concerns/RecordsContactAttempts.php`
  (fields, rules, messages, defaults, the call to `RecordManualContactAttempt`) and
  `resources/views/partials/contact-attempt-form.blade.php`. The Lead page and the
  card both use them; the old copy in the Lead page is gone. On the card, channel
  and direction are fixed to an outbound phone call and forced on the server.
- **Told at once.** A 10-second Livewire poll that only re-checks the permission
  and re-reads. When the hold is gone the card is replaced by the reason (from the
  queue history for that hold) and Next lead. `hold_expired` only ever means "time
  ran out".
- **Manager part** (people who may assign Leads, only their Locations plus unrouted
  where the company-wide rule allows): due now, longest wait, Leads held and by
  whom, the "Cadence finished, no contact" count and list (each Lead linked, Put
  back in queue with a required note via `ReturnLeadToQueue`), and a warning when
  the `queue_tick` heartbeat is over 10 minutes old or missing. Numbers come from
  the new read-only `app/Support/Queue/DueQueue.php`.
- **Lead page.** Cadence panel (state, attempts made of total, due time, who holds
  it), the queue history, and Put back in queue, shown only where the person may
  assign there (new `LeadPolicy::manageQueue`; Put back uses `returnToQueue`).
- **Shared pieces touched.** `ClaimNextLead`: behaviour-neutral extraction of the
  candidate filter and cooldown subquery into `DueQueue`, so the count and the
  claim read the same query. `components/confirmation-modal.blade.php` gained a
  slot for extra fields (existing uses unaffected). `QueueEventType` and
  `QueueHoldEndReason` gained wording methods.
- **Documents.** Roadmap, slice 4 plan (status lines, and the plan-wording
  correction carried from the 4b handoff), navigation map, permission model,
  README and security notes were updated in the Architect's commit.
- **No** new table, migration, permission, role grant, audit event, `LeadStatus`
  case or package.

### Fix round 1

The first review asked for five fixes; both reviewers confirmed all five.

- The device-time formatter threw an error in the browser (it mixed two kinds of
  `Intl.DateTimeFormat` options). It now uses one kind, and a test runs the exact
  options in Node for an Eastern and a Pacific time.
- The card and the gone-hold notice now check the Location's calling hours on each
  poll (read only, hold untouched): "Calling hours have closed for this lead."
- The shared attempt dialog's Cancel button now takes focus when it opens.
- Disqualify from the card gained tests for a successful save, the Duplicate and
  note rules, and refusal when the person may not change the status.
- The count test now restores the seeded pool between people and asserts fixed
  numbers: Lakeview agent 2, Harbor manager 2, company-wide agent 5 and Owner 5
  (the last two include the unrouted Lead).

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` (Pint, PHPStan, full Pest suite), run by the office on the final commit | **Passed.** The office's record gives the result only; it does not give the test count or the duration. |
| Suite size and duration | **Not recorded for the final commit.** Before fix round 1 the builder reported 1968 tests, 9514 assertions, 36 of them in the new `QueueScreenTest.php`, in 299 s. Fix round 1 added tests, so those figures are out of date and the final ones are not known. |
| GitHub "Checks and tests (SQLite)" | **Passed.** [Job](https://github.com/jakerans/bananabackoffice/actions/runs/37931284551/job/113822354329) |
| GitHub "Migrations and tests (MySQL 8.4)" | **Passed.** [Job](https://github.com/jakerans/bananabackoffice/actions/runs/37931284551/job/113822354475) |
| Device-time formatter in Node, Eastern and Pacific | Ran successfully for the re-reviewer. |
| Reviews | Both reviewers approved after fix round 1. Both re-reviews were read-only and reran nothing. |
| Rendered browser review | **Not run.** No browser was available to the builder or the reviewers. |
| Click-through testing by the office | **Not run.** The office has no test browser or test environment for this project yet. |
| Staging scheduler | **Could not be checked.** See below. |
| Merge | Not merged yet. Recorded by the office. |
| Staging deployment | Not verified. Recorded by the office. |
| Production | Not touched. |

Mapping of the plan's "Step 4c" > "Tests" list. Unless another file is named, the
test is in `tests/Feature/Queue/QueueScreenTest.php`.

| Plan item | Test |
| --- | --- |
| Route and page: refused to Sales Consultant, Production Manager, Finance, no grants and a guest, by URL and by direct Livewire call; open to the four roles | "the page opens for the four queue roles", "…refused by URL and by direct Livewire call…", "a guest…", "actions on the page are refused again when the access is gone" |
| Navigation: live for four roles, hidden otherwise; Contact Center still opens on Leads; IDs unique; no Sales item | "the queue item is live…", "the menu shows Call Queue…"; `tests/Feature/Support/Navigation/NavigationTest.php` (queue permission used by exactly the Call Queue item; existing ID-uniqueness test); `tests/Feature/Leads/LeadNavigationTest.php` unchanged |
| Count matches what Next lead would offer per role and scope; excludes outside hours, held by others, just skipped | "the count equals what next lead would offer, per role and scope", "the count leaves out…" |
| Next lead shows one Lead; no list of due Leads; a second session cannot get the same Lead | "next lead shows one lead and the page never lists the due leads", "a second session cannot obtain the same lead" (the engine's real-process race is still `QueueConcurrencyTest`) |
| Logging from the card saves one attempt through the shared action, ends the hold, schedules the next; Lead page form unchanged | "logging from the card…", "an attempt form left open past the hold still saves…"; slice 3 `LeadContactAttemptTest.php` unchanged |
| Skip and Disqualify from the card with reason rules and permissions; a stale card (hold expired, Lead moved, access revoked) saves nothing and shows the reason | "skip needs a reason, and Other needs a note", "disqualify from the card follows the existing reasons and permission", "a valid reason disqualifies the lead from the card…", "the Duplicate reason takes an original… and a reason that needs a note is refused without one", "openDisqualify and disqualify are refused for a holder who may not change the status", "a stale card saves nothing…", "a hold that ran out is explained as time running out…", "when calling hours close the poll replaces the card…" |
| Summary numbers; Location Manager sees only their Locations; warning at 11 minutes and when missing, not at 9 | "the manager summary shows each number…", "a location manager sees only their locations numbers", "the timer warning shows at 11 minutes…" (11, never ran, 9) |
| Times: Location-local time and ISO timestamp for Eastern and Pacific; nothing reads a device timezone | "the card shows the location time and the device time source for Eastern and Pacific locations", "nothing on the server reads a device timezone", and the Node formatter test added in fix round 1 |
| Put back from both places with the dialog rules; marker and history hidden from people who may not assign | "put back from the page needs a note…", "put back is refused to a setter…", "the lead page shows the cadence panel, history and put back only where the person may assign" |
| Native-dialog regression | the existing `ApplicationConfirmationDialogsTest.php` scan (covers the new page and partial) plus "the queue page, the lead page and the shared form use the shared confirmation dialog…" |
| Polling only reads; a gone hold shows the reason only | "the page polls every ten seconds and polling never claims, extends or releases a hold", "when someone disqualifies the lead the card is replaced by the reason only" |

Existing tests changed (none weakened or deleted):
`tests/Feature/Settings/ContactCenterSettingsTest.php`: the two tests that pinned
which files mention the queue permission and asserted that no queue route exists
now pin the new truth (exactly one route and one navigation item). A third test in
that block, "holding the queue permission opens no extra page", also asserted that
no navigation item used it, so it became "opens no settings page".
`NavigationTest.php`: "the queue permission is used by no navigation item" now
expects exactly the Call Queue item.

### Staging scheduler

**Could not be checked.** Neither the builder nor the reviewers can see the staging
server or Forge. **The scheduler is not known to be running on staging.** Nothing
was changed anywhere. **The on-screen warning is now how Jacob can tell:** open the
Call Queue as a manager; if the timer warning is showing two minutes after a
deployment, the scheduler is not running.

Jacob's Forge step from the 4b handoff is unchanged, and its path and labels remain
**unverified** (not read from Forge):

1. Open the server "BackOffice" and the staging site (`bananabackoffice-xg8eqczb.on-forge.com`).
2. If the site has a "Laravel scheduler" switch, turn it on. That is the whole step.
3. Otherwise add a scheduled job: user `forge`, frequency "Every Minute", command
   `php /home/forge/bananabackoffice-xg8eqczb.on-forge.com/artisan schedule:run`.

Unverified: the site folder (with zero-downtime deployments it ends in
`/current/artisan`), and the labels "Laravel scheduler", "Every Minute" and server
name "BackOffice".

## Implementation choices (flagged for Jacob to confirm or change)

The Architect's readings in the plan, as built:

- **Opening the page** follows Leads (route, navigation and every action check
  `leads.work_queue` in the current Location view). Once open, the pool, count and
  summary cover every Location the person has that access at.
- **The setter's count** uses the same candidate query as the claim, plus the
  person's skip cooldown. The Lead held is not in it.
- **The manager part** is shown to anyone who may assign Leads somewhere, for
  exactly those Locations (plus unrouted when the company-wide rule allows).
  "Due now" there means in the queue, due, not held and inside its Location's
  calling hours now.
- **A hold that is gone** is explained from the queue history for that hold; no
  history means "time ran out". Access lost while the hold still stands is shown as
  "Your access to this lead changed."
- **A call is never thrown away:** an open attempt form survives the card going
  away and saves if the person may still work the Lead; if not, nothing is saved
  and the reason is shown.
- **Polling only reads;** the countdown runs in the browser.
- **The form's time field is unchanged** (company time).
- **The timer does not end a hold because hours closed;** the poll hides the card
  and the next Next lead ends the hold with the right reason.
- **Next lead with no queue access anywhere** is refused as not authorized.
- **A busy database on the page's own saves** shows a "try again" message and
  saves nothing.

**One reading the screen no longer matches.** The plan's reading says a skip is
accepted outside calling hours while the person holds the Lead. Since fix round 1
the card is hidden when calling hours close, so a setter can no longer start a
skip after hours (the action is refused). The engine itself still accepts such a
skip, and Next lead ends the hold with the right reason, so nothing is lost. See
"Decisions needed", item 2.

The builder's choices beyond the plan:

- **Server-held locked state, not browser IDs.** The cadence ID, hold token and the
  Lead a dialog was opened for are set by the server and re-resolved on every
  action.
- **A stale hold at Skip** closes the dialog and shows the reason; the engine's own
  "no longer holding" check is still what decides.
- **Disqualify on the card** has the reason list, duplicate choice and note, but
  not the Lead page's "Schedule follow-up instead" shortcut. The full Lead is one
  link away.
- **`LeadPolicy::manageQueue`** (may view, and hands out Leads there) is a new
  policy method gating the Lead page's cadence panel and history. It adds no
  permission.
- **The held list and the cadence-finished list show up to 25 Leads;** the counts
  are exact. Nothing on screen says the list was cut short.

## Deviations from the plan

- **`phpstan.neon` changed** (not in the work order's list): one ignore entry for
  the "trait is used zero times" notice on `RecordsContactAttempts`, because its
  only users are Livewire single-file pages, which PHPStan does not analyse. No
  other analysis rule changed.
- **`components/confirmation-modal.blade.php` gained a slot** for extra fields.
- **`NavigationTest.php` and one extra test in `ContactCenterSettingsTest.php`
  changed** as described above, because both asserted the old "no navigation item"
  state.
- **`LeadPolicy` gained `manageQueue`;** `QueueEventType` and `QueueHoldEndReason`
  gained wording methods.
- **`LeadNavigationTest`, `ApplicationConfirmationDialogsTest` and
  `LeadContactAttemptTest` were not added to;** the new coverage is in
  `QueueScreenTest.php`, and the existing scan test already covers the new files.
- **Skip cannot be started after calling hours close** (above).
- Nothing in engine behaviour changed.

## Known limitations

- **Backlog of Leads due at once.** Leads captured since 4b are already due. When
  4c is deployed the first setters will see a large "due now" count, and each Lead
  is handed out one at a time, newest never-called first.
- **The deadlock follow-up is still open** (4b follow-up 1). On MySQL, two actions
  on the same hold within milliseconds can end in a deadlock error for one of
  them; nothing is half-saved. The page answers a busy database on its own saves
  with "try again".
- **The attempt form's time field is in company time,** not the device or Location
  timezone (decision 68), also on the card.
- **No automatic return to the queue** after Deferred Follow-up, reopening or a
  finished cadence: a manager uses Put back in queue.
- **Every queue role may assign Leads,** so the Call Center Agent also sees the
  manager part for their Locations (see "Decisions needed", item 1).
- **Lists stop at 25** with no notice, so a list can be shorter than its count.
- The page refreshes on a 10-second poll, so a change can take up to 10 seconds to
  show; a save always re-checks on the server.
- Rendering was exercised only through Livewire and HTTP tests, never in a
  browser.

## Decisions needed from Jacob

1. **Should a Call Center Agent see the manager part?** The plan says "people who
   may assign Leads". The existing Call Center Agent role has the assign
   permission, so agents see the summary, the timer warning and the
   cadence-finished list, and can Put back, at their own Locations. Keeping it
   needs nothing. Hiding it is a product decision about the role or a new rule for
   the screen, not a small code tweak. Recommendation: decide after seeing it on
   staging (step 9 below).
2. **Skip after calling hours close.** As built, the card disappears when hours
   close, so the setter cannot record a skip reason for that Lead; the hold simply
   ends. The alternative is to keep Skip available on the "hours have closed"
   notice. Recommendation: keep it as built; nothing is lost and it is simpler.
3. Confirm or change the other readings and choices listed above.
4. Whether to approve the deadlock follow-up as its own job.
5. Whether staging's scheduler is on (Forge step above).

## Open follow-ups

None of these blocked approval. Both reviewers listed them for a later step.

Behaviour on the screen:

1. **Skip when access was lost.** If the setter loses access to the Lead while the
   Skip dialog is open, saving shows a "forbidden" error page instead of closing
   the dialog and showing "Your access to this lead changed." Nothing is saved.
2. **A status shown to someone who lost access.** One path
   (`closeBecauseLeadIsGone`) says "This lead was disqualified" to a person who may
   no longer work the Lead. Prefer the access-changed notice there, or take the
   reason from the queue history as the gone-hold notice does.
3. **A second save after a success** could replace the success notice with "Your
   access to this lead changed." Unlikely, because Livewire handles one request at
   a time; returning early when there is no Lead in hand would remove it.
4. **Two phone numbers.** The card shows the Lead's inquiry phone, while the
   attempt form pre-fills the linked contact's phone when there is one. They can
   differ; show the same number.
5. **Lists stop at 25 with no notice.** Add pagination or a visible "showing the
   first 25" line to the held and cadence-finished lists.
6. **`openPutBack` looks the Lead up before it authorizes,** so it answers "not
   found" or "forbidden" depending on whether the ID exists. Minor; it is
   authorized again on open and on confirm.

Tests:

7. **Stale-card tests move the Lead directly** (`forceFill`), not through
   `RouteLead` or `RevokeAccess`. Add cases that use the real actions, including a
   setter who keeps queue access at another Location.
8. **The device-time formatter test skips itself when Node is not installed.** If
   a machine lacks Node, the only proof that the formatter runs disappears
   silently. Consider failing instead of skipping in CI.
9. **The `changeStatus` refusal test** gets its refusal by disqualifying the Lead
   under the open card, not by using a holder whose role lacks the permission. A
   role-based case would be stronger proof.
10. **The count test** takes the company-wide agent's and the Owner's expected
    numbers from the helper inside its loop and asserts the explicit 5 only after
    it. Put 5 in the table.

Carried forward:

11. The deadlock follow-up (4b follow-up 1).
12. Role changed in place: if an edit-grant action exists or is added, give it the
    same hook as `RevokeAccess`.
13. Suite duration was about five minutes before fix round 1; keep watching it,
    and record the final count and duration with the next step.
14. Browser review, the two-person test and the staging scheduler check are all
    still to be done (steps below).

Closed in this job: the shared attempt form's Cancel focus, the missing
successful-disqualify coverage, and the plan-wording follow-up from the 4b handoff
(corrected in the Architect's commit).

## What Jacob can and cannot observe on staging after this step

After the merge and the automatic deployment, staging has a Call Queue item for
the four roles. Nothing is sent: no call, text or email. Whether the timer runs is
visible as the manager warning. Production is untouched.

## Browser review for Jacob on staging (plain English)

Nobody could run a browser, so none of this has been looked at on a real screen.
Use fictitious Leads only.

1. **Each role's menu.** Sign in as, in turn, an Owner, a Leadership user, a
   Location Manager and a Call Center Agent: Marketing > Contact Center shows
   **Leads** and **Call Queue**. Then a Sales Consultant, a Production Manager and
   a Finance user: no Call Queue anywhere, and typing
   `/marketing/contact-center/queue` gives "forbidden". Clicking Contact Center
   itself still opens Leads.
2. **Next lead.** As a Call Center Agent open Call Queue. You see "N leads due now"
   and a **Next lead** button, and no list of Leads. Press **Next lead**. One card
   appears.
3. **The card and its times.** Check it shows the name, a phone number, the
   Location, "Attempt 1 of …", what was asked for, "Earlier attempts", a countdown
   "Time left on your hold", and "Open the full lead". Each time shows your own
   device time and, beside it, "Location time …" in the Location's zone. Check a
   Lead at an Eastern Location and one at a Pacific Location: the two times should
   differ by the right number of hours (if your device is in Eastern, only the
   Pacific Location should look different). If a time is blank or the page shows
   an error here, tell the office; this is the part fixed in round 1.
4. **Log attempt.** Press **Log attempt**. The dialog says outbound phone call and
   has no channel or direction choice, and the cursor starts on Cancel. Choose "No
   answer", enter a duration, and save. The card is replaced by "Your call was
   logged." and **Next lead**. Open the Lead: one new attempt, and the queue
   history shows the next attempt scheduled.
5. **Skip.** Press Next lead, then **Skip**. Try saving with no reason (it should
   refuse), pick "Other" with no note (it should refuse), then give a note and
   save. In any dialog: focus starts on Cancel, Escape closes it, and focus goes
   back to the button you pressed.
6. **Disqualify.** On a card press **Disqualify**, choose a reason (try Duplicate
   and the "original" choice), and save. The card is replaced by a message and
   Next lead.
7. **The gone-hold message.** Press Next lead and leave the card open. In another
   browser, as a manager, either disqualify that Lead, or move it to another
   Location, or wait out the hold (the Contact Center settings show its length).
   Within about 10 seconds the card should be replaced by a short reason (for
   example "This lead was disqualified…") and show nothing about the Lead. Also
   open **Log attempt**, let the hold run out, and save: the call should still be
   saved.
8. **Nothing due, and closing time.** When no Lead is due the page says "Nothing
   is due for you right now." Outside calling hours it also says when they open.
   If you hold a card as calling hours close, within about 10 seconds it is
   replaced by "Calling hours have closed for this lead."
9. **Manager summary and timer warning.** As a Location Manager or Owner open Call
   Queue: you see Due now, Longest wait, Leads held now, and Cadence finished, no
   contact, and "held by <name>" while a setter holds a Lead. A Location Manager
   sees only their own Locations' numbers. If the queue timer has not run in the
   last 10 minutes, or never, a yellow "The queue timer has not run lately"
   warning shows. **This is how you tell whether the scheduler is on:** two
   minutes after a deployment there should be no warning. If there is, do the
   Forge step above. Then open the page as a Call Center Agent and note that the
   same manager part shows; that is decision 1.
10. **Cadence-finished list.** In the "Cadence finished, no contact" list each Lead
    is a link. Press **Put back in queue**: the dialog needs a note (try saving
    empty), then save. The Lead leaves the list and becomes due at the next
    opening of calling hours.
11. **Put back from the Lead page.** Open a Lead whose cadence finished (or ended):
    a "Call queue" panel shows its state, the same marker, "Queue history" (skips
    with their reasons, returns with their notes) and **Put back in queue**. Check
    that a Sales Consultant who can open the Lead does not see this panel.
12. **Two-person test.** Have two setters sign in on two browsers (different
    people, same Location, at least ten Leads due). Both press **Next lead** at the
    same moment, then both press Skip (or log a call), and repeat many times. They
    must never be shown the same Lead at the same time. If they ever are, stop and
    tell the office.
