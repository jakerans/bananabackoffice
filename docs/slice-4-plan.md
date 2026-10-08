# Slice 4 — Scheduled Work and the Due Queue: Implementation Plan

Status: **Planned and approved for implementation (2026-10-08). Nothing in this
document is built yet.** Jacob's decisions are 65 to 72 in
[decisions.md](decisions.md); decision 73 records the design below. Where this plan
and those decisions disagree, the decisions win and the build stops to ask.

Product status: scope, exits, order, holds and failure rules are **LOCKED**. The
cadence, calling hours, calling days and hold length are **CONFIGURABLE** on a
settings page. Implementation status: **approved**, in three separately reviewed
steps: **4a** settings and timezones, **4b** the engine, **4c** the queue screen.

## What slice 4 is, and is not

Every newly captured Lead enters a phone cadence. Marketing > Contact Center gains
a Call Queue: a "due now" count and a **Next lead** button that gives a setter one
Lead nobody else can get. The setter calls on their own phone, logs the result with
the slice 3 contact-attempt form, and the system schedules the next attempt.

Not in slice 4, and not to be added by any step: a dialer, a provider integration,
any outbound call, text or email sent by Backoffice, the new-lead popup, the snooze
tray, Aged Leads, AI, penalties, email or text alerts, a holiday calendar,
per-Location cadences or hours, moving existing screens off company time, new
packages, paid services. No B7 lifecycle status is added or changed. B7 Deferred
Follow-up stays a separate, unchanged workflow.

## The contracts

### 1. Scheduler contract

**The queue is read from stored state. It is never driven by timer events.**

- A Lead in the cadence has exactly one row in `lead_cadences`, holding the one
  attempt that is currently due (`due_at`, UTC). There is no table of future
  attempts and nothing is pre-generated.
- "Due now" is a live question asked of that table when someone opens the queue or
  presses Next lead: `state = in_queue`, `due_at <= now`, not held by someone else,
  and the Lead's Location is inside calling hours at this moment.
- The next attempt is calculated and stored only when the current one is logged,
  in the same database transaction as the contact attempt. So attempts cannot pile
  up, and an attempt that was never made simply stays due.
- A hold expires by its own stored time (`held_until`). Claiming checks that time
  directly, so an expired hold is taken over without any timer having run.

The background timer is therefore a safety net and a heartbeat, not the engine:

| | |
| --- | --- |
| Command | `php artisan backoffice:queue-tick` |
| Registered in | `routes/console.php`, with Laravel's built-in scheduler |
| Frequency | Every minute, `withoutOverlapping(5)` (the lock uses the existing database cache store; no Redis) |
| Runs | Inline in the scheduler process. **No queue worker, daemon or queued job is used or needed.** |

Each tick, in this order, each part independent of the others:

1. **Record the heartbeat:** set `scheduler_heartbeats.last_ran_at` for `queue_tick`.
2. **Release expired holds** (`held_until <= now`), writing one `hold_expired`
   queue event each.
3. **Reconcile.** For every cadence still `in_queue`: if its Lead is Disqualified
   or in Deferred Follow-up, end the cadence with that reason; if it is held by
   someone who may no longer work that Lead, end the hold with reason
   `access_lost`. Both are already done at once by the actions that cause them
   (see the failure contract); this pass only repairs what a crash left behind.

The tick never schedules an attempt, never marks a Lead finished and never
contacts anyone.

### 2. Timezone contract

- Every stored time stays UTC. The application timezone stays UTC.
- **Each Location has an IANA timezone** (`locations.timezone`), default
  `America/New_York`. It is chosen from a fixed list of United States zones:
  Eastern `America/New_York`, Central `America/Chicago`, Mountain `America/Denver`,
  Arizona `America/Phoenix`, Pacific `America/Los_Angeles`, Alaska
  `America/Anchorage`, Hawaii `Pacific/Honolulu`. The server validates against that
  list. Daylight saving is handled by PHP's own timezone data.
- **Calling hours and calling days are evaluated in the Lead's Location timezone,**
  at the moment of the check. One set of hours and days applies company-wide, in
  each Location's local time.
- **An unrouted Lead uses `America/New_York`.** This is a named constant of the
  queue, deliberately not `APP_DISPLAY_TIMEZONE`, so changing the company display
  time can never move calling hours. Once routed, the Lead uses its Location's
  timezone from the next check onwards.
- **A stored `due_at` is an instant and is never recalculated** when a Lead is
  routed or moved, when a Location's timezone is changed, or when the hours, days
  or cadence are changed. Those changes apply to (a) every live "is it calling
  hours?" check from then on, and (b) every attempt scheduled from then on. A Lead
  is never offered outside its Location's current calling hours, whatever its
  `due_at` says.
