# Slice 31 — Estimating and Production Rates: Plan

Status: **proposed on 2026-10-27 (decision 196). Waits for Jacob's answers.**
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
| 31a | **Settings > Pricing: production rates and the price book.** Rate categories and rates with every setting above, products, area templates, line items, the hourly rates, discount and tax, for each Location. A one-time import of each Location's PaintScout settings from the saved file. Every change recorded in the Audit Log. |
| 31b | **The pricing engine, proved against PaintScout.** Backoffice's calculation for areas, surfaces, coats, difficulty, prep, materials, line items, discount and tax. Jacob's test estimates from PaintScout are checked to the cent on this computer; the committed tests use made-up rates. |
| 31c | **The estimate in the Sales Workspace.** Built on a tablet during the appointment: areas from templates, measurements, surfaces from the rates, then options presented the new way. Each option becomes a proposal, so everything built on proposals (results, Pending, sales, commission) keeps working. |

Not in slice 31: the signed agreement and deposit (5.3, later), Commercial
estimates (the plan says commercial selling is pending, 5.9; their rates
are imported but not offered), PaintScout's invoices, emails and
customer-facing PDF design, and moving old PaintScout estimates over.

## Decisions needed

1. **How options are presented.** You said only this changes. What should
   the customer see? *Recommended, from the plan's rule "any number of
   named, ordered options, no fixed tiers":* the salesperson builds
   as many options as the conversation needs, each named in their words
   (for example "Walls only" or "Whole house with the upgraded paint"). They
   are shown side by side with the price and what each includes, and one
   can be marked as recommended. Good / Better / Best percentage tiers are
   not used. Please describe anything you want different.
2. **Whose price book.** *Recommended:* each Location has its own, as in
   PaintScout; a new Location starts as a copy of another's.
3. **Who manages rates.** *Recommended:* Owner and Leadership change them;
   Location Managers and salespeople can see them but not change them.
4. **When rates change.** *Recommended:* an estimate keeps the rates it was
   priced with. Changing a rate affects new estimates only, unless the
   salesperson presses "Reprice" on an open one.
5. **Keeping your prices private.** The GitHub repository is public
   (decision 140). *Recommended:* real rates live only in Backoffice's
   database, entered by the import or the Pricing pages, never in the code.
   The match-to-the-cent check against your test estimates runs on this
   computer only, from files that are never committed.
6. **Farmington.** *Recommended:* import Farmington's PaintScout settings
   too, saved the same way, once you say so.

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
