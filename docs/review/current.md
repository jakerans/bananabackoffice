# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-30 · **Branch:** `main`

**State:** slice 36, the customer's copy of the agreement, is complete and
on staging (decision 214): step 36a. As built:
[../slice-36-agreement-copy-plan.md](../slice-36-agreement-copy-plan.md).
Customer email is switched off everywhere until Jacob sets up its sending
domain; staging never emails a customer address.

## In the browser (Jacob, on staging)

1. Staging has no signed agreement yet: have one signed on a sample lead
   whose appointment is today (its estimate, **Present**, accept an option,
   sign). The signed agreement opens; above the summary, **Customer's
   copy** says "Not sent: email to customers is not switched on yet".
2. Press **Email a copy** (the lead needs an email address): the
   confirmation names the address, with Cancel first. Send it: it is
   recorded, not sent, and the lead's Estimates card says the same.
3. **Settings > Audit Log** shows "Lead · Agreement emailed" with the
   address and why it was not sent.

## When Jacob chooses: switching it on

The guide is in the plan, "What Jacob sets up": a sending domain for
customer email in Resend and its DNS records, a key limited to it, and four
settings on production in Forge. Staging stays off.

## Next

- The plan check after slice 36, against
  [../product-architecture.md](../product-architecture.md), and the next
  step proposed from it.

## Also waiting for Jacob

- PaintScout's eight test contacts ("Backoffice Test 01" to "08", at ABQ)
  can only be deleted, which cannot be undone, so they are his to delete.
- The sending domain for customer email (above), when he chooses.
- Whether the presentation lists each room's work in the customer wording
  the rates already carry ("Bedroom: Paint Walls, Paint Doors").
- A side menu for Settings, hiding pages not built yet, and where the
  insurance deduction moves (decision 202, point 3).
- QuickBooks's live keys, after Intuit's review.
- 30c, recovery and first use, when he chooses.
- Slice 32, Reports, waits for his answers.
- The app's top bar is wider than a phone screen on every page.
