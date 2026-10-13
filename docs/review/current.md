# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-sales-figures-decision-134`

**State:** the fixes Jacob approved after slice 8 (decision 134) are built and
wait for his review and his word to merge. `main` and staging do not have
them. Slice 8 itself is merged: both GitHub jobs passed on the merge, and
staging deployed it with every migration run.

## Step

"The fixes after slice 8", one step. Approved by decision 134: "merge it. make
the fixes - especially close rate - we work off lead ownership - so close rate
is based on who created the proposal and when it was accepted". From the plan:
[../product-architecture.md](../product-architecture.md), 5.2 and 5.3;
decisions 133 and 134. As built:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md)
and [../slice-8-projects-plan.md](../slice-8-projects-plan.md), each under
"Changed after slice 8".

Objective: Sales > Performance counts the way Sales Leadership decided, a
month that has passed stops changing, a no-demo says why, and a Project is
named for what was sold.

## What changed

**Close Rate works off lead ownership.**

- A win belongs to the salesperson who had the Lead when the proposal was
  accepted, and counts in the month it was accepted (marked Sold).
- It is divided by the Leads whose first appointment was run in that month,
  as before. The card reads "3 leads won this month, against 11 leads first
  run this month."
- One Lead is one win. A second proposal sold to it later adds revenue, not a
  second win.
- Sold Revenue follows the same salesperson and the same month.

**A month that has passed is not rewritten.**

- A sale a manager corrects in a later month stays in the month it was made.
  The amount, and the win if it was the Lead's only sale, come off the month
  it was corrected in, and the card says so: "$4,000 was taken back by 1
  correction this month."
- The same for a Lead Issued that is taken back later (booked in error, or
  should never have been booked): "21 handed to Sales this month, less 3 taken
  back this month."
- Behind it is a record of sales that is only ever added to: one line for a
  sale, one more for its correction. Nobody can change or delete a line.

**Why nothing was presented.**

- The after-action report asks "Why was nothing presented?" when the answer is
  "run, nothing presented". It is the salesperson's own words and is required.
- An appointment marked run from the Lead page shows "Say why nothing was
  presented" on the Proposals card, for the salesperson or a manager. It can
  be changed afterwards; what it was is kept in the audit record.
- It still counts as a no-demo whatever is written. Adding a proposal is what
  changes that, as before.

**A Project is named for what was sold:** "Back deck and rail — 3921 Oak St",
not the Lead's main service. A manager can still edit it. Projects that
already exist keep their titles.

**Database:** one new table (`sale_entries`) and four new columns on
appointments. Sales already made are written into the new table by its
migration. **No new permission, no new package, nothing sent to anyone.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,658 tests (2,657 passed, 1 skipped) in about 410 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 21:

- `tests/Feature/Sales/SaleAdjustmentsTest.php` (9): the line written for a
  sale; a manager typing the sale in still giving the salesperson the win; the
  win following whoever has the Lead when it is sold, not whoever ran the
  first visit; a sale corrected in a later month, with the earlier month
  compared before and after, and sold again; a second proposal sold later and
  one of two sales corrected; a Lead Issued taken back in a later month; the
  page showing a month of corrections; a line refusing to be changed or
  deleted; sales made before the record existed.
- `tests/Feature/Proposals/NoDemoReasonTest.php` (12): the report refused
  without the reason, with nothing saved; what is kept and audited; that it
  is still a no-demo; giving and changing it from the card, and no longer
  being asked once there is a proposal; three refusals; who may, as a matrix
  of five people; the report page.

Existing tests that changed, none weakened or removed:

- `SalesFiguresTest.php` and `PerformancePageTest.php`: the worked month now
  reads "6 issued, 1 taken back, 5 count"; a sale closed on a return visit in
  November is a November win; a sale corrected in its own month comes to
  nothing in that month.
- `ProjectFromSaleTest.php` and `ProposalPropertyTest.php`: the new titles.
- `AfterActionReportTest.php` and `AfterActionReportScreenTest.php`: "nothing
  presented" now gives its reason.

## Architectural decisions made while building

Jacob's words were short, so these are readings. They are also in decision
134. Any of them can be changed.

