# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-7d-sales-figures`

**State:** slice 7, step 7d is built and waits for Jacob's review and his word
to merge. It is the last step of slice 7. `main` and staging have steps 7a to
7c and none of 7d. Jacob said "Start" on 2026-10-13.

## Step

Slice 7, step 7d: the first Sales figures. Plan:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md),
"Step 7d" and "Step 7d as built". From the plan:
[../product-architecture.md](../product-architecture.md), 5.2, with decisions
124 and 125 (answer 10).

Objective: a salesperson can see how their month is going, and a manager how
their Location's and each salesperson's is, from what has been recorded since
slice 6.

## What changed

**Sales > Performance** is now a real page, where "Coming soon" stood. For a
chosen month, at one Location:

| Figure | What it shows |
| --- | --- |
| **NSLI** (first and largest) | Sold revenue in the month, divided by Leads issued in the month. |
| Leads issued | Leads handed to Sales in the month that still count. |
| Appointments run | And how many were booked for the month. |
| Demo rate | Of the appointments run, how many have a proposal. |
| Close rate | Of the Leads first run in the month, how many are sold. |
| Sold revenue | The sold amounts recorded in the month, and from how many Leads. |
| Cancellation rate | Of the appointments booked for the month, how many were cancelled. No-shows are shown beside it. |

- **Each figure says how it was reached,** for example "2 of 3 run
  appointments have a proposal", and the page ends with "How these are
  counted" in plain words.
- **A salesperson sees their own figures** and nobody else's.
- **A manager sees everyone at their Location,** or chooses one salesperson.
  Someone with several Locations chooses the Location.
- **The last twelve months** can be chosen.
- **No targets, no ranking, no Leaderboard.**
- **Nothing is stored and there is no database change:** every figure is
  counted from the records each time. **No new permission, no new package.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,513 tests (2,512 passed, 1 skipped) in about 400 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 21, in `tests/Feature/Sales/`. They use one worked month, written
out in `tests/Pest.php` (`octoberOfSales`): six Leads in October 2026, one
sold for $8,200, one run with nothing presented, one cancelled, one no-show,
one pending with another salesperson, one booked in error.

- `SalesFiguresTest.php`: that month for everyone ($1,640 NSLI, 67% Demo Rate,
  33% Close Rate, 20% Cancellation Rate) and for each salesperson; nothing
  recorded giving empty rates, not zero; a proposal added a day late moving an
  appointment into Demo Rate; three proposals with one sold being one win, and
  two sold to one Lead being one win with both amounts; a return visit that
  closes the sale not lowering Close Rate, and the Lead counted in the month
  it was first run; a corrected sale, a proposal added in error and a Lead
  that should never have been booked no longer counting; whose figure it is
  when an appointment is moved; one Location never including another's; the
  month being the Location's own.
- `PerformancePageTest.php`: the page being live; refused to people without
  sales access; every figure and the line under it, for a manager and for each
  salesperson; a salesperson asking by hand for a colleague or for everyone
  and still seeing only their own; nobody shown a Location that is not theirs;
  choosing a Location; the twelve months and what an unknown month does; a
  salesperson who has lost access still choosable for the past.

No existing test changed. The tests that used the Performance placeholder as
an example page still pass against the real page.

## Architectural decisions made while building

Listed in the plan under "Step 7d as built". These decide what the numbers
mean, so they are the ones to read:

- **Which month a thing counts in.** A Lead issued: the month the appointment
  was saved. An appointment: the month of its day. A sale: the month it was
  recorded.
- **Close Rate places each Lead once,** in the month of its first run
  appointment. A return visit never counts a Lead twice. A past month's rate
  goes up when one of its Leads is sold later.
- **NSLI is this month's sold revenue over this month's Leads issued.** A sale
  this month of a Lead issued last month is in this month's revenue. "Net" has
  nothing taken off it yet: there are no cancelled sales or refunds.
