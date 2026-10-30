# Slice 36 — The Customer's Copy of the Agreement: Plan

Status: **proposed on 2026-10-30 after the plan check following slice 35,
and approved the same day with every recommendation (decision 214); step
36a is built and merged, and slice 36 is complete.** Customer email is
switched on once Jacob sets up its sending domain. Written as what the
implementation agent recommends.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 35 (the full check is in the review handoff of 2026-10-30):

1. **Everything an estimate is priced, worded and signed from is in
   Backoffice** (slices 31, 33 and 35).
2. **The one thing still keeping salespeople on PaintScout** is the
   customer's copy of what they signed. Decision 210 put it "later"; it is
   the only gap left that needs no paid provider.

It is the first email Backoffice sends a customer. Decision 22 keeps
customer email on a sending domain of its own, apart from the staff email,
so the reputation of one never harms the other.

## Settled by Jacob (decision 214)

1. **A sending address of its own for customer email,** set up by Jacob in
   Resend from the guide below. Until it is verified and switched on,
   nothing is sent to customers.
2. **From the Location, replies to the salesperson:** "Banana Painting" and
   the Location's name; replies reach the salesperson who presented it.
3. **The copy is the email itself,** not an attached PDF and not a link.
4. **Staging never emails a customer address:** outside production it goes
   only to Banana's own addresses.

## Step

| Step | Delivers |
| --- | --- |
| 36a | **The signed agreement emailed to the customer** when they sign, to the Lead's email address: the option chosen, its price and deposit, the full wording signed, their name and the date. "Email again" on the agreement page. The agreement page and the Lead's Estimates card show when and to which address it was sent, or why not; every sending is in the Audit Log. |

## Who

- **Emailed by itself** when the customer signs.
- **Email again:** whoever may sign for that Lead (its salesperson or a
  manager), for an agreement that is not void.
- **Seeing whether it was sent:** whoever may see the agreement.

## What Jacob sets up (when he chooses)

1. In Resend, add a domain for customer email, for example
   `email.bananapainting.com`, and add the DNS records Resend shows where
   bananapainting.com's DNS is managed. Wait until Resend shows it
   verified.
2. In Resend, create an API key with sending access to that domain only.
3. On the production server's environment settings in Forge, set the
   values listed under "Customer email" in `.env.example`: the key
   (`RESEND_CUSTOMERS_API_KEY`), the from address on that domain
   (`MAIL_CUSTOMERS_FROM_ADDRESS`), the mailer (`MAIL_CUSTOMERS_MAILER`
   set to `resend`), the switch (`MAIL_CUSTOMERS_ENABLED` set to `true`)
   and, on production only, `MAIL_CUSTOMERS_TO_ANYONE` set to `true`.

Staging stays as it is: switched off, or, if switched on to try it, it
still sends only to Banana's own addresses, because it is never set to
email anyone.

## Step 36a as built

- **When the customer signs,** the copy is emailed to the Lead's email
  address, through its own mailer (`customers`), from
  `MAIL_CUSTOMERS_FROM_ADDRESS` with the name "Banana Painting" and the
  Location's name, replying to the salesperson who presented it while they
  still work here. Subject: "Your signed agreement with Banana Painting".
- **The email:** a greeting, when it was signed, who to reply to; the
  customer, property, option, rooms, line items, savings, price and
  deposit; the full wording of the version signed; "Signed electronically
  by" the name typed, when, and the version. A plain-text version goes with
  it. Everything is escaped.
- **Not sent, and why,** each kept and shown: the Lead has no email
  address; email to customers is not switched on (`MAIL_CUSTOMERS_ENABLED`,
  off by default); or the address is not at a test domain
  (`MAIL_CUSTOMERS_TEST_DOMAINS`, `bananapainting.com` by default) on a
  server not set to email anyone (`MAIL_CUSTOMERS_TO_ANYONE`, off by
  default, set on production only). A
  failure of the email service is reported and shown as "could not be
  sent"; the signing stands.
- **The agreement page,** above the summary: "Customer's copy", with
  "Emailed to … on …", "Not sent on …: why", or "Not emailed yet" for those
  signed before this step; "Email a copy" or "Email again" opens the styled
  confirmation, naming the address. Not offered on a void agreement, nor
  without an address on the Lead. Not printed.
- **The Lead's Estimates card,** under the signed agreement: "Copy emailed
  to … , date", or why not.
- **Every time** is a row of its own (`agreement_emails`) and an Audit Log
  event, "Lead · Agreement emailed", with the address, whether it was
  emailed or why not, and "again" when sent from the page.

Decisions made while building:

- **Who "Email again" is for:** whoever may sign for the Lead
  (`manageProposals`), as for signing.
- **Not again within five minutes** of the last copy sent, so a double
  press cannot send two.
- **The address is the Lead's as it is now,** so a corrected address is used
  when it is sent again.
- **Who may be emailed is a setting, not the server's name:** staging runs
  as "production" too, which the staging check found, so customer
  addresses need `MAIL_CUSTOMERS_TO_ANYONE`, set on production only.
- **Kept small for Gmail,** which cuts off emails over about 100 KB: ABQ's
  wording makes an email of about 70 KB.

Known limits:

- The drawn signature is not in the email; it names who signed and when.
- A much longer wording could make the email long enough for Gmail to cut
  it off, with a link to the rest.
- Replies go to the salesperson's Backoffice address; a salesperson who no
  longer works here gets none, and the email has no reply-to.

## Not in slice 36

A PDF of the agreement; a link the customer opens; the drawn signature in
the email (the email names who signed and when); any other email to
customers (reminders, invoices, marketing); texting.
