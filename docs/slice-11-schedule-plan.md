# Slice 11 — Work Packages and the Exact Schedule: Plan

Status: **approved on 2026-10-14 (decision 144) in three steps, each stopping
for Jacob's review, with the recommendation on all ten questions. Step 11a was
built and merged on 2026-10-14 (decision 145); see "Step 11a as built". Step
11b was built and merged on 2026-10-14 (decision 146); see "Step 11b as
built". Step 11c was built and merged on 2026-10-14 (decision 147); see "Step
11c as built". Slice 11 is complete.** It was written as what the implementation agent recommended
after slice 10, from the plan.

It follows [product-architecture.md](product-architecture.md), sections 6.2,
6.5, 6.6, 7.8 and 20, and builds on slice 10
([slice-10-pre-production-plan.md](slice-10-pre-production-plan.md)).

## Why this is next, from the plan

1. **The plan's own order.** Pre-production gives "rough timeframe before
   exact schedule" (6.3). Slice 10 ends with a list called "Ready to
   schedule". Nothing can be done with a job on it yet.
2. **The schedule's states are already written** (6.5): rough timeframe,
   proposed windows, crew-selected window, customer-confirmed schedule,
   rescheduled with reason and history. The first is built. This slice builds
   the second, fourth and fifth.
3. **Dates belong to a Work Package, not straight to the job** (6.6: a Work
   Package has "proposed date windows"; "large/multi-crew projects may split
   into multiple packages"). The plan names the Work Package model as a
   foundation to build early (26). Putting dates on the Project now would
   have to be moved later.
4. **It needs nothing that is not built.** No payment, no QuickBooks, no
   subcontractor roster and no message to a customer.
5. **What else could have been next, and why not first.**
   - *The deposit and payments* (10.4, 10.6, 22) need the QuickBooks
     integration check, which has not been done.
   - *Subcontractors* (7): recruiting, compliance, the roster and the
     marketplace are a large area of their own. They attach to Work Packages,
     so Work Packages come first.
   - *Average Job Size and Close-on-the-Spot Rate* (5.2) are small and can be
     done at any time, before or after this.

## What the plan already settles

| The plan says | Where |
| --- | --- |
| Dates are firm reservations once confirmed, but a Production Manager may reschedule by hand. | 6.5 |
| A reschedule keeps its reason and its history. | 6.5 |
| Exact dates are usually settled three to six weeks before the start. | 6.3 |
| A Project has one or more Work Packages. A large job may be split. | 6.6 |
| The Production Manager offers windows the customer has agreed to; a subcontractor picks one; the Production Manager reconfirms with the customer. | 7.8 |
| Production works from its Workspace. Schedule is its own page in the Production Center. | 6.2; navigation |
| Utilization is scheduled crew-days over available crew-days, for six weeks, by Location. | 6.5; decision 133 |

## What slice 11 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 11a | **Work Packages and proposed windows.** A job that is ready to schedule gets its package of work and the date windows offered to the customer. | Plan 6.5, 6.6 |
| 11b | **The confirmed schedule.** The customer's confirmation of one window, the job shown as Scheduled, and rescheduling with a reason and its history. | Plan 6.5 |
| 11c | **Production > Schedule.** The page that shows what is booked, week by week, and which ready jobs need dates soon. | Plan 6.2, 6.5 |

Not in slice 11, each needing its own plan and approval:

- **Crews and subcontractors** (7): who does the work, the "crew-selected
  window" state, payout, the marketplace, awards and signed agreements.
- **Utilization** (decision 133). It counts crews and their available days,
  which do not exist yet.
- **Any message to the customer:** a schedule confirmation, a reminder, the
  portal. No message to a customer has been authorized in any slice.
- **Work orders, change orders, closeout, job costing, the deposit.**

## Step 11a — Work Packages and proposed windows

### What a person sees

- **On a job that is ready to schedule,** a part called "Work and schedule".
  It starts with one Work Package for the whole job, named after the job,
  with its scope taken from the handoff's "What is included".
- **The Production Manager writes for each package:** the scope in words, how
  many working days it is expected to take, and the smallest crew it needs.
- **A large job can be split** into more packages, each with its own scope
  and days. A package with no dates yet can be removed.
- **Proposed windows:** for each package, one or more windows the customer
  has been offered, each a first and a last working day.
- **The Workspace** shows on each ready job whether windows have been
  proposed.

### What it stores

Work Packages, each belonging to a Project; and each package's proposed
windows. Payout, photos, release and award are not stored yet.

### Step 11a as built (2026-10-14)

**Merged into `main` on 2026-10-14** with Jacob's word ("go", decision 145).
Built as planned above.
What a reader of the code should know:

- **Two new tables:** `work_packages` (name, scope, working days, smallest
  crew, its place in the list) and `work_package_windows` (first and last
  day, who offered them). One action writes both:
  `App\Actions\Projects\ChangeWorkPackages`.
- **"Work and schedule"** is a part of its own on the Project page
  (`pages::projects.work`). It shows on a job that is ready to schedule, and
  stays in view, read only, on a job that has work and stops being ready.
- **The first package starts from the sale:** its name is the proposal's name
  and its scope is the handoff's "What is included". Both can be changed.
  Working days and the smallest crew are typed. "Split off another package"
  adds more, which start empty.
- **A window** is a first and a last day. It is refused in the past (by the
  Location's calendar), more than two years ahead, the wrong way round,
  longer than 180 days, with no weekday in it, or the same as one already
  offered. Up to ten per package. It says how many working days it holds
  (Monday to Friday) and points out one shorter than the work needs; it does
  not refuse it.
- **Removing a package** removes the dates offered for it. Nothing is booked
  yet, so nothing booked is lost.
- **Who may:** `ProjectPolicy::managePreProduction`, as for the rest of
  pre-production, and only while the job is ready to schedule (question 4).
  Unticking the welcome call stops any change to the work until it is ticked
  again.
- **In the customer's Activity:** dates offered, and dates withdrawn, each
  with the package and the days. Adding, changing or removing a package is in
  the audit record only.
- **Production > Workspace:** each job under "Ready to schedule" says "No dates
  offered yet" or how many windows are offered.

Readings made while building, for Jacob to change if wrong:

- **Withdrawn dates are in the customer's Activity too,** not only offered
  ones, so the Activity never shows an offer that was taken back without
  saying so.
- **A window shorter than the work's days is allowed,** with a warning: the
  customer may agree to a crew working a weekend.
- **Nothing is written on a job that is not ready,** packages included, not
  only windows.

## Step 11b — The confirmed schedule

### What a person sees

- **"The customer confirmed":** the Production Manager picks one proposed
  window and records who confirmed it, how (in person, by phone, by text or
  by email) and on which day, as the colors approval is recorded.
- **The job becomes "Production: Scheduled"** once every one of its packages
  has confirmed dates. The Workspace gains a third list, "Scheduled", with
  the dates.
- **Rescheduling:** new dates, with a reason. What the dates were is kept and
  shown, as it is for the rough timeframe.
- **The salesperson who sold it is told in Backoffice** when the dates are
  confirmed and when they change, and sees the dates on the Lead, so they can
  answer the customer.

### What it stores

On a package: its confirmed first and last day, who confirmed and how, and
every earlier set of dates with the reason it changed. On the Project: the
production state "Scheduled".

### Step 11b as built (2026-10-14)

**Merged into `main` on 2026-10-14** with Jacob's word ("Merge and
continue.", decision 146). Built as planned above.
What a reader of the code should know:

- **Stored:** each package's confirmed first and last day
  (`work_packages.scheduled_first_day`, `scheduled_last_day`), and every set
  of dates it has had in `work_package_schedules`, which refuses to be
  changed or deleted. The production state gained "Scheduled".
- **"The customer confirmed"** is on each offered window. It asks who said
  yes, how (in person, by phone, by text or by email, the same four as the
  colors) and on which day, starting on the job's contact and today. Dates
  that have started cannot be confirmed.
- **Scheduled** is worked out after every change: a job is Scheduled when it
  has packages and every one has confirmed dates. Until then the part says
  "Partly scheduled". Adding a package to a Scheduled job takes it back to
  pre-production until that package has its dates too. Changing the
  Production Manager keeps a Scheduled job Scheduled.
- **Work with confirmed dates** offers no more dates and cannot be removed;
  it is rescheduled.
- **Rescheduling** asks for the new dates, the reason, and who agreed, how
  and when. The earlier dates stay listed under the new ones, with who
  changed them, when, and why.
- **The salesperson is told in Backoffice** when the job becomes Scheduled
  and on every reschedule of a Scheduled job. The notice names the days and
  nothing about the customer or the address. The Lead shows "Scheduled:
  [days]. Confirmed with the customer." in place of the rough timeframe.
- **In the customer's Activity:** dates confirmed and rescheduled, each with
  the package and the days, and the reason for a reschedule.
- **Production > Workspace** has a third list, "Scheduled", with the job's
  dates from the first day of its first package to the last day of its last.
  A scheduled job is no longer under "Ready to schedule".

Readings made while building, for Jacob to change if wrong:

- **A reschedule records who agreed to the new dates,** as the first
  confirmation does, because the dates stay "customer-confirmed" (6.5).
- **The salesperson is not told when only part of a split job is
  confirmed,** only when the whole job is Scheduled.
- **Confirmed dates cannot be taken away** without new ones. Putting a job
  back to "no dates" is not in the plan; it can be added if it is needed.
## Step 11c — Production > Schedule

### What a person sees

- **Schedule becomes a live page** in the Production Center: the Location's
  booked work week by week, each job with its dates, its Production Manager
  and where its colors stand.
- **Two jobs on the same days are both shown.** Nothing is refused: there are
  no crews yet to be double-booked.
- **"Needs dates soon":** a ready job with no confirmed dates whose rough
  timeframe starts in less than three weeks is flagged, on this page and in
  the Workspace.

### What it stores

Nothing new.

### Step 11c as built (2026-10-14)

**Merged into `main` on 2026-10-14** with Jacob's word ("merge and continue",
decision 147). Built as planned above.
What a reader of the code should know:

- **Production > Schedule is live** (`pages::production.schedule`, route
  `production.schedule`), for anyone with Production access, showing the
  Locations where they may see Projects.
- **Six weeks at a time,** from this week's Monday in the Location's
  calendar: the six weeks of the utilization measure (decision 133).
  "Earlier" and "Later" move six weeks; "This week" goes back. The first
  Monday shown is kept in the address, so a view can be shared.
- **Each week lists the work with confirmed dates** that falls in it: the
  dates, the job, the part's name when the job is split, working days and
  smallest crew, the customer, the Production Manager and where the colors
  stand. Work that spans weeks is listed in each. Two jobs on the same days
  are both listed. Nothing on the page changes anything.
- **"Needs dates soon"** (`App\Support\Projects\NeedsDatesSoon`): a job ready
  to schedule, not Scheduled, whose rough timeframe starts less than three
  weeks from today in its Location, or has already started. It is listed at
  the top of the Schedule page and marked on its row under "Ready to
  schedule" in the Workspace. A split job with only some parts confirmed is
  not Scheduled, so it can still be flagged.

Readings made while building, for Jacob to change if wrong:

- **Parts of a split job that are confirmed show on the Schedule** even
  before the whole job is Scheduled, because those days are booked with the
  customer.
- **"Needs dates soon" is a mark, not a message,** like "falling behind".
  Nobody is notified.

## Who may do what

No new permission.

| What | Who |
| --- | --- |
| See packages, windows, dates and the Schedule page | Anyone with `projects.view` at the Location. The Schedule page also needs access to the Production Center. |
| Write packages and windows; record the customer's confirmation; reschedule | The Project's Production Manager, or anyone with `projects.manage` there. The same rule as pre-production. |
| Nothing | The salesperson who sold it reads the dates and writes none. |

## Audit and Activity

Each is an audit event saved with its change. In the customer's Activity:
windows proposed; schedule confirmed; rescheduled, with the reason. Changes
to a package's scope, days or crew size are in the audit record only.

## Tests

As slices 8 to 10 had: who may and may not, as a matrix of roles and
Locations; every refusal; nothing half-saved; history kept exactly; each job
in the right Workspace list; the Schedule page showing only its Location; and
the migrations on MySQL 8.4, with every new stored name checked against its
column.

## Decisions needed

**Answered by Jacob on 2026-10-14 (decision 144): "Yes, all
recommendations."** The questions are kept below as they were asked.

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **Do dates belong to a Work Package or to the job?** *Recommended:* to a
   Work Package. Every job starts with one package for the whole job, so a
   normal job reads as "the job's dates"; a large job can be split.
   *Alternative:* dates on the job now, Work Packages later, and the dates
   moved then.
2. **What a Work Package holds in this slice.** *Recommended:* a name, the
   scope in words, expected working days and the smallest crew. Payout waits
   for subcontractors, because it has privacy and margin rules of its own
   (7.5, 6.7).
3. **What a window is.** *Recommended:* a first and a last working day, both
   chosen. *Alternative:* a first day only, with the last worked out from the
   expected working days.
4. **Must a job be "Ready to schedule" before windows are proposed?**
   *Recommended:* yes. That is what the list is for.
5. **Must colors be approved before dates are confirmed?** *Recommended:* no,
   as decision 141 answered for readiness. Approved colors will be required
   before a work order goes to a crew, in the slice that builds work orders.
   *Alternative:* yes, no confirmed dates without approved colors.
6. **With no crews in Backoffice yet, how is "who is doing it" kept?**
   *Recommended:* it is not, in this slice. The plan's "crew-selected window"
   step is skipped until subcontractors exist, and the Production Manager
   confirms with the customer directly. *Alternative:* a typed crew name on
   the package, to be replaced later.
7. **Rescheduling.** *Recommended:* the Production Manager or a manager, with
   a reason every time; the old dates kept; the salesperson told in
   Backoffice.
8. **When does a ready job with no dates get flagged?** The plan says dates
   are usually settled three to six weeks before the start. *Recommended:*
   flagged as "needs dates soon" when its rough timeframe starts in less than
   three weeks. *Alternative:* another number of weeks, or a setting.
9. **The Schedule page.** *Recommended:* a plain list by week. A calendar
   that is dragged around can come when there are crews to move work between.
10. **No message to the customer** in this slice. *Recommended:* none.

To start, Jacob needs to approve the plan, or step 11a alone, and answer
questions 1 to 4. Questions 5 to 7 are needed before 11b, and 8 and 9 before
11c.

## Known limits of this plan

- **Nothing tells the customer anything.** The confirmation is a call or a
  text the Production Manager makes; Backoffice only records it.
- **A confirmed date reserves no crew.** Two jobs can be booked on the same
  days, and utilization cannot be shown, until crews exist.
- **Working days are Monday to Friday.** Public holidays are not known.
- **The customer's confirmation is somebody's word** that the customer
  confirmed, until the portal exists.