- **A no-show is not in Cancellation Rate.** It is shown beside it.
- **Whose figure it is, provisional.** A Lead issued counts for the salesperson
  it was issued to; an appointment for the salesperson it is with; a sale for
  the salesperson the Lead is with. The plan leaves this open with Sales
  Leadership (5.1). It only matters when an appointment is moved to another
  salesperson; for a Location as a whole nothing is lost or counted twice.
- **One Location at a time.** No figure across all Locations.

## Deviations from the approved plan

None.

## Known limitations

- **Not built from 5.2:** Average Job Size, Close-on-the-Spot Rate, Pending
  Rate, % Pending with Rehash booked, Rehash conversion. The plan's step 7d
  listed seven figures and these were not among them.
- **No targets and no comparison** with another month or another person.
- **The figures are only as good as what is recorded.** An appointment nobody
  closes out is in none of them; decision 126 exists to prevent that.
- **Small, and not from this step:** at a phone's width the header can be
  wider than the screen when the Location's name is long. The Performance
  page itself fits.
- Local database only: nothing was added for this review.

## Decisions needed from Jacob

1. **Review 7d** and say whether to merge it.
2. **Do the numbers mean what he wants?** The six readings under
   "Architectural decisions" above. Recommendation: keep all six for now, and
   take "whose figure it is" to Sales Leadership, since salespeople are
   measured on NSLI.
3. **What comes after slice 7.** See "The plan check" below.

## The plan check after slice 7

Decision 122 asks for this after each slice. Slice 7 is the after-action
report of the plan's 5.6 and the first figures of 5.2.

- **Built from the plan in slice 7:** run or no-show; close out first, the
  prompt and the reminder; proposals; their results; a Lead that is Sold or
  Lost; corrections; the after-action report; the Objections list; seven Sales
  figures.
- **In 5.6 and 5.2 and not built:** the voice note (decision 125); the five
  figures listed under "Known limitations".
- **Built and not in the plan before its decision:** decisions 126, 128, 129.

**What the plan says could be next,** none of it approved. In the plan's own
order of a customer's path (section 20), a sale that is Won goes to "Sales →
Production handoff":

1. **A Project when a sale is Sold** (rulebook rule 13; decision 36), and the
   handoff to Production. This is the next step on the path, and nothing
   reaches Production until it exists. Recommended next.
2. **Pending ownership and Rehash** (5.5): a Pending proposal has no clock
   today. Its period is still open with Sales Leadership.
3. **Commission** (5.7) and the **Leaderboard** (5.8): both read the figures
   built here. Commission rules are Sales Leadership's.
4. **Still set aside from earlier slices:** the booking engine, confirmation,
   Google Calendar, the new-lead popup and snooze, Aged Leads, the short-call
   report and Connection Rate, recordings.

Each needs its own plan and Jacob's approval before anything is built.

## Browser review (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.** Before
it, the fictitious salesperson's October was counted straight from the
database tables by a different route than the page takes, to compare.

| Step | Result |
| --- | --- |
| 1. A salesperson's own month | Pass. "Sam Sales at Northside, October 2026", with no choice of salesperson. NSLI $109.38 ("$1,750 sold, over 16 leads issued"); 16 Leads issued; 9 appointments run of 20 booked; Demo rate 67% (6 of 9); Close rate 13% (1 of 8); Sold revenue $1,750 from 1 Lead; Cancellation rate 35% (7 of 20), 1 no-show. **Leads issued, appointments run and booked, proposals presented, sold revenue and cancellations each matched the count taken from the tables.** Asking by hand for another salesperson changed nothing. |
| 2. A manager | Pass. "Everyone at Northside, October 2026", with a choice of Location and of salesperson. Choosing the salesperson showed exactly what they see on their own page. Twelve months were offered, October 2026 back to November 2025; September showed a dash for NSLI and "No leads were issued this month." |
| 3. How these are counted | Pass. The explanation was shown under the figures. |
| 4. A phone-sized screen | Pass for the page: the figures stack in one column and fit. The header was wider than the screen because the Owner's Location name is long; that is the header's, not this page's. |
