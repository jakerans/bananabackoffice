# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-27 · **Branch:** `checkpoint-slice-31-estimating`

**State:** slice 31, Estimating, is approved (decision 197). **Step 31a,
Settings > Pricing Settings, is built and waits for Jacob's review. Not
merged.** Jacob is setting up production (step 30b) from the guide, at
`app.bananapainting.com` (decision 198).

## What to review

Plan and "Step 31a as built":
[../slice-31-estimating-plan.md](../slice-31-estimating-plan.md).

- The default price book and each Location's: a Location uses the default
  until "Customize for this Location" copies it.
- Production rates, categories, paints, hourly rates and tax, changed by
  Owner and Leadership, seen by managers and salespeople; every change in
  the Audit Log under "Pricing".
- `php artisan backoffice:pricing-import <file>` fills the default once
  from the saved PaintScout settings. Real prices are only ever in the
  database, never in the repository.

## In the browser (Jacob, after merging, on staging)

Staging has no prices until the import is run there; it uses the saved
file, which only exists on this computer, so the agent sends it with
Jacob's say-so. Until then the page shows "No price book yet".

1. Settings > Pricing Settings: the rates by category, then Paints and
   Hourly rates and tax.
2. Choose a Location: it says it uses the default. Press "Customize for
   this Location", then edit one of its rates.
3. Settings > Audit Log, area Pricing: the change, with the rate's name.

## Decisions

None open for 31a.

## Next

- **31b, the pricing engine,** needs the 17 test estimates built in
  PaintScout from the list published to Jacob.
- **30b, the production server:** Jacob, from the guide.

## Noted separately

The header overflow on phones is being fixed in its own session.