- Calling hours are limited to 8:00 am–9:00 pm, and daylight-saving changes happen
  at 2:00 am, so a calling window never contains a clock change.
- **Display on the queue screen:** each time is shown in the setter's own device
  timezone, with the Location's local time beside it. The server renders the
  Location-local time and an ISO timestamp; the browser formats the device-local
  time with its built-in `Intl.DateTimeFormat`. The device timezone is display
  only: it is never sent to the server and decides nothing.
- **Existing screens are unchanged.** Received time, follow-up dates and every
  other existing screen keep company time (decision 40). Moving them to Location
  time is a separate later job (decision 68).

### 3. How an attempt is scheduled

Definitions, all in the Lead's Location timezone (or Eastern while unrouted):

- A **calling window** is `[open, close)` on a ticked calling day.
- The **next opening at or after** an instant is that instant if it is inside a
  window, otherwise the start of the next window.
- The cadence is a list of **cadence days**. Day 1 is *timed*: a list of waits in
  minutes, the first always 0. Every later day is *spread*: a number of attempts.
- The **gap** before an attempt that is not the first of its cadence day is: on
  day 1, its configured wait; on a spread day, the window length divided by that
  day's number of attempts.

The rule, applied with the settings in force at that moment:

| Attempt | Due at |
| --- | --- |
| Attempt 1 | The next opening at or after the moment the Lead is captured. |
| A later attempt on the same cadence day | The previous counted attempt's time plus the gap; if that is not inside a window, the next opening after it. |
| The first attempt of a new cadence day | The opening of the first calling day after the local date of the previous counted attempt. |

What follows from it:

- Day 1 is the day the Lead arrives; if it arrives outside calling hours, attempt 1
  waits for the next opening, and that day is day 1.
- An attempt that does not fit before closing rolls to the next opening and keeps
  its place, so every later attempt moves along with it. Nothing is skipped: a
  Lead gets every attempt in the cadence unless it leaves the queue first.
- With calls made on time and the default 8:00 am–9:00 pm hours, a three-attempt
  day falls at 8:00 am, 12:20 pm and 4:40 pm, and a two-attempt day at 8:00 am and
  2:30 pm.
- When the attempt just counted was the last in the cadence, no further attempt is
  scheduled and the cadence becomes `finished_no_contact`. If the cadence was
  shortened so that a Lead has already had at least the new total, its next
  counted attempt finishes it.

Default cadence, created by the migration: day 1 waits `[0, 30, 90]` minutes
(immediately, 30 minutes later, about 2 hours after arrival); day 2 three attempts;
days 3, 4 and 5 two each. Twelve attempts.

### 4. What a logged contact attempt does to the cadence

Inside `RecordManualContactAttempt`, in its existing transaction, after a **new**
attempt row is inserted (a replayed submission returns the existing row and does
nothing more):

| The attempt | Effect on a Lead whose cadence is `in_queue` |
| --- | --- |
| Is a real two-way connection (`was_connected`), any channel, either direction | The cadence ends, `connected`. The Lead leaves the queue and a person decides the next step. |
| Is an outbound phone call, not connected (no answer, voicemail, wrong party, other), and happened after the last counted attempt | It **counts**: it uses up the current attempt, is stamped with the cadence cycle and step, and the next attempt is scheduled, or the cadence finishes. |
| Anything else (a text or email sent, an unanswered inbound call, a back-dated call older than the last counted one) | Evidence only. No effect on the cadence. |

In every case: the Lead's lifecycle status, assignee and `lifecycle_version` are
untouched, the attempt's `lifecycle_result` stays `no_change`, and any hold on the
Lead ends. A Lead with no cadence row, or whose cadence is not `in_queue`, is
unaffected, exactly as in slice 3. It makes no difference whether the attempt was
logged from the queue card or from the Lead page.

### 5. Atomic claim

One conditional `UPDATE` decides who gets a Lead. It is the same pattern the B7
lifecycle revision already uses (`ChangeLeadLifecycle`), and it needs nothing that
only one database offers: no `SKIP LOCKED`, no advisory locks, no stored
procedures.

`ClaimNextLead`, in one transaction retried up to three times on a deadlock or a
busy database:

1. Release the caller's own hold if it has expired.
2. If the caller still has a live hold, re-authorize that Lead and return it.
   Pressing Next lead twice, or in two tabs, returns the same Lead.
