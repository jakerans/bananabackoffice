# Slice 31 — Estimating and Production Rates: Plan

Status: **proposed on 2026-10-27 (decision 196) and approved the same day
(decision 197). Step 31a is built and merged (decision 199); step 31b is
built and waits for Jacob's review.**
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
- **A line item priced by hours is not rounded:** 2.5 hours stays 2.5.
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
  them: how tax is rounded (Backoffice rounds to the cent), two discounts on
  one estimate (Backoffice adds them up, each worked out on the same
  amount), prep hours (counted at the hourly rate, not rounded), a rate's
  own hourly rate, a fixed-hours rate, and a line item with its own price.
  No ABQ rate uses prep hours, its own hourly rate or fixed hours; ABQ does
  use tax rates and several discounts. One more test estimate, with a city
  tax and two discounts, would settle the two ABQ uses.
- PaintScout's per-surface overrides (typing over the hours or the price)
  and an area's quantity are not part of the engine; 31c decides whether
  the estimate offers them.
- Discounts and tax rates are read-only until a later step.
- Staging's default price book gets the discounts and tax rates when the
  import is run there again with `--replace`.
