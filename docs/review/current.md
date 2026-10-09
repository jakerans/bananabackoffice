# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `office/12-slice-4c-contact-center-queue-screen-and`
· **Pull request:** recorded by the office

**State:** Job #12 (Build, step 4c: the Call Queue screen and manager tools) is
built and committed on its branch. It is **not pushed, not reviewed and not merged.**
The office does the push, the pull request, the GitHub checks and the merge. No
deployment has happened or been verified. Production is not touched.

## Step

Slice 4, step 4c. Plan: [../slice-4-plan.md](../slice-4-plan.md), "Step 4c" and
"Architect's readings for the 4c build (job #12)". Decisions 61 and 65 to 74.

## What changed

- **The page.** `/marketing/contact-center/queue`, route and ID
  `marketing.call_center.queue`, live, gated by `leads.work_queue` (route
  `->can(...)` and again in `mount` and in every action). It is a third-row item
  after Leads in Marketing > Contact Center only; Contact Center's primary is still
  `shared.leads`. Sales has no queue item.
  `resources/views/pages/marketing/⚡call-queue.blade.php`.
- **The setter's view.** A "due now" count and **Next lead** (`ClaimNextLead`). One
  card for the held Lead: name, phone, Location, "Attempt N of M", what was asked
  for and what they wrote, earlier attempts the person may see (up to five), the
  time left on the hold (counted down in the browser from `held_until`), and a link
  to the full Lead. **Log attempt**, **Skip** (reason required, Other needs a note,
  through `SkipHeldLead`) and **Disqualify** (`changeStatus`, the existing reasons,
  duplicate rule and lifecycle-version check, through `DisqualifyLead`). Never a
  list of due Leads. When nothing is due it says so, and when every Location the
  person may work is closed it says when calling hours open.
- **Queue times.** New `components/queue-time.blade.php`: the browser formats the
  device-timezone time with `Intl.DateTimeFormat` from the server-rendered ISO
  timestamp; the Location's local time is rendered by the server beside it. The
  device timezone is never sent to or read by the server. `local-time` is unchanged.
- **One shared attempt form.** `app/Livewire/Concerns/RecordsContactAttempts.php`
  (fields, rules, messages, defaults, the call to `RecordManualContactAttempt`) and
  `resources/views/partials/contact-attempt-form.blade.php`. The Lead page and the
  card both use them; the old copy in the Lead page is gone. On the card, channel
  and direction are fixed to an outbound phone call and forced on the server.
- **Told at once.** `wire:poll.10s="refreshQueue"`. It only re-checks the permission
  and re-reads. When the hold is gone the card is replaced by the reason (from the
  queue history for that `hold_token`) and Next lead. `hold_expired` only ever
  means "time ran out".
- **Manager part** (people who may assign Leads, only their Locations plus unrouted
  where the company-wide rule allows): due now, longest wait, Leads held and by
  whom, "Cadence finished, no contact" count and list (each Lead linked, Put back in
  queue with a required note via `ReturnLeadToQueue`), and a warning when the
  `queue_tick` heartbeat is over 10 minutes old or missing. Numbers come from the
  new read-only `app/Support/Queue/DueQueue.php`.
- **Lead page.** Cadence panel (state, attempts made of total, due time, who holds
  it), the queue history, and Put back in queue, shown only where the person may
  assign there (new `LeadPolicy::manageQueue`; Put back uses `returnToQueue`).
- **Shared pieces touched.** `ClaimNextLead`: behaviour-neutral extraction of the
  candidate filter and cooldown subquery into `DueQueue` so the count and the claim
  read the same query. `components/confirmation-modal.blade.php` gained a `{{ $slot }}`
  for extra fields (no change to existing uses). `QueueEventType::label()` and
  `QueueHoldEndReason::label()/notice()` for wording.
