# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-10c-readiness`

**State:** **slice 10, step 10c (readiness) is built and waits for Jacob's
review. It is not merged.** It is the last step of slice 10. A proposed plan
for the next slice is with it, for approval or change.

## What changed

Plan: [../slice-10-pre-production-plan.md](../slice-10-pre-production-plan.md),
"Step 10c" and "Step 10c as built".

- **A job is "ready to schedule"** when four things are recorded: the handoff
  from Sales is complete, a Production Manager is assigned, the welcome call
  is ticked, and a rough timeframe is set. Nobody ticks "ready". It is worked
  out from the record each time it is shown, so it cannot be wrong about it.
- **Colors are not one of the four** (decision 141, question 8). They are
  shown beside them, with "Not needed to schedule the job."
- **On the Project,** a new card at the top, "Ready to schedule?": "Getting
  ready: 2 of 4" or "Ready to schedule", the four lines with what is recorded
  for each, what is still needed, and the colors on a line of their own.
  Nothing in it is a button.
- **Production > Workspace** has two lists where "Pre-production" was:
  "Getting ready", each job with what it still needs, and "Ready to
  schedule". Each job says how long it has been in pre-production.
- **Nothing new is stored.** No migration, no new permission, no new audit
  event.

## Tests and checks

- `composer test`: formatting, PHPStan and 2,834 tests (2,833 passed, 1
  skipped, as before). 21 are new, in `tests/Feature/Projects/ReadinessTest.php`.
- They cover: the check against eight worked examples; colors never standing
  in for one of the four; unticking the call taking readiness away; the two
  Workspace lists agreeing with the checklist, with no job in both or in
  neither; the count of time in pre-production surviving a change of
  Production Manager; the checklist for a reader, with nothing to press; a
  cancelled job and a job at another Location in neither list.
- Three existing tests were changed to follow the screen: the Workspace's
  first line, the list's new name, and one job that is now under "Ready to
  schedule".
- Both GitHub jobs: see the report in the chat for this push.

## Browser review (done by the agent, on the local site, fictitious data)

Signed in as the owner and viewed as the sample Production Manager, the
sample salesperson and the owner.

1. Workspace as the Production Manager: four jobs under "Getting ready", one
   under "Ready to schedule", each with how long it has been in
   pre-production; the job nobody has called for a week still says "Falling
   behind".
2. A job with no call and no timeframe: "Getting ready: 2 of 4", two lines
   ticked, "Still needs: the welcome call, a rough timeframe."
3. Ticked the welcome call: "3 of 4". Set the timeframe: "Ready to schedule",
   with the colors still "not approved yet: not started".
4. Back in the Workspace the job had moved to "Ready to schedule".
5. Unticked the call: "3 of 4" again, and the job was back under "Getting
   ready".
6. The owner saw the same two lists with "Change" on every job. A cancelled
   job had no checklist.
7. The salesperson saw the checklist on their sold job and was offered
   nothing in it.
8. At a phone's width neither the checklist nor the lists ran off the screen.

To look yourself: Production > Workspace at Northside, then open any job.

## Architectural decisions made

- **One class answers "is it ready?"** (`App\Support\Projects\Readiness`),
  for the Project page and for the Workspace's two lists.
- **No stored state for readiness,** and the production state stays
  "Pre-production" for both lists.

## Deviations from the approved plan

None. Three readings, in "Step 10c as built", for Jacob to change if wrong:

- The checklist shows from the sale on, not only once a Production Manager
  has the job.
- How long a job has been in pre-production is shown to everyone who sees the
  list, not to managers only.
- Nobody is notified when a job becomes ready.

## Known limitations

- "Ready to schedule" is a list and nothing more: a job on it cannot be given
  dates yet. That is the next slice.
- Time in pre-production counts calendar time, weekends included.

## Plan check after slice 10

Against [../product-architecture.md](../product-architecture.md), 6.2 to 6.8
and 20. Slice 10 built everything it set out to: the welcome call, the rough
timeframe, the Color Specification and readiness. Still not built from
pre-production (6.3): the confirmation email or text, the Coordinator and the
deposit-paid handoff call. None was in slice 10.

**What the plan says is next: the exact schedule (6.5),** on Work Packages
(6.6). The proposed plan is
[../slice-11-schedule-plan.md](../slice-11-schedule-plan.md), in three steps:

- **11a:** Work Packages and the date windows offered to the customer;
- **11b:** the customer's confirmation, the job shown as Scheduled, and
  rescheduling with a reason and its history;
- **11c:** Production > Schedule as a live page.

It leaves out crews and subcontractors, utilization, and any message to the
customer. The other candidates, and why they are not first, are in the plan:
the deposit and payments (they need the QuickBooks check), subcontractors
(they attach to Work Packages), and the two small Sales figures still missing.

## Decisions needed

1. **Merge step 10c into `main`?**
2. **Approve the slice 11 plan,** or step 11a alone, or choose something else?
   It asks ten questions, each with a recommendation. "Follow your
   recommendations" is a complete answer. The ones that shape it most:
   - dates belong to a Work Package, and every job starts with one;
   - colors need not be approved before dates are confirmed;
   - nothing about crews in this slice.
