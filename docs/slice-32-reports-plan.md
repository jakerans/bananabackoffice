# Slice 32 — Reports, the First Set: Plan

Status: **proposed on 2026-10-27 as slice 31, at Jacob's request ("both at
once, start with reports"; decision 195); renumbered 32 when he put
estimating first (decision 196). Waits for his answers. The day's
appointments added as its first report (decision 218). Questions answered as
recommended (decision 219); step 32a is built.** Written as what the
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

## What slice 32 is, and is not

Three steps. Every report has the same frame: a range of dates, one
Location or all of yours, the figures in a table with totals, a
month-by-month view, and a download of the table as a spreadsheet file
(CSV).

| Step | Delivers |
| --- | --- |
| 32a | **The report frame and Sales > Reports.** By salesperson and in total: appointments issued, run and cancelled, Demo Rate, Cancellation Rate, sales, Close Rate, Close-on-the-Spot, average job size and sold revenue; Pending and Rehash outcomes; Lost Reasons; each month's leaderboard. |
| 32b | **Marketing > Reports.** By Lead Source and Marketing Source: spend, leads, cost per lead, appointments and booking rate, sales, close rate, sold revenue and return on spend; speed to lead and connection rate from the call attempts; month by month. |
| 32c | **Finance > Reports.** Profit and loss by Location from QuickBooks, side by side; AR Aging; cash collected by month; gross profit and job-cost variance on completed jobs; pay and commission owed but not yet paid. |

Not in slice 32, for a later slice: Production and Company Reports,
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

## Added 2026-11-09: the day's appointments (decision 218)

Jacob asked to see how many appointments were set today, how many ran, and
how they went, and for it to live in Reports, not on a new page. Proposed as
the **first report in Sales > Reports, built first, as step 32a;** the other
Sales figures then follow as 32b, Marketing 32c and Finance 32d.

**Appointments,** for a day or any range, at one Location or all of yours:

- **Set:** appointments booked in the range, for any date, by who booked them
  and by Lead Source. One marked "booked in error" is not counted.
- **On the calendar:** appointments whose time falls in the range, split
  into Ran, No-show, Cancelled, and still to come.
- **How the ones that ran went:** Sold (how many, and for how much), Signed,
  Presented and pending, Lost, No demo (nothing presented), and Report still
  due.
- **By salesperson,** with totals; the same counting as Sales > Performance
  and the Today page, so they always agree.
- Each count opens the list of those appointments, each linking to its Lead.

### More decisions it needs

5. **Which Reports tab.** *Recommended:* Sales > Reports. Setting
   appointments is the Contact Center's work (Marketing), but the report is
   about appointments end to end, and Marketing > Reports later shows
   bookings by source and setter for longer ranges.
6. **What "set today" means.** *Recommended:* booked today, whatever day
   the appointment is for. What is on today's calendar is shown beside it.
7. **A Lead booked twice in one range** (cancelled, then booked again).
   *Recommended:* each booking counts in Set, and the second is marked a
   re-booking, so the setter's work is seen. Leads Issued on Sales >
   Performance still counts that Lead once.
8. **Ranges.** *Recommended:* question 3's list with **Today** (the default
   for this report) and **Yesterday** added; Today is each Location's own
   day.

## Step 32a as built (decision 219)

**Sales > Reports > Appointments,** at `/sales/reports`, for `reports.view`
(Owner, Leadership, Location Manager). Sales Consultants and the Contact
Center do not see it in the menu and are refused it.

- The counting is in `App\Support\Reports\AppointmentsReport`; the ranges
  in `App\Enums\ReportRange`. An appointment booked in error counts
  nowhere. A re-booking is an appointment for a Lead that had one before.
- How the ones that ran went is read as they stand now, from the estimates
  presented at them: sold if any was, pending if any is still open, lost if
  all were, no demo if none was. A month's Ran, No-show and On the calendar
  equal Sales > Performance's for the same month (tested).
- Each count, and each number in the table by salesperson, lists its
  appointments: when, the customer and address, the work and its size, the
  salesperson, who set it and when, the Lead Source, and the result.
- The three tables download as CSV, counts and staff names only.
- Tests: `tests/Feature/Sales/ReportsTest.php`. Browser review on local data:
  `.claude/tools/playwright/reviews/slice-32a.mjs`.

**Known limits:** a custom range is at most 400 days. The list is not split
into pages, so a long range lists every appointment in it. The page is
wider than a phone only because of the top bar, as on every page.

## Step 32b as built (decision 222)

**Sales > Reports > Sales figures,** at `/sales/reports/figures`, a tab beside
Appointments, for `reports.view`. This month by default, or any range.

- **Totals:** NSLI first, then sold revenue, Close Rate, Demo Rate, Leads
  issued, appointments run, average job size and Close-on-the-Spot; beside
  them Cancellation Rate, Pending Rate, pending with a return visit, Rehash
  conversion and sales.
- **By salesperson,** with the total: revenue by rep. **Month by month,**
  when the range spans more than one. **Sold revenue by month,** each
  salesperson ranked, the month's most marked: the Leaderboard's history.
- **Why leads were lost** (Leads Sales marked lost in the range, by reason)
  and **objections heard** (estimates whose objection was recorded in it).
- Every figure is counted by `SalesFigures`, the class behind Sales >
  Performance, now over any span of time (`between`), each Location in its
  own days and then added together; a month here equals that month there
  (tested). The counting is in `App\Support\Reports\SalesReport`.
- **The frame every report shares** is `App\Livewire\ReportPage`: the
  range, the Location, the month-by-month parts and the downloads.
  Appointments now uses it too, unchanged to read.
- Every table downloads as CSV: figures and staff names only.
- Tests: `tests/Feature/Sales/SalesFiguresReportTest.php`. Browser review on
  local data: `.claude/tools/playwright/reviews/slice-32b.mjs`.

## Step 32c as built (decision 222)

**Marketing > Reports,** at `/marketing/reports`, now live in the Marketing
menu, for `reports.view`. This month by default, or any range.

- **By Lead Source, or by Marketing Source** (what the customer says
  brought them, 21): spend, leads, cost per lead, connection rate,
  appointments and booking rate, cost per appointment, sales, Close Rate,
  cost per sale, sold revenue and return on spend, with the total. Spend is
  entered by Lead Source, so it is not split by Marketing Source.
- **Month by month,** when the range spans more than one.
- **Speed to lead:** from when each lead was received to its first call
  out, in bands (within 5 minutes, 5 to 30, 30 minutes to 2 hours, 2 to 24
  hours, over 24 hours, never called), with the middle time; and the
  connection rate, the leads reached over the leads received (4.2).
- Appointments, sales and sold revenue are counted by `SalesFigures` for the
  leads from each source, as Marketing > Performance counts them; a month
  here equals that month there (tested). The counting is in
  `App\Support\Reports\MarketingReport`.
- **Decided while building:** spend is entered for a month, so a range
  holding part of a month counts that share of its spend, by its days.
- Every table downloads as CSV: figures only.
- Tests: `tests/Feature/Marketing/MarketingReportsTest.php`. Browser review
  on local data: `.claude/tools/playwright/reviews/slice-32c.mjs`.

## Known limits of this plan

- Marketing figures depend on spend being entered each month (slice 25).
- Finance's profit and loss is QuickBooks' figure as of its nightly read,
  and only for Locations whose company is connected.