- **No** new table, migration, permission, role grant, audit event, `LeadStatus`
  case or package.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` (Pint, PHPStan level 7, full Pest suite), run on this machine on SQLite | **Passed.** 1968 tests, 9514 assertions, 0 failures. Pest reported 299 s; the whole command took 5 min 7 s. |
| New file `tests/Feature/Queue/QueueScreenTest.php` | 36 tests, all passing |
| GitHub "Checks and tests (SQLite)" | recorded by the office |
| GitHub "Migrations and tests (MySQL 8.4)" | recorded by the office |
| Merge | recorded by the office |
| Staging deployment | recorded by the office |
| Rendered browser review | **Not run.** No browser is available in this environment. See Jacob's steps below. |
| MySQL | **Not run here** (the suite ran on SQLite); the MySQL GitHub job is the check. |

Mapping of the plan's "Step 4c" > "Tests" list:

| Plan item | Test file and test |
| --- | --- |
| Route and page: refused to Sales Consultant, Production Manager, Finance, no grants, guest, by URL and by direct Livewire call; open to the four roles | `tests/Feature/Queue/QueueScreenTest.php`: "the page opens for the four queue roles", "…refused by URL and by direct Livewire call…", "a guest…", "actions on the page are refused again when the access is gone" |
| Navigation: live for four roles, hidden otherwise; Contact Center still opens on Leads; IDs unique; no Sales item | `QueueScreenTest.php` "the queue item is live…", "the menu shows Call Queue…"; `tests/Feature/Support/Navigation/NavigationTest.php` (queue permission used by exactly the Call Queue item; existing ID-uniqueness test); `tests/Feature/Leads/LeadNavigationTest.php` unchanged and green |
| Count matches what Next lead would offer per role and scope; excludes outside hours, held by others, just skipped | `QueueScreenTest.php` "the count equals what next lead would offer, per role and scope", "the count leaves out…" |
| Next lead shows one Lead; no list of due Leads; second session cannot get the same Lead | `QueueScreenTest.php` "next lead shows one lead and the page never lists the due leads", "a second session cannot obtain the same lead" (the engine's real-process race is still `QueueConcurrencyTest`) |
| Logging from the card saves one attempt through the shared action, ends the hold, schedules next; Lead page form unchanged | `QueueScreenTest.php` "logging from the card…", "an attempt form left open past the hold still saves…"; slice 3 `LeadContactAttemptTest.php` unchanged and green |
| Skip and Disqualify from the card incl. reason rules and permissions; stale card (hold expired, Lead moved, access revoked) saves nothing and shows the reason | `QueueScreenTest.php` "skip needs a reason, and Other needs a note", "disqualify from the card follows the existing reasons and permission", "a stale card saves nothing…", "a hold that ran out is explained as time running out…" |
| Summary numbers; Location Manager sees only their Locations; warning at 11 minutes and when missing, not at 9 | `QueueScreenTest.php` "the manager summary shows each number…", "a location manager sees only their locations numbers", "the timer warning shows at 11 minutes…" (11, never ran, 9) |
| Times: Location-local time and ISO timestamp for Eastern and Pacific; nothing reads a device timezone | `QueueScreenTest.php` "the card shows the location time and the device time source for Eastern and Pacific locations", "nothing on the server reads a device timezone" |
| Put back from both places with the dialog rules; marker and history hidden from people who may not assign | `QueueScreenTest.php` "put back from the page needs a note…", "put back is refused to a setter…", "the lead page shows the cadence panel, history and put back only where the person may assign" |
| Native-dialog regression | the existing `ApplicationConfirmationDialogsTest.php` scan (covers the new page and partial) plus `QueueScreenTest.php` "the queue page, the lead page and the shared form use the shared confirmation dialog…" |
| Polling only reads; a gone hold shows the reason only | `QueueScreenTest.php` "the page polls every ten seconds and polling never claims, extends or releases a hold", "when someone disqualifies the lead the card is replaced by the reason only" |

Existing tests changed (none weakened or deleted):
`tests/Feature/Settings/ContactCenterSettingsTest.php`: the two tests that pinned
the files mentioning the queue permission and asserted no queue route now pin the
new truth (the enum, the role lists, `Navigation.php`, `DueQueue.php`,
`QueueEligibility.php`, the page and `routes/web.php`; exactly one route and one
navigation item). A third test in that block ("holding the queue permission opens
no extra page") also asserted no navigation item used it, so it became "opens no
settings page". `NavigationTest.php`: "the queue permission is used by no
navigation item" now expects exactly the Call Queue item.

### Staging scheduler

**Could not be checked again.** This environment cannot see the staging server or
Forge. **The scheduler is not known to be running on staging.** Nothing was changed
anywhere. **The on-screen warning is now how Jacob can tell:** open the Call Queue
as a manager; if the timer warning is showing two minutes after a deployment, the
scheduler is not running.

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
- **The setter's count** uses the same candidate query as the claim
  (`DueQueue::candidateQuery`), plus the person's skip cooldown. The Lead held is
  not in it.
- **The manager part** is shown to anyone who may assign Leads somewhere, for
  exactly those Locations (plus unrouted when the company-wide rule allows).
  "Due now" there means in the queue, due, not held and inside its Location's
  calling hours now.
- **A hold that is gone** is explained from the queue history for its hold token;
  no history means "time ran out". Access lost while the hold row still stands is
  shown as "Your access to this lead changed."
- **A call is never thrown away:** an open attempt form survives the card going
  away and saves if the person may still work the Lead; if not, nothing is saved and
  the reason is shown.
- **Polling only reads;** the countdown runs in the browser.
- **The form's time field is unchanged** (company time).
- **Three 4b choices confirmed by the screen:** the timer does not end a hold
  because hours closed (the poll and the next Next lead do); a skip is accepted
  outside calling hours while the person holds the Lead; Next lead with no queue
  access anywhere is refused as not authorized (403).
- **A busy database on the page's own saves** shows a "try again" message and
  saves nothing.

Choices of mine beyond the plan:

- **Locked Livewire state, not browser IDs.** The cadence ID, hold token and the
  Lead a dialog was opened for are `#[Locked]` properties set by the server and
  re-resolved on every action.