3. Work out, in PHP, the Locations the caller is eligible for **and** that are
   inside calling hours right now, and whether unrouted Leads are both allowed and
   open (Eastern).
4. Read up to ten candidate cadence IDs in queue order, with no lock.
5. For each candidate in turn:

   ```sql
   UPDATE lead_cadences
      SET held_by_user_id = :me, hold_token = :uuid,
          held_at = :now, held_until = :now_plus_hold
    WHERE id = :candidate
      AND state = 'in_queue'
      AND due_at <= :now
      AND (held_by_user_id IS NULL OR held_until <= :now)
   ```

   Exactly one row affected means the caller won it. Zero means someone else did:
   try the next candidate. If the row carried someone's expired hold, a
   `hold_expired` event is written for them.
6. Load the Lead and authorize it again through the policy. If that fails, release
   it and continue with the next candidate.
7. Write the `claimed` event and return. If every candidate was lost, read again
   (at most three rounds), then answer "nothing due right now".

Why it is safe on both engines: an `UPDATE` always reads the latest committed row
and holds the row lock (MySQL InnoDB) or the single write lock (SQLite) while it
tests the `WHERE` clause, so two callers can never both see the row as free.

Three database rules back the code up, so a bug cannot break the guarantees:

- `lead_cadences.lead_id` is unique: one cadence, and so one hold, per Lead.
- `lead_cadences.held_by_user_id` is unique (many empty values are allowed on both
  engines): **one held Lead per setter.** A second claim racing in another tab
  fails on this index and is answered with the Lead already held.
- `contact_attempts (lead_id, cadence_cycle, cadence_step)` is unique: a cadence
  step can be used up only once.

Queue order, for the candidates in step 4:

1. Leads never called: `cycle = 1` and `attempts_made = 0`, newest first
   (`leads.received_at` descending, then ID descending).
2. Then everything else, most overdue first (`due_at` ascending, then ID).

A Lead the caller skipped is left out of their candidates until one hold length
has passed since the skip. A setter never sees a list to choose from.

### 6. Retry and failure contract

| Situation | What happens |
| --- | --- |
| Next lead pressed twice, or a request retried | The same held Lead is returned. No second hold. |
| The attempt form submitted twice | Slice 3's submission ID returns the existing attempt; the cadence moves once. |
| Two people log a call on the same Lead at the same moment | The Lead row is locked for each in turn (slice 3). Both calls are kept as evidence; each counted one uses its own step. |
| The setter walks away | The hold ends at `held_until`. The Lead is due again for everyone, whether or not the timer ran. |
| The setter logs a call after their hold expired | The attempt is saved if they may still work the Lead, and it counts as above. If someone else now holds the Lead, that hold ends and they are told. A real call is never thrown away. |
| A held Lead is disqualified, scheduled for Deferred Follow-up, routed, moved, or returned to central | The same action, in its own transaction, ends the cadence or the hold. The setter's card says why on its next poll (every 10 seconds) or their next click. |
| The setter's access is revoked or their account deactivated | `RevokeAccess` and `DeactivateStaffMember` end their hold at once if they may no longer work that Lead, next to the existing release of assignments. |
| Any save from the queue | Re-resolves the Lead and re-authorizes the person against the Lead's current Location, as every Lead action does. A stale card saves nothing it is no longer allowed to. |
| Server restart or outage | Nothing to replay. Due times and hold times are stored instants; on return, overdue Leads are simply due, most overdue first, and stale holds are already expired. |
| A tick fails partway | Each part and each Lead is handled on its own and is safe to repeat. The failure is logged without customer details and the next tick tries again. The overlap lock frees itself after five minutes. |
| The timer stops altogether | The queue keeps working. The manager summary warns once the heartbeat is more than 10 minutes old, or has never been written. |
| Deadlock or "database is locked" during a claim | The transaction is retried, up to three times, then reported as "try again". |

## Data model

Every schema change is additive, gives each foreign key an index of its own
(decision 21), and is run on SQLite and on MySQL 8.4 before review.

### Step 4a

**`locations.timezone`** — `string(64)`, not null, default `America/New_York`.
Existing Locations receive the default.

