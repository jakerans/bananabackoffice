# Slice 31 — Estimating and Production Rates: Plan

Status: **proposed on 2026-10-27 (decision 196) and approved the same day
(decision 197). Steps 31a and 31b are built and merged (decisions 199 and
203); step 31c is built and waits for Jacob's review.**
Written as what the implementation agent recommends, after reading Banana
Painting ABQ's PaintScout settings with Jacob signed in.

## Why this, now

Jacob put estimating first (decision 196): PaintScout's pricing rebuilt in
Backoffice so the same inputs give the same figures to the cent, with only
the presentation of options changed. He then added that Backoffice must
manage production rates as PaintScout does. Pricing belongs to the Sales
Workspace (product-architecture.md 5.3: "pricing engine", "options",
"presentation"), inside the selling flow and not as a separate Estimates
module. Today a proposal is a name and a price typed by hand; this slice
puts the priced breakdown behind it.

## What PaintScout does, as found

Read from Banana Painting ABQ's own settings and its rates export, saved
for reference outside the repository (`docs/reference/paintscout/`, never
committed). The first file read was the Corporate account's by mistake
(decision 200); these figures are ABQ's.

- **Estimate types:** Interior and Exterior.
- **Areas:** each estimate is built from areas (a room, or one wall or
  surface), started from 13 area templates, with their width, length and
  height; PaintScout works out the length around, wall area, ceiling area
  and floor area from them.
- **Production rates:** 301 rates in 68 categories (wall, ceiling, trim,
  door and repair rates inside; siding, stucco, trim, fascia, soffit,
  shutter, door, fence and deck rates outside, painted and stained). Each
  rate has:
  - what it measures: wall area, ceiling area, floor area, linear feet,
    or a count;
  - how it is calculated: units per hour, hours per item (most), or price
    per item;
  - up to four values, **one for each number of coats** (1, 2, 3, 4): for
    example square feet per hour for one coat, two coats, three;
  - default coats, a default paint and how much of it each item uses,
    prep hours, and an optional hourly rate of its own.
- **Materials:** 26 paints and other products, each with a price per
  gallon and how far a gallon goes on walls, on trim and per item.
- **Price:** hours times the hourly rate (company-wide, by estimate type,
  or by rate), plus materials, plus priced line items, less a discount,
  plus tax. Line items are also the estimate's written sections, such as
  preparation and exclusions.
- **Options:** one "Good / Better / Best" package, with a price
  adjustment for each tier. **This is the part that changes.**

## What slice 31 is, and is not

| Step | Delivers |
| --- | --- |
| 31a | **Settings > Pricing: production rates and the price book.** Rate categories and rates with every setting above, products, area templates, line items, the hourly rates, discount and tax, in a company default price book and a copy for each Location. A one-time import of Banana's PaintScout settings from the saved file into the default. Every change recorded in the Audit Log. |
| 31b | **The pricing engine, proved against PaintScout.** Backoffice's calculation for areas, surfaces, coats, prep, materials, line items, discount and tax. Jacob's test estimates from PaintScout are checked to the cent on this computer; the committed tests use made-up rates. |
| 31c | **The estimate in the Sales Workspace.** Built on a tablet during the appointment: areas from templates, measurements, surfaces from the rates, then the options, one after another down the page, largest first, with quick buttons to jump to each. Each option becomes a proposal, so everything built on proposals (results, Pending, sales, commission) keeps working. |

Not in slice 31: the signed agreement and deposit (5.3, later), Commercial
estimates (the plan says commercial selling is pending, 5.9; their rates
are imported but not offered), PaintScout's invoices, emails and
customer-facing PDF design, and moving old PaintScout estimates over.

## Decisions (settled by decision 197)

1. **Options follow one another down the page, largest first,** never all
   on one screen: the salesperson guides the homeowner through them. Quick
   buttons, easy to tap, jump straight to any option. Each option is a
   proposal of its own. Good / Better / Best tiers are not used.
2. **A default price book, and one per Location.** A new Location starts
   from the company's default price book and is customized from there.
   Banana's PaintScout settings become the default, and ABQ's and
   Farmington's start as copies of it.
3. **Owner and Leadership change rates;** Location Managers and
   salespeople can see them but not change them.
