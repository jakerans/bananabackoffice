# Slice 15 — Average Job Size and Close-on-the-Spot Rate: Plan and As Built

Status: **planned and built overnight on 2026-10-15 under decision 155, its
recommendations taken as the answers, for Jacob to review in the morning.**
Not merged. One step.

It follows [product-architecture.md](product-architecture.md), 5.2, which
lists both figures among Sales' secondary figures without defining them, and
builds on slices 7 and 9 (Sales > Performance).

## Why this, from the plan

The two figures are the last of 5.2's list not built ("Not built: Average Job
Size, Close-on-the-Spot Rate"). Every slice plan since slice 10 named them as
small and ready at any time. They need nothing new stored.

## Decisions (recommendations taken as answers under decision 155)

1. **What a "job" is for Average Job Size.** *Taken:* a sold proposal, since
   each has a Project of its own (decision 130). The month's sold revenue
   over the jobs sold in it, each after the month's corrections, the same
   way NSLI is counted. *Alternative:* per Lead won, which would add up
   several proposals sold to one customer.
2. **What "on the spot" means.** *Taken:* of the Leads demoed for the first
   time in the month, the ones with something sold at that demo by the end
   of its day in the Location: the other side of Pending Rate, read the same
   way from the record of sales, so a later correction does not change the
   month of the demo. *Alternative:* sold within a set number of hours.
3. **PFAD.** The plan says normal-project Average Job Size excludes Painter
   for a Day. PFAD is not built, so nothing is excluded yet.

## As built

- `App\Support\Sales\SalesFigures` gains `leadsClosedOnTheSpot`,
  `averageJobSizeCents()` and `closeOnTheSpotRate()`.
- **Sales > Performance** shows "Average job size" and "Close on the spot",
  each with how it was reached, for everyone at the Location or one
  salesperson, and adds both to "How these are counted".
- **Tests:** `tests/Feature/Sales/JobSizeAndSpotTest.php`, on the worked
  October month the other Sales tests use: the two rates for everyone and
  for each salesperson, a lead sold a week later not counting as on the
  spot, a corrected sale coming off the job size and not off the month of
  the demo, and the page.
