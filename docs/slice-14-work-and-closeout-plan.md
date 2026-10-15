# Slice 14 — The Work and Its Completion: Plan

Status: **built overnight on 2026-10-15 under decision 155, with the
recommendation on each of the ten questions taken as the answer, for Jacob
to review in the morning.** Not merged. It was written as what the
implementation agent recommended after slice 13, from the plan.

It follows [product-architecture.md](product-architecture.md), sections 6.8,
7.9 and 7.10, and the stakeholder answers recorded there on the final
walkthrough and the scorecard, and builds on slices 11 to 13.

## Why this is next, from the plan

1. **A job now stops at the work order.** Slices 10 to 13 took it from the
   sale to a subcontractor with dates, a payout and a work order. The plan's
   next stretch is the work itself and its completion (6.8): "work complete,
   walkthrough, punch list if needed, punch resolved, customer signoff".
2. **The plan names what happens when work runs over** (7.9) **and the
   scorecard at closeout** (7.10). Both need work that has started and
   finished, which nothing records yet.
3. **It needs nothing that is not built.** No payment, no QuickBooks, no
   uploaded file and no message to anyone.
4. **What else could have been next.** Average Job Size and
   Close-on-the-Spot Rate (5.2) are small and can be done at any time.
   Document storage needs the private storage of decision 33 set up first.
   The deposit and payments need the QuickBooks check. A subcontractor
   portal would be the first sign-in from outside Banana, with security
   questions of its own.

## What the plan already settles

| The plan says | Where |
| --- | --- |
| Customer-facing completion: work complete, walkthrough, punch list if needed, punch resolved, customer signoff, final balance collected. | 6.8 |
| The final walkthrough: internal inspection first, then the customer walkthrough and a 100%-satisfied signature; the Production Manager follows up and gives final-payment instructions. | 6.8 |
| A qualified subcontractor may conduct the walkthrough at the Production Manager's discretion after at least five completed Projects and a 4.5+ rating; responsibility stays with the Production Manager. | 6.8 |
| Signed duration is tracked. A contractor-caused overrun is told apart from weather, the customer or another legitimate cause. | 7.9 |
| The scorecard at closeout: quality, schedule adherence, communication, cleanup and professionalism, scope adherence, callback and punch burden, customer review obtained, yard signs, progress photos, overall rating, notes. The subcontractor sees all of it and a "feedback for the subcontractor" field, never the internal notes. | 7.10 |
| The color record must stay easy to find years later. | 6.8 (built in slice 10) |
| The PM cannot financially close before job costing is complete. | 6.8, 6.9 |

## What slice 14 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 14a | **The work in progress.** Work started and finished for each package; a job In progress and Work complete; work running over its dates flagged, and extended with whose cause it was. | Plan 6.8, 7.9 |
| 14b | **The walkthrough and signoff.** The internal inspection, the customer walkthrough, a punch list, and the customer's 100%-satisfied signoff. | Plan 6.8 |
| 14c | **The scorecard.** The Production Manager scores the subcontractor for each finished package; their page shows every scorecard and their average. | Plan 7.10, 7.11 |

Not in slice 14, each needing its own plan and approval:

- **Financial close:** job costing, receipts, Finance reconciliation, final
  gross margin, the final balance and payout or commission eligibility
  (6.8, 6.9). They need Finance's side and QuickBooks.
- **Brand Ambassador obligations** (6.10): the Brand Ambassador packages
  are not built yet.
- **Removing a subcontractor mid-job,** a completion package and a partial
  payout (7.9). This slice records overruns and their cause; replacing a
  crew that fails comes with payables.
- **Photos** of the work and the signed walkthrough: Backoffice stores no
  files yet.
- **Warranty** (16).
- **Any message** to a customer or a subcontractor, including review
  requests.

## Step 14a — The work in progress

### What a person sees

- **"Work started"** on a package given out, with the day; **"Work
  finished"**, with the day. The job becomes "Production: In progress" when
  any package has started, and "Work complete" when every package has
  finished.
- **Running over:** a package not finished by the working day after its last
  day is marked "Running over", on the job, the Schedule page and the
  Workspace.
- **Extending:** a new last day and whose cause it was: the subcontractor,
  the weather, the customer, or another reason given in words. The earlier
  dates are kept, as for a reschedule.
- **The Schedule page and the Workspace** show work in progress and work
  complete.

### What it stores

On each package: the day work started, the day it finished, and every
extension with its cause. On the Project: the production states In
progress and Work complete.

## Step 14b — The walkthrough and signoff

### What a person sees

- **"Internal inspection done"**, with the day and who did it, once the work
  is complete.