4. **Never retroactive:** an estimate keeps the rates it was priced with;
   a salesperson may choose to reprice an open estimate.
5. **Real prices stay out of the public repository:** they live only in
   Backoffice's database; the match-to-the-cent check runs on this
   computer only, from files that are never committed.
6. **Farmington's PaintScout settings are the same as ABQ's.**
## The test estimates (built in PaintScout)

Each test estimate has a made-up customer named "Backoffice Test 01" and
onwards, so only those are read. Each covers one part of the calculation,
so a mismatch points at its cause: a room at one, two and three coats;
odd measurements; each way a rate is calculated (per hour, hours per item,
price per item); several rooms together; an exterior; a different paint;
a priced line item, a discount and a changed hourly rate; and PaintScout's
optional extras. The implementation agent builds them at Jacob's request
(decision 200) with ABQ's own rate names.

**Built on 2026-10-27:** six estimates at ABQ, "Backoffice Test 01" to
"06" (PaintScout #38 to #43), each with several test areas so that every
area's own figures can be checked: one plain bedroom; the same room at two
and three coats, odd measurements and a tall room; price per item, hours
per item, items per hour and a stained ceiling; an exterior with body,
fascia, doors, shutters and prep, a fence and a deck; a priced line item,
a changed hourly rate and a 10% discount; and a different paint with an
optional extra. Each one's complete PaintScout record is saved outside the
repository (`docs/reference/paintscout/tests/`).

What they already show, for 31b:

- **Hours are rounded up to the whole hour for each surface,** even 6.04
  hours becomes 7 and 0.5 becomes 1; a surface's hours times the hourly
  rate is its labor.
- **Paint is worked out in exact gallons** (area times coats over coverage,
  or per item) and **the paint's cost is rounded up to the whole dollar**
  for each surface.
- **A price-per-item rate has no hours and no paint;** its price is the
  quantity times the price.
- **A line item's price is rounded up to the whole dollar** (test 8,
  below); a line item priced by hours keeps its hours: 2.5 hours stays 2.5.
- **A percentage discount is rounded up to the whole dollar.**
- **Options are priced like any surface but left out of the total.**
- **Measurements are not rounded:** a room's length around is twice its
  width and length, its wall area that times its height, its ceiling and
  floor its width times its length (13.58 by 10.25 is 139.195 square feet).
  One flat surface, such as a side of a house, a fence or a deck, counts its
  length, its length times its height, and its length times its width.
- **A rate for a count of items always multiplies:** a door rate of 0.5
  "per hour" is half an hour for each door, never two doors an hour.
- ABQ also has named discounts (three Brand Ambassador tiers and payments
  by check or ACH, and by card) that the import does not bring in yet;
  31b adds them.

## Known limits of this plan

- PaintScout's exact rounding (per surface, per area or at the end) is
  learned from the test estimates; until they exist, 31b cannot finish.

## Step 31a as built

**Settings > Pricing Settings** (`/settings/pricing`) shows a price book in
five tabs: production rates by category, paints, areas, line items, and
hourly rates and tax. A selector chooses the default price book or a
Location's.

- **The default and each Location's** (question 2). A Location estimates
  with the default until someone presses "Customize for this Location",
  confirmed in a dialog. That copies the default, with every category,
  rate, paint, area and line item, into a book of the Location's own; from
  then on a change to the default no longer reaches it. A Location still on
  the default shows the default's prices and says so; they are changed from
  the default's own view.
- **Who** (question 3). New permissions: `settings.pricing.view`
  (Location-level: Owner, Leadership, Location Manager, Sales Consultant)
  and `settings.pricing.manage` (company-wide: Owner and Leadership). A
  manager or salesperson sees the default and their own Locations' books,
  read-only. Finance, the Call Center and Production Managers are turned
  away.
- **What can be changed now:** production rates (name, the customer's
  label, category, what it measures, how it is calculated, up to four
  values easiest first, coats, prep hours, its own hourly rate, default
  paint, estimate types, never discounted, never taxed, offered or not),
  categories, paints (price, coverage), and the hourly rate overall and by
  estimate type, and tax. Nothing is deleted; a rate, category or paint is
  switched off instead. Areas and line items are shown, and are edited
  with the estimate itself in 31c.
