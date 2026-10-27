# Slice 31 — Reports, the First Set: Plan

Status: **proposed on 2026-10-27, at Jacob's request ("both at once, start
with reports"; decision 195). Waits for his answers.** Written as what the
implementation agent recommends.

## Why this, now

Jacob chose to build features while he sets up production (decision 195),
starting with Reports. Every Center's Reports page is still Coming Soon,
and the Performance boards already show each month at one Location; the
product plan asks for deeper analysis behind them (product-architecture.md
4.6, 5.9, 9.7 and 18).

What each Center's Reports holds, from the plan:

- **Marketing (4.6):** Marketing and Lead Source, booking rates, spend and
  return by source and Location, referrals, product-line mix, demand by
  area, Contact Center trends, speed to lead, connection rate, missed-call
  recovery, aged leads, setter performance.
- **Sales (5.9):** NSLI, Demo Rate, Cancellation Rate, Close Rate, average
  job size, Close-on-the-Spot, Pending and Rehash, objections and Lost
  Reasons, appointments, revenue by rep, leaderboard history.
- **Finance (9.7):** P&L by default; AR Aging, Cash Collections, gross
  profit and job-cost variance, Payables, payroll and commission owed,
  Location comparison, Monthly Close.
- **Company (18):** analysis across the company and Locations.
- **Production:** named in the menu, not yet described.

## What slice 31 is, and is not

Three steps. Every report has the same frame: a range of dates, one
Location or all of yours, the figures in a table with totals, a
month-by-month view, and a download of the table as a spreadsheet file
(CSV).

| Step | Delivers |
| --- | --- |
| 31a | **The report frame and Sales > Reports.** By salesperson and in total: appointments issued, run and cancelled, Demo Rate, Cancellation Rate, sales, Close Rate, Close-on-the-Spot, average job size and sold revenue; Pending and Rehash outcomes; Lost Reasons; each month's leaderboard. |
| 31b | **Marketing > Reports.** By Lead Source and Marketing Source: spend, leads, cost per lead, appointments and booking rate, sales, close rate, sold revenue and return on spend; speed to lead and connection rate from the call attempts; month by month. |
| 31c | **Finance > Reports.** Profit and loss by Location from QuickBooks, side by side; AR Aging; cash collected by month; gross profit and job-cost variance on completed jobs; pay and commission owed but not yet paid. |

Not in slice 31, for a later slice: Production and Company Reports,
Payables, Monthly Close, referrals and campaigns (not recorded yet),
product-line mix and demand by area, and missed-call recovery (no phone
system yet).

Each figure is counted the way its Performance board already counts it, so
a report and a board for the same month and Location always agree. Sold
revenue is the basis, as the plan says (21), and each figure belongs to the
day it happened: a lead to the day it came in, an appointment to its day, a
sale to the day it was sold.

## Decisions needed

1. **What comes first.** *Recommended:* Sales, Marketing, then Finance, in
   three steps; Production and Company Reports in a later slice once Jacob
   says what Production's should hold. *Alternative:* all five at once, a
   longer slice.
2. **Who sees Reports.** *Recommended:* Owner and Leadership for every
   Location; a Location Manager for their own Location; Finance for
   Finance's Reports. Sales Consultants, Call Center agents and Production
   Managers do not; they keep their Performance boards and the
   Leaderboard. It is one new permission, "View reports", inside each
   Center's own access.
3. **Ranges of dates.** *Recommended:* This month (the default), Last
   month, This quarter, Year to date, Last 12 months, or any two days.
   Comparing two ranges side by side waits for a later slice.
4. **Downloads.** *Recommended:* every table downloads as a CSV file that
   opens in Excel or Google Sheets. Reports hold totals and staff names,
   never a customer's name, address or phone, so a download needs no extra
   permission and is not recorded in the Audit Log.

## Known limits of this plan

- Marketing figures depend on spend being entered each month (slice 25).
- Finance's profit and loss is QuickBooks' figure as of its nightly read,
  and only for Locations whose company is connected.