- **The customer walkthrough:** the day, who conducted it (the Production
  Manager, or a subcontractor at the Production Manager's discretion), and a
  punch list of what needs touching up, each item with who fixes it.
- **Punch items** are ticked off as they are done.
- **The customer's signoff:** who signed, how, the day, and that they are
  100% satisfied, once every punch item is done. The job becomes
  "Production: Signed off". A customer not fully satisfied is recorded with
  what is wrong, and more punch items can follow.

### What it stores

The inspection, the walkthrough, each punch item and when it was done, and
the signoff. On the Project: the production state Signed off.

## Step 14c — The scorecard

### What a person sees

- **A scorecard for each finished package**, filled in by the Production
  Manager: quality, schedule adherence, communication, cleanup and
  professionalism, scope adherence, callback and punch burden, each from 1
  to 5; customer review obtained, yard signs and progress photos, each yes
  or no; an overall rating; "feedback for the subcontractor"; and internal
  notes.
- **The subcontractor's page** lists their scorecards, their average overall
  rating and how many jobs they have completed, the first figures towards
  VIP eligibility (7.11).
- **A scorecard is not changed after it is saved;** a correction is a note
  added to it.

### What it stores

Each scorecard, with who filled it in and when.

## Who may do what

No new permission.

| What | Who |
| --- | --- |
| Record work started, finished and extended; the inspection, walkthrough, punch items and signoff | The Project's Production Manager, or anyone with `projects.manage` there. The same rule as pre-production. |
| Fill in a scorecard | The same people, who may also see subcontractors (`subcontractors.view`). |
| See scorecards | Anyone with `subcontractors.view` at one of the subcontractor's Locations. |
| See the job's progress, punch list and signoff | Everyone who may see the Project. |

## Audit and Activity

Every change is an audit event saved with it. In the customer's Activity:
work started, work finished, extended, the walkthrough, the signoff. Punch
items and scorecards are in the audit record only.

## Tests

As slices 8 to 13 had: who may and may not, as a matrix of roles and
Locations; every refusal; nothing half-saved; history kept exactly; the
production states moving in step; and the migrations on MySQL 8.4, with
every stored name and key name checked against its limit.

## Decisions needed

**Taken as recommended under decision 155, for Jacob to confirm or change.**
The questions are kept below as they were asked.

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The production states.** *Recommended:* three more after Scheduled: In
   progress (any package started), Work complete (every package finished),
   Signed off (the customer signed off). Financially closed comes with job
   costing. *Alternative:* fewer states, with progress shown only on each
   package.
2. **Running over.** *Recommended:* marked from the working day after the
   last scheduled day if the work is not finished; a mark, not a message.
3. **Extending.** *Recommended:* a new last day and the cause: the
   subcontractor, the weather, the customer, or another reason in words.
   Only "the subcontractor" will count against them in the scorecard and VIP
   figures.
4. **A failing crew** (removing them, a completion package, a partial
   payout). *Recommended:* not in this slice; it comes with payables. Until
   then the award can be taken back, with whose doing it was.
5. **The inspection checklist.** The plan names the PCA checklist.
   *Recommended:* record that the internal inspection was done, the day and
   who, without the checklist's items, until you send the checklist you use;
   it can then be added as items to tick. *Alternative:* a short checklist
   written now.
6. **Who may conduct the walkthrough.** *Recommended:* the Production
   Manager, or a subcontractor chosen by them, recorded by name. Backoffice
   shows whether the subcontractor meets "five completed Projects and a 4.5
   rating", which it can count once scorecards exist, but does not refuse.
7. **The punch list.** *Recommended:* items in words, each with who fixes it
   (the subcontractor or Banana) and the day it was done. The customer
   cannot sign off while one is open.
8. **The signoff.** *Recommended:* who signed, how (in person, phone, text,
   email), the day, and "100% satisfied" ticked. A customer who is not fully
   satisfied is recorded with what is wrong, and the job stays at Work
   complete.
9. **The scorecard.** *Recommended:* the plan's measures, each 1 to 5; the
   three yes-or-no items; an overall rating from 1 to 5; feedback for the
   subcontractor; internal notes. Filled in once per finished package, not
   changed afterwards; a correction is a note added.
10. **No message** to customers or subcontractors, including review
    requests. *Recommended:* none.

To start, Jacob needs to approve the plan, or step 14a alone, and answer
questions 1 to 4. Questions 5 to 8 are needed before 14b, and 9 before 14c.

## Known limits of this plan

- **Nothing is signed by the customer in Backoffice.** The signoff records
  that they signed or said so, until there is a customer portal or stored
  documents.
- **No photos** of the work or the walkthrough are kept.
- **A job is not financially closed** at the end of this slice: that needs
  job costing and the final payment.
