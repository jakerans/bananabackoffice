# Slice 23 — Performance Boards: Plan

Status: **proposed on 2026-10-25 for Jacob's review, after slice 22 was
merged (decision 178). Nothing is built.** Written as what the
implementation agent recommends from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 22:

1. **A job is now recorded from first call to warranty,** with its money:
   payments, job costing, the financial close, pay and warranty cost
   (slices 8 to 22).
2. **The Production and Finance Centers still open on a Coming Soon page:**
   each one's primary section is **Performance** (6.1, 9.1), the working
   board the plan describes, and neither exists. The plan names the
   "reporting/performance-board framework" among the foundations to build
   first (26).
3. **The numbers they need now exist:** completed revenue and gross margin
   from the financial close (slice 19); jobs on time or running over (slice
   14); what customers owe (slice 17); job-costing accuracy (slice 19);
   warranty (slice 22); the Production Bonus month (slice 20).
4. **The Sales leaderboard** (5.8) uses only the sale records Sales >
   Performance already counts from (slice 7).

What else could be next, and why not first: **refunds, credits and
write-offs** (10.5, 10.7) affect few jobs; **My Banana**, **Referrals** and
**Painter for a Day** are large and customer-facing; **Leadership
Scorecards** (17.3) need targets nobody has set yet.

## What slice 23 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 23a | **Production > Performance.** For a Location (or all of a person's): this month's completed revenue and gross margin, jobs on time and running over, what customers owe on its jobs, job-costing timeliness and accuracy strikes, warranty cases, and the jobs at risk, each linked to its job. Each Production Manager's month: gross-margin dollars, margin, and whether it is on track for the Production Bonus. |
| 23b | **Finance > Performance.** This month's completed revenue and gross margin, cash collected, what is owed by age, jobs waiting for the financial close, warranty cost, and, for Finance and the Owner, variable pay owed and not yet paid. |
| 23c | **The Sales leaderboard.** On Sales > Performance: company-wide sold revenue by salesperson for the month, quarter and year to date; last month's, quarter's and year's champions, the defending champion marked, and the winners before. |

Not in slice 23:

- **TV Mode** (17.2): the boards first; a TV layout of them later.
- **Net or operating profit, and the P&L:** Backoffice does not hold
  overhead; QuickBooks does (9.8).
- **Production utilization:** Backoffice does not know crew capacity yet,
  so it cannot say how full it is.
- **Leadership Scorecards** (17.3): they need targets and owners first.
- **Commercial sales:** not in Backoffice (5.9).

## Who may see what

| Board | Who |
| --- | --- |
| Production > Performance | Those with Production access at the Location. |
| Finance > Performance | Those with Finance access at the Location; variable pay owed only with `compensation.manage`. |
| Sales leaderboard | Those with Sales access. It is company-wide by design (5.8). |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 23a, 23b, 23c.
2. **What "completed" means on the boards.** *Recommended:* financially
   closed this month, in the Location's time: the same jobs the Production
   Bonus counts, at their final gross margin.
3. **The jobs at risk.** *Recommended:* work running past its last day; a
   job over its budget; a final balance past due; or an open warranty case.
4. **Each Production Manager's month.** *Recommended:* Production managers
   and the Location's managers see every Production Manager's gross-margin
   dollars, margin and a status: **on track** (55% or more and above the
   $37,500 floor), **at risk** (above the floor, below 55%), or **below the
   floor**. The bonus amount is pay, so it stays on "My pay".
5. **Job-costing timeliness.** *Recommended:* the days from the customer's
   signoff to job costing submitted, averaged over the jobs submitted this
   month, with any signed-off job not yet submitted after 7 days listed.
6. **Cash collected.** *Recommended:* payments received this month that
   count (approved, not reversed), by the day received.
7. **The leaderboard's periods.** *Recommended:* calendar month, quarter
   and year in the company's time; a champion is the salesperson with the
   most sold revenue in a finished period; a tie shares it; corrected sales
   count as Sales > Performance counts them.
8. **Who sees the leaderboard.** *Recommended:* everyone with Sales access,
   company-wide, with every salesperson's name and total (5.8).

## Known limits of this plan

- **Before allocations,** gross margin reads higher than it will once
  allocations exist (slice 19).
- **The boards are read only:** everything is done on each job, as on
  Finance > Job Costing.
