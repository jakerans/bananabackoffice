# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-13c-work-order`

**State:** **slice 13, step 13c (the work order) is built and waits for
Jacob's review. It is not merged.** It is the last step of slice 13. Step
13b was merged before it (decision 153).

## What changed

Plan: [../slice-13-giving-work-plan.md](../slice-13-giving-work-plan.md),
"Step 13c" and "Step 13c as built".

- **A work order page** for each package given out, opened from the job's
  "Work order" button: the subcontractor, the address and access notes, the
  dates, working days and crew, the work, what is not included, the
  approved colors, and the pay. For a subcontractor using Banana's coverage
  it shows the deduction and what is paid after it.
- **"Print"** prints the work order alone. Every page now prints without
  the menus, the impersonation banner and its buttons.
- **"Work order sent"** records how and when; it can be recorded only once
  the customer has approved the colors and the subcontractor has accepted
  the payout it has now. If the payout, deduction, days or subcontractor
  change after it was sent, the page says to send it again.
- **The insurance deduction rate** is in Settings > Locations: 10% now,
  changed from a day that has not passed, every rate kept. A work order uses
  the rate in force on its first day.
- **Who sees it:** only those with `payouts.view`; Finance reads it; the
  salesperson cannot open it. "Work order sent" is in the customer's
  Activity without the payout.
- **Stored:** two new tables, `insurance_deduction_rates` and
  `work_orders_sent`.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 14 are new, in
  `tests/Feature/Projects/WorkOrderTest.php`.
- They cover: no work order before an award; the days, payout and deduction;
  the rate in force on the first day; confirmed and rescheduled dates;
  colors required; a changed payout needing acceptance and marking the sent
  one as changed; refusals; who may record; the deduction rate and its
  rules; the page for the Production Manager, Finance and the salesperson.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

1. Settings showed "Now 10% of payout."
2. From the sample job, "Work order" opened the page for the package given
   out in step 13b's review. Its colors were not approved yet, so it said
   it could not be sent; after the sample colors were approved, it showed
   the address and access, Feb 1 to Feb 8 with 6 working days and a crew of
   3, the colors, and the payout of $3,450.50.
3. Printed, it showed the work order alone, with no menus or buttons.
4. "Work order sent" was refused without "how", then recorded: "Sent by
   email on Oct 14, 2026".
5. The sample salesperson was refused the page.

## Architectural decisions made

- **A work order is worked out, not stored,** apart from the record of each
  time it was sent, which keeps what it said then.
- **The deduction rate is effective-dated,** as decision 133 asks, so a
  change never alters work already sent.

## Deviations from the approved plan

None. Three readings, in "Step 13c as built", for Jacob to change if wrong:

- One deduction per work order, whether one or both insurance items are
  covered.
- The rate is the one in force on the work's first day.
- A new rate cannot start in the past.

## Known limitations

- Nothing is sent by Backoffice, and nobody is paid by it.
- A Location rate for the deduction is not built; one company rate applies.

## Plan check after slice 13

Against [../product-architecture.md](../product-architecture.md), 6.6 to 6.9,
7 and 10. Slice 13 built what it set out to: payout, the direct award, and
the work order. Still not built: the marketplace and portal (7.4, 7.5),
declines (7.6), overruns (7.9), the scorecard (7.10), utilization, and
paying subcontractors.

**What the plan says is next: the work itself and closeout** (6.8, 7.9,
7.10). A job now gets as far as a work order. The plan's next stretch is:
work started and finished for each package, an overrun handled with whose
cause it was, the customer's walkthrough and punch list, the
subcontractor's scorecard, and the colors on record for good. Job costing
and final payment, which closeout also needs, touch Finance and QuickBooks
and would come after.

The other candidates:

- **Average Job Size and Close-on-the-Spot Rate** (5.2): small, any time.
- **Document storage:** needs the private DigitalOcean storage of decision
  33 set up first.
- **The deposit and payments:** need the QuickBooks check.
- **The marketplace and a subcontractor portal:** the first time anyone
  outside Banana would sign in; a large step with security questions of its
  own.

## Decisions needed

1. **Merge step 13c into `main`?** Slice 13 is then complete.
2. **What next:** a plan for the work itself and closeout (recommended), the
   two Sales figures, or something else?