**`contact_center_settings`** — one row per saved version; the current settings
are the highest `version`. Rows are never changed or deleted. The migration
creates version 1 with the defaults, so every environment has settings without a
seeding step (as decision 39 did for the lists).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `version` | unsigned int, unique | A save writes `loaded version + 1`; the unique index refuses a second save made from the same starting point. |
| `cadence` | json | A list of cadence days: `{"type":"timed","waits_minutes":[0,30,90]}` then `{"type":"spread","attempts":3}` and so on. |
| `calling_opens_at` | unsigned smallint | Minutes after midnight. Default 480 (8:00 am). |
| `calling_closes_at` | unsigned smallint | Minutes after midnight. Default 1260 (9:00 pm). |
| `calling_days` | json | A list of ISO weekday numbers, 1 (Monday) to 7 (Sunday). Default all seven. |
| `hold_minutes` | unsigned tinyint | Default 5. |
| `changed_by` | bigint, nullable, FK `users` (null on delete), own index | Empty for version 1. |
| `changed_by_name` | string, nullable | Copied, as audit events do. |
| `created_at` | datetime | |

Validation, always on the server:

- Hours: `480 <= opens < closes <= 1260`, in 15-minute steps, at least 60 minutes
  long. Nothing outside 8:00 am–9:00 pm can be saved by any route.
- Days: at least one.
- Cadence: 1 to 14 days. Day 1 is timed with 1 to 6 attempts, its first wait 0 and
  each later wait 5 to 720 minutes. Each later day is spread with 1 to 6 attempts.
- Hold: 1 to 30 whole minutes.

### Step 4b

**`lead_cadences`** — one row per Lead that has ever entered the cadence.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `lead_id` | bigint, unique, FK `leads` (restrict) | |
| `state` | string(30), indexed with `due_at` | `in_queue`, `connected`, `disqualified`, `deferred_follow_up`, `finished_no_contact`. |
| `cycle` | unsigned smallint | 1 at first; a restart adds 1. |
| `attempts_made` | unsigned smallint | Counted attempts in this cycle. |
| `due_at` | datetime, nullable | The one attempt due. Empty unless `in_queue`. |
| `last_counted_attempt_at` | datetime, nullable | |
| `entered_at` | datetime | When this cycle began. |
| `ended_at` | datetime, nullable | |
| `held_by_user_id` | bigint, nullable, **unique**, FK `users` (null on delete) | |
| `hold_token` | uuid, nullable | Identifies one hold, so a card can tell its own hold from a later one. |
| `held_at`, `held_until` | datetime, nullable | `held_until` indexed. |
| `last_skipped_by_user_id` | bigint, nullable, FK `users` (null on delete), own index | Cleared when an attempt is counted. |
| `last_skipped_at` | datetime, nullable | |
| `created_at`, `updated_at` | datetime | |

"Cadence finished, no contact" is `state = finished_no_contact` here and nowhere
else. `LeadStatus`, `lead_status_changes` and `lifecycle_version` are not touched.

**`lead_queue_events`** — append-only history of the queue, refused update and
delete in the application like audit events and the status history.

| Column | Notes |
| --- | --- |
| `id`, `lead_id` (FK, restrict), `lead_cadence_id` (FK, restrict) | Each with its own index. |
| `type` | `entered`, `claimed`, `hold_expired`, `hold_ended`, `skipped`, `attempt_counted`, `ended`, `finished_no_contact`, `returned_to_queue`. |
| `reason` | For `hold_ended`: `disqualified`, `deferred_follow_up`, `moved`, `access_lost`, `attempt_logged_by_other`. For `ended`: `connected`, `disqualified`, `deferred_follow_up`. For `skipped`: the skip reason. |
| `note` | The skip note, or the required note on a return to the queue. |
| `cycle`, `step` | The cadence position at that moment. |
| `hold_token`, `contact_attempt_id` (nullable FK) | |
| `location_id` (nullable FK), `location_name` | The Lead's Location then. History is filtered by it, as contact attempts are. |
| `actor_id`, `actor_name`, `impersonator_id`, `impersonator_name` | Actor is empty for the timer. Impersonation is recorded as everywhere else. |
| `affected_user_id` | Whose hold expired or was ended. |
| `created_at` | |

**`contact_attempts`** — two nullable columns, written only when the row is
inserted, never afterwards: `cadence_cycle` and `cadence_step` (unsigned
smallint), with a unique index on `(lead_id, cadence_cycle, cadence_step)`.
Existing rows keep both empty. The table stays append-only.

**`scheduler_heartbeats`** — `name` (string, unique) and `last_ran_at` (datetime).
One row, `queue_tick`.

**Who enters.** `CaptureLead` creates the cadence row and its `entered` event in
the transaction that creates the Lead, for every Lead captured after step 4b is
deployed. The migration creates no rows: Leads that already exist do not enter.

## Permissions

Two new permissions. Names are exact.

