# Slice 21 — Brand Ambassador: Plan

Status: **approved on 2026-10-25 (decision 175), with the recommendation on
all eight questions, to be built straight through 21a, 21b and 21c and then
reviewed. Being built.** Written as what the implementation agent
recommended from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 20:

1. **A job now runs from first call to financial close and pay** (slices 8
   to 20).
2. **One item of closeout is still unbuilt:** "BA obligations
   handled/scheduled/waived" (6.8). Brand Ambassador is how Banana discounts
   a sale in return for reviews, a yard sign, content and testimonials
   (6.10), and nothing in Backoffice records the package sold or tracks what
   the customer promised.
3. **Its rules are confirmed** (decision 133; 6.10): Bronze 10% (30-day
   yard sign, permission for project content, paper survey); Silver 15%
   (Bronze, plus Facebook and Google reviews with before/after photos and
   being a named or phone reference); Gold 25% (Silver, plus a video
   testimonial and a personal social post). Sales customizes only when the
   package itself is the one thing stopping the sale.
4. **The budget rule waits on it** (10.2): a job is priced before the
   discount, and Production's budget is the floor price, the retail price
   less the largest discount (25%), never the higher price it sold for.
   Slice 19 budgets from the contract, because the retail price was not
   known.

What else could be next, and why not first: **Warranty** (16) is larger and
needs the warranty budget question answered (open question 14); **refunds
and credits** (10.5) are small and affect only a few jobs; the
**leaderboard** (5.8) and the **Performance boards** (17) report on numbers
that already exist.

## What slice 21 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 21a | **The package on the sale.** When a proposal is marked Sold, the salesperson records its Brand Ambassador package: none, Bronze, Silver, Gold, or Custom (the items chosen and the discount given, with why). The job keeps the package, the discount and the retail price before it. The job page and the handoff show it. |
| 21b | **The obligations.** Each job with a package lists what the customer promised, item by item. The Production Manager marks each **completed** (and by whom), **partial** (with a note), **deferred** to a day, or **waived** (with why). A deferred item due reminds the Production Manager; Production > Workspace lists what is due or overdue. |
| 21c | **The floor budget.** The job's budget comes from the floor price: the retail price, with signed change orders, less 25%, times the Location's target cost share. Upside from selling above the floor is a higher margin, never a larger budget. |

Not in slice 21:

- **Sending anything to customers.** Nothing asks for a review or a
  testimonial automatically, after a good job or a bad one; the Production
  Manager decides (6.10).
- **Checking the reviews online.** The Production Manager marks a review
  done; Backoffice does not read Facebook or Google.
- **Changing the tiers in Settings.** The tiers are kept in the code and
  kept on each job as sold; a change is a code change, effective for later
  sales.

## Who may do what

| What | Who |
| --- | --- |
| Record the package at the sale, and correct it until the handoff is complete | The salesperson, or a manager at the Location. |
| Correct it after the handoff | A manager at the Location, with why. |
| Mark an obligation completed, partial or deferred | The job's Production Manager or a manager there. |
| Waive an obligation | The same, with why. |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 21a, 21b, 21c.
2. **When the package is recorded.** *Recommended:* when the salesperson
   marks a proposal Sold, as part of the sale; the salesperson can correct
   it until the handoff is complete, a manager afterwards, with why. Jobs
   sold before slice 21 show "No package recorded" until someone records
   one.
3. **Custom packages.** *Recommended:* Custom means choosing items from
   the list and giving a discount of up to 25%, with why; the Location's
   managers are told, so they see each one.
4. **Who handles the obligations.** *Recommended:* the job's Production
   Manager or a manager there. Whoever marks an item completed can say who
   physically did it.
5. **Does the financial close wait for them?** *Recommended:* it waits
   only until every item has been handled: completed, partial, deferred or
   waived. A deferred item can stay open after the close (6.10: the
   Project may close financially while obligations remain open; decision
   170 left them out of the close because they did not exist yet).
6. **The floor budget.** *Recommended:* follow 10.2 for every job: the
   budget is (retail price + signed change orders) × 75% × (100% − the
   Location's target margin). A job with no package has a retail price equal
   to its sold price. This lowers every job's budget from today's, as 10.2
   intends. Jobs already closed keep the figures they closed with.
7. **The largest discount.** *Recommended:* 25%, kept on each job as sold,
   so a later change does not change an earlier job's floor.
8. **The 30-day yard sign.** *Recommended:* one item, "Yard sign, 30
   days": the Production Manager records the day it went up, and it is due
   to come down 30 days later, with a reminder.

## Step 21a as built

- **The package goes with the sale.** Marking a proposal Sold, on the
  Proposals card or in the after-action report, asks for the Brand
  Ambassador package: no package (the default), Bronze 10%, Silver 15%,
  Gold 25%, or Custom. Custom asks for a discount from 1% to 25%, the items
  promised and why, and tells the Location's managers.
- **The job keeps** the tier, the discount, the items, why, the largest
  discount then (25%) and the retail price before the discount: the price
  sold ÷ (100% − the discount). A job sold before slice 21 says "No package
  recorded" until someone records one.
- **The Brand Ambassador card** on the job shows the package, the retail
  price, who recorded it and what the customer promised. The salesperson
  records or corrects it there until the handoff is complete; a manager at
  any time, saying why once the handoff is complete. Each change is audited
  and shown in the job's activity. The handoff page shows the package.

## Step 21b as built

- **Each item promised is listed on the job** to be handled
  (`project_ambassador_obligations`), from the package as recorded. A
  corrected package adds new items and drops those nobody has handled yet;
  an item already handled stays.
- **The Production Manager or a manager there marks each item:**
  completed (with who physically did it, if someone else), partial (with
  what is done), deferred to a later day, or waived (with why). The yard
  sign is marked up on a day, and is then due to come down 30 days later.
  Each change is audited and shown in the job's activity.
- **Reminders:** at 07:00 each morning, company time,
  `backoffice:ambassador-reminders` tells the Production Manager of each
  item whose day has come, once for each due day.
- **Production > Workspace** lists the Brand Ambassador items due: those
  whose day has come, and those not handled yet on a job the customer has
  signed off. The person's own come first.
- **The financial close** now also waits for every item to be handled
  (decision 175, question 5). A deferred item, such as a yard sign still
  up, does not hold it. Finance > Job Costing says so among what holds a job.

## Known limits of this plan

- **Online reviews are taken on the Production Manager's word.**
- **Commission and the Production Bonus do not change:** they are paid on
  the real gross margin, which already includes the discount.
