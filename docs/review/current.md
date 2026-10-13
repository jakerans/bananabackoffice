# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-close-rate-demo-to-close`

**State:** one change waits for Jacob's review and his word to merge: Close
Rate as demo to close (decision 135). `main` and staging do not have it.

The fixes after slice 8 are merged, on 2026-10-13 with Jacob's word ("merge
it", decision 135): a Project named for what was sold, wins by lead ownership
in the month a proposal was accepted, corrections kept in their own month, and
the reason for a no-demo.

## Step

"Close Rate is demo to close." Decided by Jacob in decision 135: "close rate
is how many proposals were accepted by how many were created ... one project
is only calculated ... we're looking for a demo to close rate. so if we demo'd
at all by if demo had any sales at all." As built:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md),
"Close Rate is demo to close".

## What changed

- **Close Rate is now the leads won in the month over the leads demoed for the
  first time in the month.** A lead is demoed when an appointment that was run
  has a proposal.
- **A no-demo no longer lowers Close Rate.** Before, the wins were divided by
  every lead whose appointment was run, so an appointment run with nothing
  presented counted against Close Rate and against Demo Rate. Now it counts
  against Demo Rate only.
- **One lead is one demo and at most one win,** however many proposals it has.
  Three proposals with all three accepted is one win; one of three accepted is
  one win. Making more proposals cannot move the rate.
- **The line under the figure** says it: "4 leads won this month, less 1 taken
  back by a correction, against 6 leads first demoed this month."
- "How these are counted" explains demo to close.

Nothing else changed: whose win it is, which month it counts in, and
corrections are as merged. **No database change, no new permission, no new
package.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,658 tests (2,657 passed, 1 skipped) in about 400 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: passed,** with no page errors. See "Browser review" below. |
| Merge, staging | **Not done.** |

No new test files. Existing tests changed to the new rule, none weakened or
removed, in `SalesFiguresTest.php`, `PerformancePageTest.php`,
`SaleAdjustmentsTest.php` and `NoDemoReasonTest.php`. They now also prove:

- the worked month reads 1 lead won against 2 demoed, 50%, with 3 run;
- a lead run with nothing presented is in Demo Rate and not in Close Rate,
  and joins Close Rate the day a proposal is added;
- three proposals for one lead are one demo;
- a return visit where the proposal is presented again is not a second demo;
- a lead whose only proposal is marked "added in error" was never demoed.

## Architectural decisions made while building

Readings of Jacob's words, also in decision 135. Any can be changed.

- **"One project" is one lead.** Backoffice has nothing between a lead and its
  proposals that groups proposals into a job, and decision 125 says a second
  job for the same customer is its own lead. So proposals for two houses
  under one lead are one demo and at most one win.
- **A lead is demoed in the month of its first demo,** for the salesperson
  that appointment is with. The win stays where decision 134 put it: the month
  the proposal was accepted, for the salesperson who had the lead then. So a
  month is "won this month over first demoed this month", and a month of sales
  closed from earlier demos can read above 100%.
- **Lead to appointment booking rate is not added to Sales > Performance.**
  The plan keeps booking rates with Marketing and Contact Center, by channel
  (4.1, 4.6), and they are not built.

## Deviations from the approved plan

None from decision 135.

## Known limitations

- **A month with wins and no first demo shows a dash** and says why, because
  there is nothing to divide by.
- **A proposal added late changes the month of its appointment,** for Demo
  Rate and now for Close Rate too, as decision 124 says it should. A past
  month's Close Rate can therefore still move when a late proposal is added
  or one is marked "added in error". Sales and wins do not move.
- **Proposals for two different houses under one lead count once.** See the
  first reading above.

## Decisions needed from Jacob

1. **Review this change** and say whether to merge.
2. **Two houses under one lead.** Built as one demo and one win. Say if each
   house should count as its own.
3. **Lead to appointment booking rate.** Not on this page. It can be planned
   with the Contact Center's figures when those are built; say if it is wanted
   on Sales > Performance sooner.
4. **What comes next.** See "The plan check" below.

## The plan check

What the plan says could be next, none of it approved:

1. **Pending ownership and Rehash** (5.5): a proposal valid for seven days by
   default, Sales ownership ending with it, then Contact Center Rehash
   (decision 131). Today a Pending has no clock. Recommended next.
2. **Pre-production** (6.3, 6.4): rough timeframe, colors and readiness.
3. **The deposit and payments** (10.4, 10.6), which touch QuickBooks.

Each needs its own plan and Jacob's approval before anything is built.

## Browser review (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and Leads, in a real browser driven from the
command line. The salesperson's demos and wins were first counted a second
way, straight from the tables, to compare the page against.

| Step | Result |
| --- | --- |
| 1. The salesperson's month | Pass. Close Rate matched the count from the tables: "4 leads won this month, less 1 taken back by a correction, against 6 leads first demoed this month.", 50%. Demo Rate beside it read "7 of 12 run appointments have a proposal", 58%. Before this change the same month read 27%, because the leads run with nothing presented were counted against it. |
| 2. A manager, everyone at the Location | Pass. The same figures for the Location, and "How these are counted" explains demo to close. |
| 3. Last month | Pass. September: "1 lead won this month, against 1 lead first demoed this month", 100%. |
