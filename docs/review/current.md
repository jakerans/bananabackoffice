# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-24 · **Branch:** `checkpoint-slice-20-compensation`

**State:** slice 20, variable compensation, is built straight through
(decision 172) and **waits for Jacob's review. Not merged.**

## What to review

Plan and "as built": [../slice-20-compensation-plan.md](../slice-20-compensation-plan.md).

- **20a:** pay plans, effective from a day; each sold job's commission for
  its salesperson: projected at the sale, the advance on straight
  commission, the final from the final gross margin at the financial close,
  clawbacks; manual adjustments; Finance > Payroll and "My pay".
- **20b:** the Production Bonus, settled each month once the next month is
  over.
- **20c:** Finance finalizes each month that is over for payroll, downloads
  it, and marks it paid with payroll's reference.

## Decisions

Answered (decision 173): a month with no job closed does not hold the
Production Bonus; an advance stands after a reversed deposit until the
final commission or a clawback; payroll IDs are added now. Nothing else is
open.

## Browser review (local, fictitious data)

Done with Playwright: Finance put Sam and Remy on plans, reopened and closed
sample job 1 again, added an adjustment; Sam and Remy each saw "My pay";
Sam was refused Finance > Payroll. October cannot be finalized until it is
over; the tests cover finalizing and paying.

Separately: at phone width the top bar scrolls sideways on every page. It
predates this slice and is offered as its own task.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