- **"Who created the proposal" is the salesperson who had the Lead at the
  sale.** He said "we work off lead ownership", and decision 133 says the win
  follows "the salesperson who owned the Lead when the proposal was sold". If
  a manager types the proposal or the sale in, the win is still the
  salesperson's.
- **What the wins are divided by did not change:** the Leads first run in the
  month. So a sale closed the month after its first visit is a win in the
  later month, and a month of late closes can read above 100%. A month with
  wins and no Lead first run shows a dash and says why.
- **Sold Revenue, Leads Issued and NSLI are shown after the month's
  corrections,** with the line under each saying what was taken back. A month
  with only a correction can show an amount below zero, "-$8,200".
- **A correction is counted for the salesperson of the sale it takes back,**
  not for whoever has the Lead on the day of the correction.
- **The reason for a no-demo is free text,** not a list to choose from. It is
  shown on the Lead page to anyone who can open the Lead, is not in the
  customer's Activity, and is in no notice or email.

## Deviations from the approved plan

None from decisions 133 and 134.

## Known limitations

- **Two kinds of correction still change a past month:** an appointment's run
  or no-show answer corrected later, and a proposal marked "added in error"
  later. Both move Demo Rate or the appointment counts of the month the
  appointment was in. Sales and Leads Issued do not.
- **A sale corrected before today has no correction line.** The record starts
  from the sales standing now. Staging has no sold proposals, so nothing is
  lost there.
- **The reason is not reported on anywhere yet.** It is read on the Lead. A
  list of no-demos with their reasons for a manager is not built.
- **After a refusal on the report, the red message under "Why was nothing
  presented?" stays until the next save,** like the other fields on that page.
- **The phone-width header overflow** already flagged is still there; nothing
  new on these screens overflows.
- Local database only: three fictitious Leads were added for the review, and
  the review left one sale made and one corrected.

## Decisions needed from Jacob

1. **Review this step** and say whether to merge.
2. **What Close Rate is divided by.** Built as: Leads won this month, over
   Leads first run this month. The other reading of "when it was accepted"
   would divide by something else, for one the appointments run in the month.
   Recommended: keep it as built, since decision 125 counts Close Rate for
   each Lead; say if the denominator should change.
3. **What comes next.** See "The plan check" below.

## The plan check

Nothing new was built outside the plan in this step. What the plan says could
be next is unchanged, and none of it is approved:

1. **Pending ownership and Rehash** (5.5): a proposal valid for seven days by
   default, Sales ownership ending with it, then Contact Center Rehash
   (decision 131). Today a Pending has no clock. Recommended next.
2. **Pre-production** (6.3, 6.4): rough timeframe, colors and readiness.
3. **The deposit and payments** (10.4, 10.6), which touch QuickBooks.

Each needs its own plan and Jacob's approval before anything is built.

## Browser review (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and Leads, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**

| Step | Result |
| --- | --- |
| 1. The report asks why | Pass. As the salesperson: "run", then "No, nothing was presented" showed "Why was nothing presented? (required)". Saved empty, it was refused with "Say why nothing was presented." and nothing was saved. With a reason: "Report saved: run, nothing presented.", and the Lead's Proposals card showed the reason with the salesperson's name. Done again at a phone's width. |
| 2. From the card | Pass. A Lead whose appointment was marked run earlier showed "Say why nothing was presented". Saved empty: refused. Saved with a reason: "Saved. It still counts as run with nothing presented.", shown on the card with "Change the reason". |
| 3. The Project's name | Pass. A proposal "Back deck and rail" was added and sold. Its Project was named "Back deck and rail — 3921 Oak St". The card stopped asking why nothing was presented. |
| 4. The salesperson's month | Pass. October went from 3 Leads won and $9,750 to "4 leads won this month, against 11 leads first run this month" (36%) and "$12,150", "From 4 sales this month." |
| 5. A correction made a month later | Pass. A manager corrected a sale made in September. September read exactly the same before and after: $4,000, 100%. October then read "$8,150", "From 4 sales this month. $4,000 was taken back by 1 correction this month.", and Close Rate 27% with "1 win was taken back by a correction this month." |
| 6. How it is counted | Pass. "How these are counted" explains the win's month, whose it is, and corrections. |
