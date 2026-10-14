# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-11c-schedule-page`

**State:** **slice 11, step 11c (Production > Schedule and "needs dates
soon") is built and waits for Jacob's review. It is not merged.** It is the
last step of slice 11. Step 11b was merged before it (decision 146).

## What changed

Plan: [../slice-11-schedule-plan.md](../slice-11-schedule-plan.md), "Step
11c" and "Step 11c as built".

- **Production > Schedule is a live page.** It shows one Location's booked
  work six weeks at a time, from this week: for each week, every piece of
  work with confirmed dates, with the job, the days, the crew size, the
  Production Manager and where the colors stand. "Earlier", "Later" and
  "This week" move between weeks. Two jobs on the same days are both listed.
- **"Needs dates soon":** a job that is ready to schedule, has no confirmed
  dates, and whose rough timeframe starts in less than three weeks. It is
  listed at the top of the Schedule page and marked in the Workspace.
- **Nothing new is stored.** No migration, no new permission.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 13 are new, in
  `tests/Feature/Projects/SchedulePageTest.php`.
- They cover: the page is live and refused without Production access; work
  listed in every week it covers; two jobs on the same days; a split job
  listed by part, with an unconfirmed part left out; moving between weeks,
  including a day that is not a Monday or does not exist; only the
  Location's own jobs and no cancelled ones; the three-week line on both
  sides; the flag going when a job is scheduled or stops being ready, and
  coming back when new work has no dates.
- Both GitHub jobs: see the chat report.

## Browser review (done by the agent, on the local site, fictitious data)

As the sample Production Manager:

1. "Schedule" is in the Production Center menu and opens on this week:
   Oct 12 to Nov 22.
2. One sample job, given a rough start of Oct 26 for the review, is listed
   under "Needs dates soon", and marked in the Workspace.
3. "Later" twice showed January and February: the job scheduled in step
   11b's review, its one-day part on Jan 12 and its six-day part from Feb 1
   to Feb 8, listed in both weeks it covers.
4. "This week" came back to Oct 12.
5. At a phone's width the Schedule page fits. The header above it is wider
   than a phone screen on every page; that is the known header issue, not
   this page.

## Architectural decisions made

- **"Needs dates soon" is one class** (`NeedsDatesSoon`), used by the
  Schedule page and the Workspace, with a test holding its database question
  and its single-job answer together.

## Deviations from the approved plan

None. Two readings, in "Step 11c as built", for Jacob to change if wrong:

- Confirmed parts of a split job show on the Schedule before the whole job
  is Scheduled.
- "Needs dates soon" is a mark; nobody is notified.

## Known limitations

- No crews: nothing stops two jobs on the same days, and utilization cannot
  be shown yet.
- Public holidays are not known.

## Plan check after slice 11

Against [../product-architecture.md](../product-architecture.md), 6.5, 6.6,
7 and 20. Slice 11 built what it set out to: Work Packages, dates offered,
the customer's confirmation, Scheduled, rescheduling with its history, and
the Schedule page. Still not built from 6.5: the crew-selected window and
utilization, which both need crews.

**What the plan says is next: subcontractors (section 7).** Crews are what
everything after scheduling waits for: the crew-selected window (6.5, 7.8),
utilization (decision 133), payout and work orders (6.6), and approved
colors before a work order (decision 141). The first part would be the
roster and onboarding (7.1 to 7.3): who the subcontractors are, their crew
size and status, and the base compliance checklist (W-9, general
liability, workers' compensation, the agreement, the New Mexico waiver).
Keeping their documents needs private file storage, which Backoffice does
not have yet; that is a decision of its own.

The other candidates:

- **Average Job Size and Close-on-the-Spot Rate** (5.2): small, ready at any
  time.
- **The deposit and payments** (10.4, 10.6, 22): they need the QuickBooks
  integration check first.

If Jacob says to continue, the next thing is a proposed plan for the
subcontractor roster and onboarding, written for his approval before
anything is built.

## Decisions needed

1. **Merge step 11c into `main`?** Slice 11 is then complete.
2. **What next:** subcontractors (recommended), the two Sales figures, or
   something else?