- **A stale hold at Skip** closes the dialog and shows the reason; the engine's
  own "no longer holding" check is still what decides.
- **Disqualify on the card** has the reason list, duplicate choice and note, but
  not the Lead page's "Schedule follow-up instead" shortcut. The full Lead is one
  link away.
- **`LeadPolicy::manageQueue`** (view plus "hands out Leads there") is a new
  policy method gating the Lead page's cadence panel and history. It adds no
  permission.
- **The held list and cadence-finished list show up to 25 Leads;** the counts are
  exact.

## Deviations from the plan

- **`phpstan.neon` changed** (not in the work order's list): one `ignoreErrors`
  entry for the "trait is used zero times" notice on `RecordsContactAttempts`,
  because its only users are Livewire single-file pages, which PHPStan does not
  analyse. No other analysis rule changed.
- **`NavigationTest.php` and one extra test in `ContactCenterSettingsTest.php`
  changed** as described above, because both asserted the old "no navigation item"
  state the work order says is now false.
- **`LeadPolicy` gained `manageQueue`;** `QueueEventType` and `QueueHoldEndReason`
  gained wording methods.
- Nothing in engine behaviour changed.

## Known limitations

- **Backlog of Leads due at once.** Leads captured since 4b are already due. When
  4c is deployed the first setters will see a large "due now" count and each Lead
  is handed out one at a time, newest never-called first.
- **The deadlock follow-up is still open** (4b follow-up 1: Lead-before-cadence lock
  order in `EndQueueHold::atLocation` and `RunQueueTick::releaseExpiredHold`, and a
  retry around the hooked actions). On MySQL, two actions on the same hold within
  milliseconds can end in a deadlock error for one; nothing is half-saved. The page
  answers a busy database on its own saves with "try again".
- **The attempt form's time field is in company time,** not the device or Location
  timezone (decision 68), also on the card.
- **No automatic return to the queue** after Deferred Follow-up, reopening, or a
  finished cadence: a manager uses Put back in queue.
- **Every queue role may assign Leads,** so the Call Center Agent also sees the
  manager summary and the cadence-finished list for their Locations (see below).
- The page refreshes on a 10-second poll, so a change can take up to 10 seconds to
  show; a save always re-checks on the server.
- Rendering was exercised only through Livewire and HTTP tests; see the browser
  review below.

## Decisions needed from Jacob

1. **Should a Call Center Agent see the manager part?** The plan says "people who
   may assign Leads". The existing Call Center Agent role has the assign permission,
   so it sees the summary, the timer warning and the cadence-finished list (and can
   Put back) at their own Locations. If that is not wanted, the fix is a product
   decision about the role, not a code tweak.
2. Confirm or change the readings and choices listed above.
3. Whether to approve the deadlock follow-up as its own job.
4. Whether staging's scheduler is on (Forge step above).

## Open follow-ups

1. The deadlock follow-up (above).
2. Role changed in place: if an edit-grant action exists or is added, give it the
   same hook as `RevokeAccess`.
3. Suite duration is about five minutes; keep watching it.
4. Plan wording noted in the 4b handoff (the deadlock-retry sentence applies to the
   claim only; contract 5 pre-check sentence) is still not edited.

## What Jacob can and cannot observe on staging after this step

After deployment staging has a Call Queue item for the four roles. Nothing is
sent: no call, text or email. Whether the timer runs is visible as the manager
warning. Production is untouched.

## Browser review for Jacob on staging (plain English)

I could not run a browser, so none of this has been looked at on a real screen.
Use fictitious Leads only.

1. **Each role's menu.** Sign in as, in turn: an Owner, a Leadership user, a
   Location Manager, a Call Center Agent: Marketing > Contact Center shows **Leads**
   and **Call Queue**. Then a Sales Consultant, a Production Manager and a Finance
   user: no Call Queue anywhere, and typing `/marketing/contact-center/queue`
   gives "forbidden". Clicking Contact Center itself still opens Leads.
2. **Next lead.** As a Call Center Agent open Call Queue. You see "N leads due now"
   and a **Next lead** button, and no list of Leads. Press **Next lead**. One card
   appears.
3. **The card.** Check it shows the name, a phone number, the Location, "Attempt 1
   of …", what was asked for, "Earlier attempts", a countdown "Time left on your
   hold", and "Open the full lead". Each time shows your own device time and, beside
   it, "Location time …" in the Location's zone. For an Eastern and a Pacific
   Location the two should differ by the right number of hours (if your device is in
   Eastern, only the Pacific Location should look different).
4. **Log attempt.** Press **Log attempt**. The dialog says outbound phone call and
   has no channel or direction choice. Choose "No answer", enter a duration, and
   save. The card is replaced by "Your call was logged." and **Next lead**. Open the
   Lead: one new attempt, and the queue history shows the next attempt scheduled.
5. **Skip.** Press Next lead, then **Skip**. Try saving with no reason (it should
   refuse), pick "Other" with no note (it should refuse), then give a note and save.
   Press Cancel in a dialog: focus starts on Cancel, Escape closes it, and focus
   goes back to the button you pressed.
6. **Disqualify.** On a card press **Disqualify**, choose a reason (try Duplicate and
   the "original" choice), save. The card is replaced by a message and Next lead.
7. **The gone-hold message.** Press Next lead and leave the card open. In another
   browser, as a manager, either disqualify that Lead, or move it to another
   Location, or wait out the hold (see the Contact Center settings for its length).
   Within about 10 seconds the card should be replaced by a short reason (for
   example "This lead was disqualified…") and show nothing about the Lead. Also try
   opening **Log attempt**, letting the hold run out, and saving: the call should
   still be saved.
8. **Nothing due.** When no Lead is due the page says "Nothing is due for you right
   now." Outside calling hours (evening) it also says when they open.
9. **Manager summary and timer warning.** As a Location Manager or Owner open Call
   Queue: you see Due now, Longest wait, Leads held now, and Cadence finished, no
   contact, and "held by <name>" while a setter holds a Lead. If the queue timer has
   not run in the last 10 minutes, or never, a yellow "The queue timer has not run
   lately" warning shows. **This is how you tell whether the scheduler is on:** two
   minutes after a deployment there should be no warning. If there is, do the Forge
   step above.
10. **Cadence-finished list.** In the "Cadence finished, no contact" list each Lead
    is a link. Press **Put back in queue**: the dialog needs a note (try saving
    empty), then save. The Lead leaves the list and becomes due at the next opening
    of calling hours.
11. **Put back from the Lead page.** Open a Lead whose cadence finished (or ended):
    a "Call queue" panel shows its state, the same marker, "Queue history" (skips
    with their reasons, returns with their notes) and **Put back in queue**. Check a
    Sales Consultant who can open the Lead does not see this panel.
12. **Two-person test.** Have two setters sign in on two browsers (different
    people, same Location, at least ten Leads due). Both press **Next lead** at the
    same moment, then both press Skip (or log a call), and repeat many times. They
    must never be shown the same Lead at the same time. If they ever are, stop and
    tell the office.