- **Every change is in the Audit Log,** under a new "Pricing" area, naming
  the price book, the rate or paint, and only the settings that changed.
- **Never retroactive** (question 4): no estimate exists yet; 31b keeps
  the rates each estimate was priced with.
- **The import.** `php artisan backoffice:pricing-import <file>` fills the
  default price book from the saved PaintScout settings, once; it refuses a
  default that already has rates. With `--replace` it replaces the
  default's prices instead of refusing (decision 200). Run on this
  computer and on staging against ABQ's file, it read 68 categories, 301
  rates, 26 paints, 13 areas and 4 line items. A rate whose category
  PaintScout no longer shows would be kept with no category, switched off,
  under "Not in a category"; ABQ has none. Everything PaintScout keeps for
  a rate that Backoffice does not use yet is kept with it for 31b.
- **Real prices stay out of the repository** (question 5): they are only in
  the database. The tests use a made-up sample file.

**Decisions made while building:** a Location on the default cannot have
its prices changed from its own view, so nobody changes the default by
mistake while looking at a Location; values are kept as exact decimals,
never rounded on the way in; the tax rate has at most two decimals.

**Known limits:** areas and line items are read-only until 31c; there is
no way to undo a Location's customization (its book can be edited back to
match); production gets its prices by running the import there in 30c.

## Step 31b as built

**The pricing engine** (`app/Support/Pricing/PricingEngine.php`) works out
an estimate's price from its areas, their surfaces and rates, the coats,
the paint, the line items, the discounts and the tax. It is not on a page
yet; the estimate in the Sales Workspace (31c) is built on it.

- **It matches PaintScout to the cent.** All six test estimates price the
  same in Backoffice: every surface's hours, labor, gallons of paint and
  paint cost, every area's totals, and each estimate's hours, paint, price,
  discount and total (173 figures). The check runs on this computer only,
  from the saved estimates outside the repository; elsewhere it is skipped.
  The committed tests use made-up rates.
- **The rules,** as found in the test estimates (above), including two
  corrections to what was written before: measurements are not rounded, and
  a rate for a count of items multiplies even when PaintScout calls it "per
  hour". Settings > Pricing now calls such a rate's value "Hours each".
- **Coats** pick a rate's value for one to four coats and multiply its paint,
  only for a rate that uses coats (the walls, ceilings and trim); any other
  rate counts one coat.
- **Never retroactive** (decision 197, question 4): an estimate is priced
  from a copy of each rate and paint as it was (`RateSnapshot`,
  `PaintSnapshot`), so changing a price later cannot change it. 31c keeps
  those copies with the estimate.
- **Named discounts and tax rates.** The import now brings ABQ's five
  discounts (the three Brand Ambassador tiers, check or ACH, and card) and
  its nine city tax rates into the default price book; "Customize for this
  Location" copies them too. Settings > Pricing shows the discounts on a new
  "Discounts" tab and the tax rates under "Hourly rates and tax", read-only
  for now, like areas and line items.

**Decisions made while building:**

- The engine works in the same floating-point arithmetic PaintScout uses, in
  the same order, so a figure that lands exactly on a whole hour or dollar
  rounds the same way; money comes out in whole cents.
- The named tax rates came with the discounts, though the plan named only
  the discounts: ABQ's estimates choose among them, and they are the same
  kind of list.

**Known limits:**

- **Not yet matched against PaintScout,** because no test estimate uses
  them: a rate's own hourly rate and a fixed-hours rate. No ABQ rate uses
  either.
- PaintScout's per-surface overrides (typing over the hours or the price)
  and an area's quantity are not part of the engine; 31c decides whether
  the estimate offers them.
- Discounts and tax rates are read-only until a later step.
- Staging's default price book gets the discounts and tax rates when the
  import is run there again with `--replace`.

