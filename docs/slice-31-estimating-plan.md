# Slice 31 — Estimating and Production Rates: Plan

Status: **proposed on 2026-10-27 (decision 196) and approved the same day
(decision 197). Step 31a is built and merged (decision 199).**
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

Read from Banana Painting ABQ's settings, saved for reference outside the
repository (`docs/reference/paintscout/`, never committed).

- **Estimate types:** Residential Interior and Exterior, Commercial
  Interior and Exterior.
- **Areas:** each estimate is built from areas (a room, a side of the
  house), started from 33 area templates, with their measurements.
- **Production rates:** 148 rates in 18 categories (Interior Prep, Walls,
  Ceilings, Interior Trim, Doors, Windows, Exterior Siding, Trim,
  Decking, Cabinets, Drywall Repair, Fencing, Repairs, Flooring Coatings,
  Closets and others). Each rate has:
  - what it measures: wall area, ceiling area, floor area, linear feet,
    or a count;
  - how it is calculated: units per hour (most), price per unit, hours per
    unit, or fixed hours;
  - up to four values, for how hard the work is;
  - coats, a default product, prep hours, and an optional hourly rate of
    its own.
- **Materials:** 11 products, each with a price per gallon and how far a
  gallon goes on walls, on trim and per item.
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
| 31b | **The pricing engine, proved against PaintScout.** Backoffice's calculation for areas, surfaces, coats, difficulty, prep, materials, line items, discount and tax. Jacob's test estimates from PaintScout are checked to the cent on this computer; the committed tests use made-up rates. |
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
## The test estimates (Jacob builds them in PaintScout)

Every test estimate uses a made-up customer named **"Backoffice Test 01"**
to **"Backoffice Test 16"**, so the agent reads only those. Each covers one
part of the calculation, so a mismatch points at its cause. The exact
list comes in a step-by-step page once the decisions above are settled:

- one bedroom priced each standard way: walls, ceiling, baseboards, a door;
- the same room at each difficulty value, and with one coat and three;
- walls sprayed against brush and roll;
- prep hours, setup and clean-up, a closet, cabinet drawers (each other way
  a rate is calculated);
- an exterior: siding, trim, doors, windows; a fence; a deck; a floor
  coating;
- a premium product swapped in, to check materials;
- a priced line item ("Time and Materials");
- a percentage discount, and an hourly rate changed on the estimate;
- a Good / Better / Best package, to record how PaintScout prices tiers.

## Known limits of this plan

- PaintScout's exact rounding (per surface, per area or at the end) is
  learned from the test estimates; until they exist, 31b cannot finish.
- The difficulty values' names are not in the settings file; the test
  estimates confirm which value each choice uses.

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
  default that already has rates. Run on this computer against Banana's
  file, it read 18 categories, 148 rates, 11 paints, 33 areas and 16 line
  items. A rate whose category PaintScout no longer shows (24 of them,
  including all 12 "price per item" rates) is kept with no category,
  switched off, under "Not in a category". Everything PaintScout keeps for
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
