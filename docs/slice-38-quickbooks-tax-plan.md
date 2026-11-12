# Slice 38: Estimates, tax and payment through QuickBooks (plan, not approved)

**Status:** approved 2026-11-12 with every recommendation (decision 230).
38a, the test, is under way; nothing in Backoffice is built yet.

## Why

Jacob, 2026-11-12: "we don't know the gross receipts tax per town.
QuickBooks knows that." Banana's QuickBooks companies use QuickBooks'
automated gross receipts tax lookup (decision 228).

**How it is done today, outside Backoffice:** the salesperson prices the bid
in PaintScout, makes a quick invoice in QuickBooks for the total to get the
gross receipts tax, types that tax back into PaintScout, the customer
accepts, and the invoice is sent from QuickBooks.

**What Jacob wants (decision 229):** "Once we create the pricing, it shoots
it over to QuickBooks, and it creates an estimate. It grabs the gross
receipts tax, brings it back, and fills that in on our system." The
customer chooses an option; the others' QuickBooks estimates are removed;
the chosen one is finalized and sent, and the customer pays through
QuickBooks. QuickBooks collects the deposit on the estimate itself, so the
deposit carries no tax and the gross receipts tax is all on the final
balance.

## What Backoffice does today

- **Estimates** have options, each priced from the rates; the tax is picked
  once for the estimate from a town list in Settings > Pricing Settings
  (decision 204).
- **The customer signs the agreement** for one option on the tablet, in
  Backoffice (decision 210); it keeps the tax, the total and the deposit due.
- **Invoices and payments** are made in QuickBooks by hand, and their
  numbers and amounts typed into the Project (slice 17).
- **The QuickBooks connection** only reads profit and loss (slice 28).

## The flow, once built

1. The salesperson prices the options in Backoffice.
2. For each option, Backoffice makes or updates an estimate in that
   Location's QuickBooks company, for the customer, with the job's address;
   QuickBooks works out the gross receipts tax; Backoffice shows it on the
   option. The town list is no longer used.
3. The customer looks at the options and signs the agreement for one, in
   Backoffice, as today.
4. The other options' QuickBooks estimates are deleted. The chosen one is
   marked accepted and sent from QuickBooks with its deposit request, the
   Location's deposit percent of the price before tax.
5. The customer pays the deposit through QuickBooks. Backoffice reads the
   payment back; nobody types it. **A payment taken another way** (Square,
   financing, check, cash) is recorded in Backoffice, as today: how it was
   paid, how much, the day and its reference. Backoffice sends it to
   QuickBooks as a payment received against the estimate's deposit or the
   invoice, so QuickBooks shows it paid too (decision 230).
6. When the job is done, the estimate becomes the final invoice in
   QuickBooks, the deposit already applied and the tax on the balance; it is
   sent from QuickBooks, and its payment is read back the same way.

## Steps

| Step | Delivers |
| --- | --- |
| 38a | **A test against Intuit's test company, no change to Backoffice.** Prove each QuickBooks piece the flow needs works through QuickBooks' connection for other software, not only in its own screens: the tax worked out on an estimate made from outside, and again when it changes; deleting an estimate; the deposit request on an estimate, and paying it; turning the estimate into the invoice with the deposit applied; sending each by email; reading payments back. A short written result; anything QuickBooks does not allow from outside is brought back to Jacob before 38b. |
| 38b | **The tax from QuickBooks.** Steps 1 and 2 of the flow: the customer matched or added in QuickBooks, an estimate per option kept in step with it, its tax shown. |
| 38c | **The chosen option, finalized and sent.** Steps 3 and 4: the others removed, the chosen one accepted and sent with its deposit request. |
| 38d | **Payments and the final invoice.** Steps 5 and 6: payments made through QuickBooks read back into the Project's payments; payments taken another way recorded in Backoffice and sent to QuickBooks; the final invoice made from the estimate and sent. Cash and check keep their receipt photo and Finance's approval (decision 133), and go to QuickBooks once approved. A Square card payment is recorded as Card with Square's reference. |

