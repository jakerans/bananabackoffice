# Slice 27 — Leadership Scorecards and TV Mode: Plan

Status: **approved on 2026-10-26 (decision 187), with one owner per
Location, its general manager, and Net Profit % to join once QuickBooks is
connected; built straight through 27a, 27b and 27c. Waits for Jacob's
review; not merged.** Written as what the implementation agent recommended from the
plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 26:

1. **Company is nearly whole** (18): the Executive Board (slice 25), Needs
   Attention (slice 26), Locations and Settings are live. **Leadership
   Scorecards** is the one item of its locked menu still Coming Soon,
   besides Reports.
2. **The scorecard was waiting on targets and owners** (slices 23 and 25
   said so). Each Location now has a monthly sales target (slice 25), and
   every number of the "GM weekly five" but one is already worked out by
   Backoffice: sold revenue, gross profit, NSLI and marketing spend per
   sale (17.3).
3. **The scorecard is human accountability, not another dashboard** (17.3):
   a named owner, a target, the actual, a Green, Yellow or Red the owner
   chooses, and a short note when it is not Green. "GM job: turn red to
   yellow to green." Backoffice fills in the numbers; the leader submits
   the status.
4. **TV Mode** (17.2) is how the boards are meant to be shown on the shop
   and office screens: large type, few controls, auto-refresh, with the
   top Needs Attention items now that they exist.

What else could be next, and why not first: **Reports** (4.6, 5.9, 9.7)
are wide and best shaped by what the boards and scorecards leave out; the
**subcontractor marketplace** (7.4) and **Painter for a Day** (8) are
large; **My Banana** (13), the **Inbox** (4.3) and **QuickBooks** (9.8)
wait on providers; direct ad-platform connections come last (decision
183).

## What slice 27 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 27a | **The weekly scorecard.** For each Location, each week: its KPIs, each with a target and the actual Backoffice worked out; its named owner chooses Green, Yellow or Red for each and writes a short note for any not Green, then submits it. Targets are set in Settings > Locations. |
| 27b | **Company > Leadership Scorecards.** Every Location's latest week side by side for the Owner and Leadership, with the weeks before; who has not submitted. A scorecard not submitted becomes a Needs Attention item for its owner. |
| 27c | **TV Mode.** "Enter TV Mode" on the Executive Board and the Sales leaderboard: a full-screen page with large type, no menus, stronger colors, refreshing by itself, with the top Needs Attention items. |

Not in slice 27:

- **Net Profit %:** overhead lives in QuickBooks (9.8); it joins the
  scorecard when QuickBooks is connected.
- **Rotating TV views and TV Mode on every board:** the Executive Board and
  the leaderboard first.
- **Scorecards for other leaders** (Sales, Production, Finance heads):
  the GM's scorecard first; the same pieces carry other KPIs later.

## The first scorecard

| KPI | Actual, worked out by Backoffice | Target |
| --- | --- | --- |
| Sold Revenue vs Plan | Sold this month to the end of the week | The Location's monthly sales target (slice 25), shown in full and as the share due by that day |
| Gross Profit % | Of jobs closed this month to the end of the week (job costing, slice 19) | Set per Location |
| NSLI | Net sales per Lead issued, this month to the end of the week (5.2) | Set per Location |
| Blended Cost per Close | Marketing spend over sales, this month to the end of the week (slice 25) | Set per Location |

## Decisions needed

1. **The steps and their order.** *Recommended:* 27a, 27b, 27c.
2. **The KPIs.** *Recommended:* the four above, the provisional "weekly
   five" without Net Profit % until QuickBooks is connected. More are
   added when asked for.
3. **Who owns a Location's scorecard.** *Recommended:* one named person per
   Location, chosen by the Owner in Settings > Locations from that
   Location's managers. Until one is chosen, the Owner owns it.
4. **The week.** *Recommended:* Monday to Sunday, in the Location's time.
   Each week's numbers are month to date at its Sunday, so they read the
   same way as the boards. It is due by the end of Monday after it.
5. **The status.** *Recommended:* the owner chooses Green, Yellow or Red
   for each KPI; Backoffice shows whether the actual is on target but never
   chooses. A note of up to 500 characters is required for Yellow or Red.