| Permission | Enum case | Scope | Allows | Roles | Step |
| --- | --- | --- | --- | --- | --- |
| `settings.contact_center.manage` | `ManageContactCenterSettings` | Company (all-locations grant only; added to `isCompanyLevel()`) | Open Company > Settings > Contact Center and change the cadence, calling hours and days, and hold length | Owner, Leadership | 4a |
| `leads.work_queue` | `WorkLeadQueue` | Location | Open the Call Queue, take the next due Lead, skip it with a reason | Owner, Leadership, Location Manager, Call Center Agent. **Not** Sales Consultant, Production Manager or Finance. | 4b |

Rules that use existing permissions, unchanged:

- **A Location's timezone** is changed by whoever may manage Locations
  (`settings.locations.manage`, Owner only today).
- **Who is offered a Lead.** A routed Lead: the person holds `leads.work_queue` at
  the Lead's own Location and may work the Lead there (`LeadPolicy::update`). An
  unrouted Lead: the person holds `leads.work_queue` through an all-locations
  grant and already passes the existing unrouted rule (`leads.view_unrouted` and
  `leads.manage`, both company-wide). A Location Manager therefore never receives
  an unrouted Lead. The header's selected Location does not widen or narrow the
  pool: a Lead is authorized by its own Location (decision 40).
- **Logging the attempt** is `LeadPolicy::update`, as in slice 3.
- **Disqualify on the card** is `LeadPolicy::changeStatus` and the existing
  `DisqualifyLead` action, with the B7 reasons, rules and audit event.
- **Put back in queue** and **the manager summary** use the existing "may assign
  Leads there" rule (`leads.assign`; for an unrouted Lead, the existing
  company-wide rule). A new policy method, `LeadPolicy::returnToQueue`, also
  requires that the Lead has a cadence, is not `in_queue`, and is New, Working or
  Qualified.
- **A hold is not assignment** and grants nothing. Holding, skipping and logging
  never read or change `assigned_user_id`.

Matrix rows are in [permission-model.md](permission-model.md), "Slice 4".

## Navigation

| Change | Detail | Step |
| --- | --- | --- |
| Company > Settings > **Contact Center** | New ID `settings.contact_center`, route name `settings.contact_center`, URL `/settings/contact-center`, live, permission `settings.contact_center.manage`. Listed after Lead & Sales Lists. | 4a |
| Company and Settings entry | `settings.contact_center.manage` joins the existing union of permissions that open the `company` Center and the `settings` node, so a Leadership member can reach the page. `/settings` gains it as the fourth landing choice, after Locations, Users and Access, and Lead & Sales Lists. | 4a |
| Company > Locations | No new item. The existing page gains a timezone field per Location. | 4a |
| Marketing > Contact Center > **Call Queue** | New ID `marketing.call_center.queue`, route name `marketing.call_center.queue`, URL `/marketing/contact-center/queue`, live, permission `leads.work_queue`. A third-row item after Leads. Contact Center's primary stays `shared.leads`, so nobody's landing page changes. | 4c |

No existing ID, route name or URL changes. No legacy ID is reused. The Call Queue
is in Marketing only; Sales gets no queue item.

## Audit events

New `AuditAction` cases, each saved in the same transaction as its change
(decision 38), each recording the real Owner when made while impersonating:

| Case | Value | About | Before and after hold | Step |
| --- | --- | --- | --- | --- |
| `LocationTimezoneChanged` | `location.timezone_changed` | The Location | The timezone | 4a |
| `ContactCenterCadenceChanged` | `contact_center.cadence_changed` | The settings record | The cadence days and total attempts | 4a |
| `ContactCenterCallingHoursChanged` | `contact_center.calling_hours_changed` | The settings record | Opening, closing, calling days; `reset: true` when Reset to default was used | 4a |
| `ContactCenterHoldLengthChanged` | `contact_center.hold_length_changed` | The settings record | Hold minutes | 4a |
| `LeadReturnedToQueue` | `lead.returned_to_queue` | The Lead | The cadence state, cycle and step before and after, and the required note | 4b |

Only what actually changed is recorded; a save that changes nothing records
nothing. The settings record gets the short fixed name `contact_center_settings`.

Not audit events, by design: taking, skipping, expiring and counting. They are
operational history and go to `lead_queue_events`. A disqualification from the
card records the existing `lead.disqualified` event. A contact attempt remains
its own evidence and is still not an audit event or Customer Activity.

## Step 4a — Settings and timezones

Builds: the `locations.timezone` column and its field on the Locations page; the
`contact_center_settings` table with version 1; `settings.contact_center.manage`;
the Contact Center settings page; the navigation changes marked 4a; the four 4a
audit events. **Nothing reads these settings yet.** No cadence, queue, command or
schedule exists after 4a.

