# Slice 25 — Marketing Spend, Marketing Performance and the Executive Board: Plan

Status: **approved on 2026-10-26 (decision 183), with the recommendation on
all eight questions, and built straight through 25a, 25b and 25c on
`checkpoint-slice-25-marketing-executive`. Waiting for Jacob's review; not
merged.** Direct ad-platform connections come last in the
plan (decision 183). Written as what the implementation agent recommended
from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 24:

1. **Every job can now be closed,** whatever the customer pays, and the
   Sales, Production and Finance Centers each open on a working board
   (slices 7 and 23).
2. **Two Centers still open on a Coming Soon page:** **Marketing >
   Performance** (4.1) and **Company > Executive Board** (18).
3. **Marketing's board is held by one missing number: spend.** Its
   headline figures are spend, leads, cost per lead, cost per appointment,
   cost per close, sold revenue and return on spend, with close rate by
   source (4.1). Leads, their sources, appointments and sales are all
   recorded already; what was spent on each source is not.
4. **The Executive Board** is a cross-functional view of company and
   Location health, KPI by KPI rather than one score, with revenue, gross
   margin, the top exceptions and the leaderboard (18). Everything on it is
   now recorded, except a plan to compare sold revenue against.

What else could be next, and why not first: **Needs Attention** and the
**exception engine** (18, 19) are a larger, separate design; **Leadership
Scorecards** need named owners and targets; **My Banana**, **Referrals**
and **Painter for a Day** are large and customer-facing.

## What slice 25 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 25a | **Marketing spend.** Each month's spend on each Lead Source at a Location, recorded with a note; a correction is a new entry with why, never an edit. And each Location's monthly **sold-revenue target**, set in Settings > Locations, effective from a month. |
| 25b | **Marketing > Performance.** For a month and one Location or all of a person's: by Lead Source and in total, spend, leads, cost per lead, appointments issued, cost per appointment, sales, cost per close, sold revenue, return on spend and close rate. |
| 25c | **Company > Executive Board.** For the company and each Location, side by side: sold revenue against target, completed revenue and gross margin, cash collected, what is late or with Collections, leads and cost per lead, and jobs at risk, each with its own status; this month's top three on the leaderboard. Each links to its board. |

Not in slice 25:

- **Ad platforms' own figures** (Google, Meta): spend is entered by hand
  until a provider is connected (22).
- **Sales Slot Utilization** (4.1): it needs the sales slot rules of each
  Location (18), which are not set yet.
- **Net profit:** QuickBooks holds overhead (9.8).
- **Needs Attention, Leadership Scorecards and TV Mode** (17, 18): later.

## Who may do what

| What | Who |
| --- | --- |
| Record and correct marketing spend | A new permission, `marketing.spend.manage`, at the Location: Owner, Leadership, Location Manager and Finance. |
| See Marketing > Performance | Those with Marketing access at the Location. |
| Set a Location's sold-revenue target | Those who manage Locations (the Owner today). |
| See the Executive Board | Company Overview access (Owner and Leadership today). |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 25a, 25b, 25c.
2. **What spend is recorded against.** *Recommended:* the **Lead Source**,
   where a Lead actually came from (Google Ads, Facebook Ads, a home show),
   since that is what is paid for (21); by month and Location.
3. **Who records spend.** *Recommended:* the new `marketing.spend.manage`
   permission, held by Owner, Leadership, Location Manager and Finance; not
   the Contact Center.
4. **Which leads, appointments and sales count for a month.**
   *Recommended:* leads received in the month; appointments issued in it;
   sales and sold revenue in the month they were accepted, by the Lead's
   source, as Sales > Performance counts them (21: current-period operating
   performance, sold revenue not collected).
5. **Return on spend.** *Recommended:* sold revenue ÷ spend, shown as
   "$4.20 per $1"; with no spend, a dash.
6. **The sold-revenue target.** *Recommended:* a monthly amount per
   Location, effective from a month, set in Settings > Locations; the
   company's target is the Locations' sum.
7. **Each KPI's status on the Executive Board.** *Recommended:* sold
   revenue against the month's target so far (on pace, behind); gross
   margin against the Location's target margin; late balances and jobs at
   risk shown as counts, red when not zero. No single health score (18).
8. **Who sees the Executive Board.** *Recommended:* Company Overview
   access, as its menu already says (Owner and Leadership).

## Step 25a as built

- **Marketing spend** (`marketing_spend_entries`) has its own page,
  `/marketing/spend`, for anyone with the new `marketing.spend.manage` at a
  Location (Owner, Leadership, Location Manager, Finance; question 3). For a
  month that has begun, it lists the spend at the Locations shown, with the
  total, and adds an entry: Location, Lead Source (question 2), amount and a
  note. A wrong entry is corrected by a new one that adds or takes some
  back, with why; nothing is ever changed. Each is audited.
- **The monthly sales target** (`location_sales_targets`) is set in
  Settings > Locations with **Sales target**, from a month; earlier months
  keep the target they had (question 6). The table shows this month's.

## Step 25b as built

- **Marketing > Performance is live,** the page the Marketing Center opens
  on, for one Location or all of those where the person has Marketing
  access, and a chosen month.
- **For each Lead Source with spend, leads, appointments or sales in the
  month,** and in total: spend, leads received, cost per lead, appointments
  issued, cost per appointment, sales, cost per sale, sold revenue, return
  on spend ("$8.45 per $1") and close rate (question 5). A cost with nothing
  to divide by is a dash.
- **Counted as Sales > Performance counts them** (question 4): Sales'
  own figures can now be narrowed to one Lead Source, so appointments
  issued, sales, sold revenue and close rate (sales over Leads demoed,
  decision 135) are the same numbers, by source. Leads count in the month
  received.
- Those who record spend get a **Marketing spend** button to the spend page
  for the month shown.

## Step 25c as built

- **Company > Executive Board is live,** the page Company opens on, for
  Company Overview access (Owner and Leadership; question 8), for a chosen
  month.
- **Each Location in a column, then the company:** sold revenue with its
  target and **On pace**, **Behind** or **No target** against the share of
  the target for the days gone (a finished month needs all of it); completed
  revenue; gross margin against the Location's target margin, red when
  below; cash collected; balances late and with Collections now; leads and
  cost per lead; jobs at risk now, red when any (question 7). No single
  health score.
- **The month's top three** on the leaderboard. Each row links to its own
  board.
- The company's target is the Locations' targets added up (question 6).

## Known limits of this plan

- **Spend is what is typed:** until an ad platform is connected, the board
  is as good as the entries.
- **A Lead with no paid source** counts in leads and sales, with no cost
  per lead.
