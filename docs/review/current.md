# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-30 · **Branch:** `main`

**State:** slice 35, the rest of Pricing Settings, is complete and on
staging (decision 213). This is the plan check after it, against
[../product-architecture.md](../product-architecture.md). Nothing is being
built until Jacob chooses the next step.

## Plan check after slice 35

**The plan is up to date:** 5.3 records slice 35; no note is out of date.

**Before salespeople stop using PaintScout:** one gap is left, the
customer's emailed copy of the agreement. Everything an estimate is priced,
worded and signed from is now in Backoffice. Decision 210 put the email
"later"; it is the only thing still needing PaintScout that needs no paid
provider.

**Still "Coming soon" across Backoffice (unchanged since slice 34):**
- Reports in every Center (slice 32, waiting for Jacob's answers).
- Marketing: Inbox and Campaigns, which need texting and email providers.
- Finance: Payments and Payouts, which need a payment provider.
- Color Studio: Sample Requests, Estimate Requests, Color Catalog.
- Settings: Company, Service Areas, Services and Products, Templates
  (waiting for the Settings side menu decision, 202 point 3).
- Not started: the customer portal, Painter for a Day, Referrals, the
  subcontractor marketplace and portal (7.4, 7.5).

## Proposed next: slice 36, the customer's copy of the agreement

The first email Backoffice sends a customer. Decision 22 keeps customer email
on its own sending domain, apart from the staff email
(`mail.bananapainting.com`), so its reputation stays separate.

| Step | Delivers |
| --- | --- |
| 36a | **The signed agreement emailed to the customer** when they sign: the option chosen, its price and deposit, the full wording they signed, their name and the date. "Email again" on the agreement page. The page and the Lead show when and to which address it was sent; every sending is in the Audit Log. A customer with no email address shows "Not sent: no email address". |

**What Jacob sets up (a guide comes with the step):** a sending subdomain
for customer email in Resend, and its DNS records. Until it is verified,
the step is built and tested but nothing goes to customers.

**Questions for Jacob, with recommendations:**

1. **The sending address:** a new subdomain for customer email, for example
   `hello@email.bananapainting.com`. *Recommended:* yes, a separate one, as
   decision 22 says.
2. **Who it is from, who replies reach:** from the Location ("Banana
   Painting Albuquerque"), replies to the salesperson who sold it.
   *Recommended:* yes.
3. **The copy is the email itself,** not an attached PDF: a PDF needs a new
   package, and a link needs a page customers open without signing in.
   *Recommended:* the email itself; a PDF can follow.
4. **Staging never emails a customer address:** there, it goes only to
   Banana staff addresses. *Recommended:* yes.

**Alternatives:** slice 32, Reports, once its questions are answered; or a
small step for the Settings side menu (decision 202 point 3) and the phone
top bar.

## Also waiting for Jacob

- PaintScout's eight test contacts ("Backoffice Test 01" to "08", at ABQ)
  can only be deleted, which cannot be undone, so they are his to delete.
- Whether the presentation lists each room's work in the customer wording
  the rates already carry ("Bedroom: Paint Walls, Paint Doors").
- A side menu for Settings, hiding pages not built yet, and where the
  insurance deduction moves (decision 202, point 3).
- QuickBooks's live keys, after Intuit's review.
- 30c, recovery and first use, when he chooses.
- Slice 32, Reports, waits for his answers.
- The app's top bar is wider than a phone screen on every page.