The settings page, one section each, each with its own Save:

- **Call cadence.** Day 1: attempt 1 is always "right away"; each further attempt
  has a wait in minutes. Later days: a number of attempts, "spread evenly through
  calling hours". Add or remove a day. The total number of attempts is shown. A
  note says changes apply to attempts scheduled from now on.
- **Calling hours.** Opening and closing time in 15-minute steps, offered only
  between 8:00 am and 9:00 pm, with "applied in each Location's local time". A
  **Reset to default** button restores 8:00 am–9:00 pm through the shared
  confirmation dialog.
- **Calling days.** Seven tick boxes.
- **Hold length.** Minutes.

Tests (Pest; every one runs on SQLite and on MySQL 8.4):

- Migration: `timezone` exists, existing Locations get `America/New_York`; version
  1 exists with the exact defaults and twelve attempts.
- Timezone: only someone with `settings.locations.manage` can change it, including
  by direct Livewire call; a value outside the list is refused; the audit event
  records before and after; an unchanged save records nothing.
- Permission: `settings.contact_center.manage` is company-level; Owner and
  Leadership hold it; no other role does; Leadership through a single-location
  grant cannot open the page; Location Manager, Call Center Agent, Sales
  Consultant, Production Manager and Finance are refused by URL and by direct
  Livewire call. `RoleTest` and the permission matrix agree.
- Hours: 7:45 am, 9:15 pm, closing before opening, a 45-minute window and a value
  off the 15-minute grid are each refused on the server; narrowing inside the
  limits saves; Reset restores 480 and 1260 and is audited with `reset`.
- Days: none ticked is refused.
- Cadence: day 1 first wait must be 0; out-of-range waits, counts and day totals
  are refused; a valid edit writes a new version and leaves the old row unchanged.
- Hold: 0 and 31 refused.
- Versions: two saves from the same loaded version, the second is refused as
  stale and writes nothing; each save is one new row plus its audit event, or
  neither (the audit event is made to fail on purpose).
- Impersonation: a change made while impersonating records the Owner.
- Navigation: registry IDs stay unique; the new item is live and routed; a
  Leadership member with only this permission reaches it through Company and
  `/settings`; the existing landing order for the other three permissions is
  unchanged; nobody without the permission sees the item.
- No native confirmation dialog is introduced (the existing regression test).

## Step 4b — The engine

Builds: the 4b tables and columns; `leads.work_queue` in the enum and roles; the
calculators (`app/Support/Queue/CallingHours.php`, `CadenceSchedule.php`); the
actions in `app/Actions/Queue/` (`EnterLeadCadence`, `AdvanceLeadCadence`,
`EndLeadCadence`, `ClaimNextLead`, `SkipHeldLead`, `EndQueueHold`,
`ReturnLeadToQueue`, `RunQueueTick`); the hooks in `CaptureLead`,
`RecordManualContactAttempt`, `DisqualifyLead`, `MoveLeadToNurture`, `RouteLead`,
`ReturnLeadToCentral`, `RevokeAccess` and `DeactivateStaffMember`;
`LeadPolicy::returnToQueue`; the `backoffice:queue-tick` command and its schedule
entry; `lead.returned_to_queue`. **No screen.** From the moment 4b is deployed,
newly captured Leads enter the cadence and logged attempts move it, but there is
no queue to open until 4c.

**Skip reasons** are a fixed enum in code, `QueueSkipReason`, not a managed list
(decision 39 keeps the managed lists at the existing ones): `no_usable_number`
"No usable phone number", `needs_manager` "Needs a manager to look at it",
`cannot_call_now` "I cannot make this call right now", `other` "Other", which
requires a short note. A skip releases the hold, leaves `due_at` as it was,
records the event, and keeps the Lead away from that setter for one hold length.

**Put back in queue.** `ReturnLeadToQueue` needs a note. A Lead whose cadence
`finished_no_contact`, or that has no attempts left, restarts: `cycle + 1`,
`attempts_made = 0`, attempt 1 due at the next opening. Any other Lead resumes
where it stopped: the next unused attempt is due at the next opening. The state
change is a conditional update on `state <> 'in_queue'`, so a double submit
changes nothing the second time.

Tests:

- **Calendar (unit, table-driven, with fixed clocks).** A Lead captured at 10:00
  am, 8:50 pm, exactly 9:00 pm, 3:00 am and on an unticked day; day 1 waits that
  cross closing roll to the next opening and keep their order; a full on-time run
  yields all twelve at the documented times; a late run still yields twelve;
  narrowed hours; a single calling day a week; Eastern, Central, Arizona and
  Pacific; both daylight-saving weekends; an unrouted Lead uses Eastern, then its
  Location after routing, with `due_at` unchanged.
