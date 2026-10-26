# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-25 · **Branch:** `checkpoint-slice-24-refunds-credits`

**State:** slice 24, Refunds, Credits and Write-offs, is built straight
through (decision 181) and **waits for Jacob's review. Not merged.**

## What to review

Plan and "as built": [../slice-24-refunds-credits-plan.md](../slice-24-refunds-credits-plan.md).

- **24a and 24b:** the job's new **Credits, refunds and write-offs** card:
  a credit or refund is asked for by one person and decided by another; an
  approved credit lowers what is owed and the revenue; a refund counts once
  Finance records it paid back.
- **24c:** a write-off of a balance with Collections, proposed by Finance
  and approved by a manager, so the job can close; below gross margin; the
  salesperson's commission on it taken back. Receivables lists what waits;
  Finance > Performance shows the month's figures.

## Decisions

None open.

## Browser review (local, fictitious data)

Done with Playwright on sample job 1, which is paid in full and closed:
Remy's credit was refused because Finance had approved the job costing;
Remy asked for a $100 refund; Finley saw it on Receivables, approved it on
the job and recorded it paid back; Finance > Performance showed $100 of
refunds this month. Write-offs need a balance 30 days with Collections,
which the local data does not have; the tests cover them.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