Not in this slice: bills, subcontractor payouts and pay entries going to
QuickBooks (later, 9.8).

## 38a results (2026-11-12)

Tried on staging against Intuit's test company ("Sandbox Company US"),
through QuickBooks' connection for other software, as Backoffice would.

**Works from outside:**

- Making an estimate for a customer with the job's address, changing its
  price, deleting one, marking one accepted, and marking one rejected.
- Turning the accepted estimate into the invoice: QuickBooks links the two
  and marks the estimate **Converted**.
- A payment taken another way (a card through Square, with its reference)
  recorded against the invoice; QuickBooks' balance follows.
- **A deposit recorded as the customer's payment before the invoice
  exists:** QuickBooks keeps it as the customer's credit, and applies it to
  the invoice by itself when the invoice is made. The deposit carries no
  tax; the tax is all on the invoice.

**Not shown, and why:**

- **The gross receipts tax from the address.** The test company does not
  use automated sales tax (it has old hand-entered rates), so it worked out
  no tax at all. Intuit's documentation says a company with automated sales
  tax works it out from the ship-to address when the tax is left to it, but
  that has to be seen on a company that uses it.
- **The deposit request on the estimate,** the one the customer pays online.
  QuickBooks' estimate, as outside software sees it, has no deposit field,
  and a payment made against the estimate is not tied to it. Requesting the
  deposit on the estimate looks to be possible only on QuickBooks' own
  screens.

**Waiting for Jacob (decision 231 when answered):**

1. **Seeing the tax worked out:** one test estimate in a real Banana
   QuickBooks company, at a real New Mexico address, read back and deleted
   straight after, as the quick invoice is today; or a new Intuit test
   company with automated sales tax switched on, set up by Jacob in Intuit's
   developer site.
2. **How the deposit is taken:** paid at signing (Square, financing, check,
   cash) and recorded in Backoffice, which sends it to QuickBooks as the
   customer's credit, applied to the final invoice by itself (works now); or,
   for a deposit paid online, someone opens the estimate in QuickBooks, adds
   the deposit request and sends it, and Backoffice reads the payment back;
   or both.

## Questions for Jacob

**All answered (decision 230): every recommendation below is taken.**

1. **QuickBooks automated sales tax:** **answered, yes** (decision 228).
2. **The customer in QuickBooks.** Every lead that is priced becomes a
   QuickBooks customer, bought or not. *Recommended:* match an existing
   customer by email, add one only when none matches, and never change one
   that exists.
3. **When the tax is fetched.** *Recommended:* automatically when an option
   is saved, and again before the customer signs; the agreement cannot be
   signed until the tax is back, with a "Try again" if QuickBooks does not
   answer.
4. **A lead lost after it was priced.** *Recommended:* its QuickBooks
   estimates are marked rejected, not deleted, so they stay in QuickBooks'
   history; only the options not chosen by a customer who bought are
   deleted.
5. **The signature stays in Backoffice.** *Recommended:* yes: the customer
   signs the agreement in Backoffice as today, and QuickBooks is where they
   pay.
6. **A Location without QuickBooks connected** cannot price with tax.
   *Recommended:* connecting its QuickBooks company becomes a ✱ step on its
   checklist, needed before it is switched on.

## Risks

- **This writes to the real books for the first time.** Built and checked on
  Intuit's test companies on staging; on production only with Jacob's
  approval, one Location first.
- **What QuickBooks allows from outside** may be less than its own screens
  allow, the deposit request on an estimate above all; 38a finds out before
  anything is built.
- **Jobs on tribal land** (for example the Navajo Nation, near Four Corners)
  may be taxed differently; QuickBooks' answer is taken as it is, and Finance
  confirms those with the accountant.

## Until it is built

Each Location's towns stay in the estimate's tax list in Settings > Pricing
Settings > Hourly rates and tax, from the New Mexico Taxation and Revenue
Department's rate schedule, which changes each July 1.