- **Entering.** Every capture path creates exactly one cadence and one `entered`
  event with the Lead, or neither; a Lead that existed before has none and is
  never offered; capture still works identically otherwise.
- **Advancing.** Each row of the table in "What a logged contact attempt does";
  a replayed submission moves nothing twice; the twelfth counted attempt marks
  `finished_no_contact`; the Lead's status, assignee and `lifecycle_version` are
  byte-for-byte unchanged by every case; a Lead with no cadence behaves exactly
  as in slice 3 (the existing slice 3 tests stay green, with only the "no
  follow-up side effect" expectation updated to the new recorded rule).
- **Leaving.** Disqualify and Schedule follow-up end the cadence in the same
  transaction and roll back together with it; Resume working and Reopen do not
  put a Lead back; no `LeadStatus` case and no `lead_status_changes` row is
  added by any cadence change.
- **Claim, deterministic.** Order: never-called newest first, then most overdue;
  only due Leads; never outside the Lead's Location's calling hours, including a
  Lead overdue from yesterday; a second caller issued the same candidate gets
  zero rows and moves on; Next lead twice returns the same Lead; a caller's
  expired hold is released and another's expired hold is taken over with a
  `hold_expired` event; the unique index refuses a second hold for one setter.
- **Claim, real concurrency.** Eight separate PHP processes, started together
  with Laravel's built-in `Process` facade, each claim as a different setter from
  a pool of five due Leads in a database they genuinely share: the CI MySQL 8.4
  database in the MySQL job, a temporary file-backed SQLite database in the
  SQLite job. Asserted: exactly five claims succeed, every Lead has exactly one
  holder, no setter holds two, three are told nothing is due, and the event
  count matches. The processes are started by a test-support script under
  `tests/`, never by an application command or route. If this cannot be made to
  run reliably on either engine, the build stops and reports it; it is not
  quietly replaced by the deterministic test.
- **Eligibility.** A Lead at a Location the setter has no access to is never
  offered; an unrouted Lead only to company-wide lead staff; a Sales Consultant
  is refused; two grants do not combine into access neither gives; a deactivated
  Location or person gets nothing; impersonation uses the staff member's access
  alone.
- **Skip.** A reason is required; `other` needs a note; the Lead is not offered
  back to the skipper within one hold length but is offered to someone else at
  once, and to the skipper afterwards; no penalty field or counter exists.
- **Hold ends at once** on disqualify, Schedule follow-up, route, transfer, return
  to central, access revoked and deactivation, each with its reason and
  `affected_user_id`; an attempt logged by someone else ends it; an attempt
  logged by the former holder after expiry is still saved and counted.
- **Put back.** Who may and may not (a Sales Consultant may not; a Location
  Manager only where they may assign); the note is required; restart versus
  resume; refused for a Disqualified or Deferred Follow-up Lead and for one
  already in the queue; the audit event and queue event save with it or not at
  all; a double submit changes nothing.
- **Tick.** Running it twice is the same as once; it writes the heartbeat,
  releases expired holds and repairs a deliberately broken state; one Lead that
  throws does not stop the others; it never schedules, finishes or contacts.
  `php artisan schedule:list` includes it every minute.
- **Outage.** With the clock moved forward a day and no tick in between: every
  overdue Lead is offered once, in order, none twice, none lost, and yesterday's
  holds are free.
- **Append-only.** `lead_queue_events` refuses update and delete; so do the two
  new `contact_attempts` columns after insert.
- Every test refuses real outgoing requests, as the suite already does.

## Step 4c — The queue screen

Builds: the Call Queue page and its navigation item; the card; the shared
contact-attempt form; the skip dialog; Disqualify on the card; the manager
summary; the "Cadence finished, no contact" list; the cadence panel and Put back
in queue on the Lead page. No new table, permission or audit event.

- **For the setter.** A "due now" count for the Leads they could be given, and
  **Next lead**. With a Lead held: who it is, the phone number, the Location, the
  attempt number ("Attempt 4 of 12"), what was asked for, earlier attempts they
  may see, the time left on the hold, and a link to the full Lead. Times are
  shown in the device's timezone with the Location's local time beside them.
  Actions: **Log attempt**, **Skip** (reason dialog), **Disqualify**. When nothing
  is due: say so, and when calling hours are closed say when they open.
- **The attempt form.** The slice 3 form is moved into one shared piece used by
  the Lead page and the card, calling the same `RecordManualContactAttempt`
  action. There is one implementation of its rules. On the card, channel and
  direction are fixed to an outbound phone call; other channels are logged from
  the Lead page.
- **Told at once.** The page polls every 10 seconds with Livewire's built-in
  polling. If the hold is gone, the card is replaced by the reason (time ran out;
  disqualified by someone; moved to another Location; your access changed;
  someone else logged a call) and Next lead.
- **Manager summary**, for people who may assign Leads, counting only Leads the
  viewer may see: due now, longest wait, Leads currently held and by whom,
  cadence-finished count, and a warning when the heartbeat is more than 10
  minutes old or missing. No email or text is sent.
- **Cadence finished, no contact.** A list under the summary, each Lead linked,
  with Put back in queue. The Lead page shows the cadence state, the same marker,
  the queue history for people who may assign there (skips with their reason,
  returns with their note), and Put back in queue.
- Every confirmation uses the shared Flux dialog: focus on Cancel, Escape closes,
  the target is resolved and authorized again on confirm.

Tests:

- Route and page: refused to a Sales Consultant, Production Manager, Finance, a
  person with no grants and a guest, by URL and by direct Livewire call; open to
  the four roles.
- Navigation: the item is live under Marketing > Contact Center for the four
  roles, hidden otherwise; Contact Center still opens on Leads; registry IDs
  unique; Sales has no queue item.
- The count matches what Next lead would offer, for each role and scope, and
  excludes Leads outside calling hours, held by others and just skipped.
- Next lead shows one Lead; the page never renders a list of due Leads for a
  setter; a second browser session cannot obtain the same Lead.
- Logging from the card saves one attempt through the shared action, ends the
  hold and schedules the next attempt; the Lead page form behaves exactly as
  before (slice 3 tests green).
- Skip and Disqualify from the card, including B7's reason rules and its
  permission checks; a stale card (hold expired, Lead moved, access revoked)
  saves nothing it should not and shows the reason.
- The summary: each number against known data; a Location Manager sees only
  their Locations' numbers; the warning appears at 11 minutes and when no
  heartbeat exists, and not at 9.
- Times: the Location-local time and the ISO timestamp are rendered for Eastern
  and Pacific Locations; nothing on the server reads a device timezone.
- Put back from both places, with the dialog rules; the marker and history are
  hidden from people who may not assign there.
- The native-dialog regression test still passes. A rendered browser review of
  the queue is part of the 4c report.

## The server's scheduler on staging

Slice 4 needs Laravel's scheduler to run every minute on the staging server. It
is not known to be enabled: the application has never had a scheduled task.

**Exactly what is needed:** one scheduler entry for the staging site, running as
user `forge`, every minute:

```
php artisan schedule:run
```

run from the site's own directory (Forge writes the full path into the command).
Nothing else: no queue worker, no daemon, no Redis, no new environment setting,
no paid service.

**Where:** Forge, server "BackOffice", the staging site. If the site has a
"Laravel scheduler" switch, turning it on is the whole step. Otherwise add a
scheduled job with the command above, user `forge`, frequency "Every Minute".

**How to tell it worked:** `php artisan schedule:list` on the server lists
`backoffice:queue-tick` every minute, and within two minutes of deployment the
manager summary shows no timer warning.

**Responsibility.** The office does not change Forge. Step 4b's report must
therefore (a) say whether the scheduler was found running on staging, with the
evidence, and (b) if it was not, give Jacob the exact Forge step, using the real
site path and Forge's current labels, checked rather than assumed. 4c repeats the
check. Until the scheduler runs, the queue still works (see the scheduler
contract); only the heartbeat warning and the tidy-up pass are missing. This is
staging only. Production does not exist and nothing here authorizes it.

## Known limits and recorded follow-ups

- Existing screens still show company time, not Location time (decision 68).
- A Lead arriving outside calling hours gets no confirmation text. That is a
  recorded future requirement (decision 72) and needs texting, a provider and
  Jacob's separate approval of a paid service.
- A Lead that leaves the queue does not come back by itself. Resuming a Deferred
  Follow-up or reopening a disqualified Lead leaves it out until a manager uses
  Put back in queue.
- Leads that existed before 4b is deployed are never in the queue.
- Changing a Location's timezone, or routing a Lead, does not move an attempt
  already scheduled; it changes when the Lead may be offered and how later
  attempts are scheduled.
- Because every newly captured Lead enters the cadence, a Lead recorded while
  staff are already speaking to the customer is due at once until that
  conversation is logged as a connected attempt.
- Queue events and the cadence state are append-only or guarded in the
  application, not by the database, like audit events (security-notes.md).
