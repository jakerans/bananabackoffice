# Slice 38: Tax from QuickBooks (plan, not approved)

**Status:** proposed 2026-11-12 (decision 227). Nothing here is built.

## Why

Jacob, 2026-11-12: "we don't know the gross receipts tax per town.
QuickBooks knows that." New Mexico's gross receipts tax depends on where the
job is, and the agreed design already says QuickBooks works out the tax from
the service address (product-architecture.md, 9.8 and decision 133's Finance
rules: "QuickBooks calculates tax from the service address, placed in both
bill-to and ship-to; estimates/invoices label it 'Approximate State & Local
Tax (subject to change)'").

## What happens today

- **Estimates:** the salesperson picks a town's rate from a list kept in
  Settings > Pricing Settings > Hourly rates and tax, imported from
  PaintScout. Someone has to keep that list right, and add each new
  Location's towns.
- **Invoices:** made by hand in the Location's QuickBooks company, which
  works out the tax. Backoffice is told the invoice's number and total, tax
  included, typed in on the Project.
- **The QuickBooks connection** only reads profit and loss (slice 28). It has
  never written anything to QuickBooks.

## The goal

Nobody keeps a tax-rate list by hand. The tax on what a customer pays is
always QuickBooks', and an estimate's approximate tax comes from the same
place.

## Steps

| Step | Delivers |
| --- | --- |
| 38a | **A test against Intuit's test company, no change to Backoffice.** Confirm that QuickBooks' automatic sales tax works out the tax when Backoffice creates an invoice, or an estimate, with a New Mexico service address, and gives the tax back; whether an estimate can be asked for without leaving clutter in the books; and what it needs from Banana's companies (automatic sales tax switched on, the right items marked taxable). A short written result, then Jacob decides 38b and 38c. |
| 38b | **Invoices made in QuickBooks from Backoffice.** On a Project, **Send to QuickBooks** makes its deposit or final invoice in that Location's QuickBooks company, with the service address as bill-to and ship-to; QuickBooks works out the tax; Backoffice records the invoice's number, tax and total itself instead of having them typed. Sending twice never makes two invoices. Recorded in the Audit Log. |
| 38c | **An estimate's tax from QuickBooks.** While pricing, the estimate shows the tax QuickBooks would charge at the job's address, still labelled approximate. The town list is retired once every Location's estimates use it. Only if 38a shows it can be done cleanly. |

Not in this slice: payments, bills, subcontractor payouts and pay entries
going to QuickBooks (later, 9.8).

## Questions for Jacob

1. **Do Banana's QuickBooks companies use QuickBooks' automatic sales tax?**
   **Answered (decision 228): yes,** QuickBooks' automated gross receipts tax
   lookup.
2. **Should Backoffice make the deposit and final invoices in QuickBooks
   (38b)?** *Recommended:* yes, by a **Send to QuickBooks** button for
   whoever records invoices today, not automatically, so each is looked at
   before it goes to the books.
3. **Customers in QuickBooks.** An invoice needs the customer to exist there.
   *Recommended:* match an existing QuickBooks customer by email, and create
   one only when none matches; never change one that exists.
4. **The estimate's tax (38c):** from QuickBooks while pricing, or keep the
   town list for estimates? *Recommended:* decide after 38a.

## Risks

- **This writes to the real books for the first time.** Built and checked on
  Intuit's test companies on staging first; on production only with Jacob's
  approval, one Location first.
- **A Location not connected to QuickBooks** cannot send invoices; it says
  so, and the invoice is made by hand as today.
- **Jobs on tribal land** (for example the Navajo Nation, near Four Corners)
  may be taxed differently; QuickBooks' answer is taken as it is, and Finance
  confirms those with the accountant.

## Until it is built

Each Location's towns stay in the list in Settings > Pricing Settings >
Hourly rates and tax, from the New Mexico Taxation and Revenue Department's
rate schedule, which changes each July 1.