6. **After submitting.** *Recommended:* a submitted scorecard is kept as it
   was, numbers included, so later corrections to the records do not
   change last week's story. Its owner may correct a status or note, with a
   reason, kept in the audit trail.
7. **Who sees what.** *Recommended:* the owner sees and submits their
   Location's; the Owner and Leadership see every one on Company >
   Leadership Scorecards; nobody else. A scorecard not submitted by the end
   of Monday becomes a Needs Attention item for its owner.
8. **TV Mode.** *Recommended:* on the Executive Board and the Sales
   leaderboard; it refreshes every five minutes and shows the five most
   serious Needs Attention items the person may see. A TV shows it while
   signed in as a staff member who may see the board; there is no public
   link.

## Step 27a as built

- **Settings > Locations, "Scorecard":** whoever manages Locations chooses
  each Location's scorecard owner from its active managers, its general
  manager (question 3), and sets its NSLI and cost-per-close targets; a
  blank target is none. Its sales target (slice 25) and target margin
  (slice 19) are set as before. Until an owner is chosen, the Owner owns
  it. Each change is audited.
- **The scorecard** (`/scorecards/{location}`, a week chosen from the last
  eight): each KPI with its target, the actual, and whether it is on
  target. Sold revenue shows the month's target and the share of it due by
  the week's Sunday. Its owner chooses Green, Yellow or Red for each and
  must say why for any that is not Green, up to 500 characters (question
  5). Only a week that has ended can be submitted, once. Each choice stays
  in the browser until it is submitted, so choosing quickly loses nothing;
  the note box is always there, needed for Yellow or Red.
- **Kept as submitted** (question 6): the numbers, targets and statuses are
  stored, so later corrections to the records do not change them. Its
  owner may correct a status or note with a reason, kept in the audit
  trail; the numbers stay.
- **Who sees it** (question 7): its owner, the Owner and Leadership; only
  its owner submits. Anyone else is refused.
- **Home** shows its owner each scorecard they own for the last week:
  submitted, due by the end of Monday, or overdue.
- **The numbers** are the month so far when the page is opened or the
  scorecard submitted, for the month the week's Sunday is in. Submitted on
  the Monday it is due, that is the week's; submitted later, it includes
  the days since.

## Step 27b as built

- **Company > Leadership Scorecards** (`/company/leadership-scorecards`,
  Live; Company Overview access): every active Location for a week, the
  last that ended unless another of the last eight is chosen. Each shows
  its owner and whether it is submitted, due or not submitted, and each KPI
  with the status its owner chose, the actual, the target and the note. A
  Location not submitted shows its numbers as they are now, with no status.
  Each Location opens its own scorecard for that week.
- **Not submitted by the end of Monday** (question 7): the exception engine
  opens a Needs Attention item, "Scorecard not submitted", for its owner,
  closed by itself once it is submitted. Besides its owner, only the Owner
  and Leadership see it.

## Step 27c as built

- **"Enter TV Mode"** on the Executive Board and the Sales leaderboard
  opens each full-page at `/company/performance/tv` and
  `/sales/leaderboard/tv`, with the same access as the board (question 8).
- **For a screen across a room:** dark, large type, no menus or controls
  besides "Exit TV Mode", and colored status blocks; it refreshes itself
  every five minutes and says when it last did.
- **The Executive Board** shows this month's sold revenue against plan with
  its pace, gross margin, cash and jobs at risk for each Location and the
  company; the leaderboard's top three; and the five most serious Needs
  Attention items.
- **The leaderboard** shows this month's top ten, the leader highlighted,
  the defending champion marked, and the five most serious Needs Attention
  items.
- **Needs Attention on a TV** is the widest list the signed-in person may
  see: everything for the Owner and Leadership, their Locations' for a
  manager, or else their own. A TV signed in as a salesperson shows only
  that salesperson's.

## Known limits of this plan

- **Net Profit %** waits for QuickBooks.
- **Targets other than sales** are new and start empty; a KPI with no
  target shows its actual with no status suggestion.
- **A TV must stay signed in:** if its session ends, someone signs in
  again on it.