**The seventh test estimate** (decision 203; PaintScout #45, "Backoffice
Test 07"): a bedroom with three sprayed doors and 1.5 prep hours typed in,
walls, two discounts (15% and 10%) and Bernalillo County's 6.188% tax. It
settled four rules, and all seven test estimates now match to the cent:

- **Prep hours are not rounded and are counted beside the painting hours:**
  three doors at 0.75 hours are 2.25 hours, rounded up to 3, plus 1.5 prep,
  so 4.5 hours of labor ($882). PaintScout reports the painting hours and
  the prep hours separately, and Backoffice now does the same. Prep is typed
  on each surface in the estimate, starting from the rate's.
- **Several discounts are each worked out on the same amount** and rounded
  up: 15% and 10% of $1,819 are $273 and $182, $455 together.
- **Tax is rounded to the nearest cent,** never up: 6.188% of $1,364 is
  $84.404, charged as $84.40.
- **A rate that does not use coats offers no coats** in PaintScout: a door
  has no coats choice.

**The eighth test estimate** (PaintScout #46, "Backoffice Test 08"): three
line items, one for each way PaintScout prices them. All eight test
estimates now match to the cent.

- **A line item's price is always rounded up to the whole dollar:** a set
  price of $249.40 is charged as $250; 1.75 hours at its own $210 an hour,
  $367.50, as $368; 3 at $33.33 each, $99.99, as $100.
- **Priced by quantity,** a line item's hours are the quantity times the
  hours for each, rounded up (3 at 0.4 hours is 1.2, so 2), and its price
  the quantity times the price for each; the hours do not change the price.
- A line item priced by hours keeps its hours as typed (1.75).

## Step 31c — plan (approved, decision 204)

The estimate in the Sales Workspace, built on the pricing engine. Proposed
and approved on 2026-10-27 with the recommendation on every question
(decision 204), split into 31c and 31d.

### What the salesperson does

1. **Starts an estimate from the Lead,** at the appointment: "New
   estimate", Interior or Exterior. It uses the Lead's Location's price book
   (the default until that Location is customized) and the book's hourly
   rate for that type. It is a page of its own (`/leads/{lead}/estimate`),
   reached from the Lead and from the Sales Workspace, laid out for a tablet
   first and usable on a phone.
2. **Builds the first option:** adds areas from the area templates (Bedroom,
   House Exterior), each a room or a surface with its width, length and
   height; adds surfaces to each from the rate categories, as in PaintScout
   (Interior Wall Rates, then "8'-10' Walls Two-Tone"); picks the coats where
   a rate uses them, the count for doors and repairs, the paint (starting
   from the rate's), and prep hours. Line items come from the line item
   templates or are typed in, priced by hours, a set price or a quantity.
   Every figure updates as it is typed: hours, paint, price.
3. **Makes more options by copying one** and changing it: fewer rooms, a
   better paint, walls only. Each option has a name ("Whole interior",
   "Bedrooms only") and a short description for the customer.
4. **Chooses the discounts and the tax** for the estimate, from the
   Location's named ones; they apply to every option.
5. **Presents:** a presentation view for the homeowner, the options one
   after another down the page, largest first, each with what is included
   in the customer's words and its price. Buttons at the top, easy to tap,
   jump straight to any option. Hours, rates and paint costs are never
   shown there.
6. **Records what happened,** as today, in the after-action report: the
   options presented are offered as the proposals, with their names and
   prices filled in, instead of typing them by hand. Each becomes a proposal
   of its own (decision 197, question 1), so results, Pending, Rehash,
   sales, projects and commission keep working unchanged.

### Never retroactive

Each surface keeps a copy of its rate and paint as they were when it was
added; the estimate keeps its hourly rate, discounts and tax. A price
change in Settings never changes a saved estimate. "Reprice with today's
prices" updates an open estimate when the salesperson chooses, and says
which figures changed.

### Who

The salesperson the Lead is with, and a manager where the Lead is, as for
proposals today (`LeadPolicy::manageProposals`). Anyone who may see the Lead
may see its estimates, read-only. Every estimate is kept; nothing is
deleted.

### Steps

| Step | Delivers |
| --- | --- |
| 31c | **The estimate:** starting one, areas, surfaces, coats, paint, prep, line items, options by copying, discounts and tax, live prices from the engine, saved as it is built, repricing. Checked against the PaintScout test estimates by building them again in Backoffice. |
| 31d | **The presentation and the proposals:** the presentation view with the quick buttons, and the after-action report taking its proposals from the options presented. |

### Decisions (settled by decision 204)

1. **Options: copies, or one list with each option choosing from it?**
   *Recommended: copies.* Each option is its own set of areas and items,
   started by copying another and then changed. It handles "fewer rooms",
   "better paint" and "add the trim" alike. The other way, one list where
   each option ticks what it includes, cannot give two options different
   paints for the same walls.
2. **PaintScout's optional extras inside an option.** *Recommended: not
   offered.* An extra becomes an option of its own, which is the point of
   decision 197.
3. **Discounts and tax: once for the estimate, or per option?**
   *Recommended: once,* applied to every option, since they follow the
   customer (a Brand Ambassador, paying by check, the city).
4. **May the salesperson change the hourly rate on an estimate?**
   PaintScout allows it. *Recommended: a manager may; a salesperson may not,*
   so price changes come from the scope, the paint and the named discounts.
5. **May anyone type over a surface's hours or price?** PaintScout allows
   it. *Recommended: not in 31c;* the price always comes from the rates.
   If it is needed later, a manager-only override, recorded.
6. **When an option is changed after it was presented and is still
   Pending,** *recommended:* its proposal's price follows, through the
   correction that exists today (kept in the proposal's history). A sold
   proposal is never changed.

### Not in 31c or 31d

The signed agreement and deposit (5.3, later); a PDF or email to the
customer; photos and notes on an estimate; Commercial estimates; editing
area and line item templates, discounts and tax rates in Settings; working
without an internet connection (PaintScout's app keeps working offline;
Backoffice needs a connection, so a weak signal in a customer's home slows
it down).

## Step 31c as built

**The Estimates card on a Lead** lists its estimates, each with its options'
prices, and "New estimate", Interior or Exterior, for the salesperson the
Lead is with or a manager. An estimate opens on a page of its own
(`/leads/{lead}/estimates/{estimate}`), laid out for a tablet: the work on
the left, the option's figures, the discounts and tax and the hourly rate on
the right; on a phone they stack.

- **Building an option:** "Add a room or area" from the area templates (the
  template names it and sets room or surface), with width, length and
  height; the area shows its wall and ceiling area and length around. "Add
  work" picks a category and a rate; the rate brings its default coats,
  paint and prep, and the dialog asks for coats only where the rate uses
  them and "how many" only for a count. Line items come from the templates
  or are typed in, priced by hours, a set price or a quantity. Every figure
  comes from the pricing engine as the option changes.
- **Options:** "Copy this option" makes a new option from the one shown,
  with all its work; each option has a name and a description for the
  customer, and can be removed (an estimate keeps at least one). Removing an
  option, an area or a piece of work asks first, in a styled dialog with
  Cancel focused.
- **Discounts and tax** are ticked once and apply to every option (decision
  204, question 3). **The hourly rate** is changed by a manager only
  (question 4), and so is a line item's own hourly rate.
- **Never retroactive:** each piece of work keeps a copy of its rate and
  paint; saving it again with the same rate keeps the copy. "Reprice with
  today's prices", after a confirmation, takes everything again from the
  Location's price book as it is now, its own once customized, and says how
  many prices changed and how many the book no longer has (kept as they
  were).
- **Recorded on the Lead** (its Activity and the Audit Log): an estimate
  started, its discounts and tax changed, its hourly rate changed, and a
  repricing with each option's total before and after. The work itself is
  not recorded piece by piece while it is being built.
- **Who** (`EstimatePolicy`): anyone who may see the Lead sees its estimates,
  read-only; building follows `LeadPolicy::manageProposals`; the hourly rate
  needs a manager where the Lead is.
- **Checked against PaintScout:** all eight test estimates, built again from
  ABQ's imported settings through the same actions the page uses, come to
  PaintScout's totals to the cent, on this computer only.

**Decisions made while building:** the work on an estimate is not recorded
in the Audit Log piece by piece, only the estimate's start, discounts and
tax, hourly rate and repricing, so the Lead's Activity is not flooded while
an estimate is built; a line item's own hourly rate is a manager's, like
the estimate's; a rate or paint switched off in the price book stays on an
estimate that already has it.

**Known limits:** nothing is shown to the homeowner yet and options do not
become proposals; both are 31d. Work cannot be reordered or moved between
areas. An estimate cannot be deleted. The page needs a connection.
