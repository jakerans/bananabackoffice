# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-27 · **Branch:** `checkpoint-slice-31b-pricing-engine`

**State:** step 31b, the pricing engine, is built and waits for Jacob's
review. As built: [../slice-31-estimating-plan.md](../slice-31-estimating-plan.md),
"Step 31b as built". The production server is set up (step 30b) except
QuickBooks's live keys, which wait for Intuit's review; Backoffice's public
terms and privacy pages for Intuit (decision 201) are on their own branch.

## Decisions needed

1. **Merge 31b?** It matches all six PaintScout test estimates to the cent.
2. **One more PaintScout test estimate,** with a city tax and two discounts,
   to settle how PaintScout rounds tax and combines discounts, which ABQ
   uses and no test estimate shows yet. Built by the implementation agent,
   like the others, if Jacob agrees.
3. **Staging's prices:** run the import there again with `--replace`, so
   staging's default price book gets the discounts and tax rates.

## Next

- **31c, the estimate in the Sales Workspace,** after 31b.
- **30c, recovery and first use:** the restore drill, the backup and file
  copy checks, then the first Owner, the Locations and QuickBooks on
  production. With Jacob, when he chooses.

## In the browser (after merging, on staging, once the import is run)

Settings > Pricing Settings: the new "Discounts" tab lists the five
discounts; "Hourly rates and tax" lists the nine city tax rates under the
hourly rates.
