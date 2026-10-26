# Slice 28 — QuickBooks Connection and Net Profit: Plan

Status: **approved on 2026-10-26 (decision 189): each Location has its own
QuickBooks company and connects on its own; staging uses Intuit sandbox
companies. To be built straight through 28a, 28b and 28c and then reviewed.
Being built.** Written as what the implementation agent recommended from the
plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 27:

1. **Jacob asked for it** (decision 187): QuickBooks is already set up for
   the Location, and connecting it brings Net Profit % to the Leadership
   Scorecard, the one KPI of the "weekly five" still missing (17.3).
2. **Net or operating profit is missing everywhere** Backoffice shows the
   business's health: Finance > Performance (9.1), the Executive Board and
   its TV view (17.2, 18). Overhead lives only in QuickBooks.
3. **The boundary is settled** (9.8, decision 133): QuickBooks is the formal
   ledger; Backoffice never becomes a second one. Reading QuickBooks'
   profit and loss keeps to that: Backoffice shows QuickBooks' numbers, it
   does not work them out again.
4. **It is the first step of the QuickBooks integration** the plan names
   (9.8, 14): connecting safely and reading comes before Backoffice writes
   invoices, payments or bills to QuickBooks, which is larger and later.

## What slice 28 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 28a | **The connection.** Settings > QuickBooks: the Owner connects Backoffice to a QuickBooks Online company by signing in to QuickBooks and approving it there, and matches each Backoffice Location to that company or to one of its QuickBooks Locations. Disconnect at any time. The connection renews itself; if it ever cannot, its owner is told in Needs Attention. Every change is audited. |
| 28b | **Profit and loss, read from QuickBooks.** Each night, and on "Refresh now", Backoffice reads the profit and loss for this month and last month for each matched Location and the company: income, cost of goods sold, expenses and net income, kept with when they were read. Finance > Performance shows them. |
| 28c | **Net Profit % where it belongs.** On the Leadership Scorecard as its fifth KPI, with a target per Location; on the Executive Board and its TV view, for each Location and the company. |

Not in slice 28:

- **Writing to QuickBooks:** invoices, payments, bills, credits and pay
  entries going to QuickBooks, and their status coming back (9.8). A later
  slice, after this one shows the connection works.
- **Reports beyond profit and loss:** balance sheet, A/R aging from
  QuickBooks, sync exceptions (14).
- **Any change to QuickBooks:** this slice only reads.

## What Jacob does, outside Backoffice

Connecting needs an app registered with Intuit, QuickBooks' maker, which is
free:

1. At developer.intuit.com, sign in with the Intuit account that runs
   Banana's QuickBooks, and create an app for QuickBooks Online accounting.
2. Put the app's two keys into the server's settings in Forge himself.
   They never go in a chat, a document or the code.
3. In Backoffice, press "Connect QuickBooks" and approve it on QuickBooks'
   own page.

Intuit gives an app test keys at once and its live keys after a short
questionnaire about the app. The implementation agent writes the exact
steps when the step is built.

## Decisions needed

1. **The steps and their order.** *Recommended:* 28a, 28b, 28c.
2. **How Banana's QuickBooks is set up.** *Recommended:* Backoffice handles
   both ways: one company file with QuickBooks Locations turned on, each
   matched to a Backoffice Location (the plan's starting structure, 14), or
   one company file per Location, each connected on its own. Jacob says
   which he has; nothing else changes.
3. **Read only.** *Recommended:* this slice only reads from QuickBooks.
   Nothing in QuickBooks is changed.
4. **Staging and real books.** *Recommended:* staging, which is for
   testing, connects to one of Intuit's free sandbox companies with made-up
   numbers. Banana's real QuickBooks is connected only on production, once
   production is approved. The alternative is connecting the real books on
   staging now, so Net Profit % is real today; that puts Banana's real
   financials on the test server.
5. **What Net Profit % is.** *Recommended:* QuickBooks' net income over
   its total income, for the month so far, as QuickBooks reports it. A
   Location's leaves out shared company costs not assigned to a Location in
   QuickBooks; the company's includes them.
6. **How often.** *Recommended:* every night, for this month and last
   month (last month changes until the books close), and whenever Finance
   or the Owner presses "Refresh now". Each number says when it was read.
7. **Who connects and who sees.** *Recommended:* only the Owner connects,
   matches or disconnects. Profit and loss is seen by Finance, the Owner and
   Leadership; a general manager sees their own Location's Net Profit % on
   their scorecard.
8. **Keeping the connection safe.** *Recommended:* its keys are stored
   encrypted and never shown or logged; Backoffice asks QuickBooks for
   accounting access only, and uses it only to read reports. Disconnecting
   removes them.

## Step 28a as built

- **Settings > Integrations** (`/settings/integrations`, Live; whoever
  manages Locations): each active Location with its QuickBooks company, or
  "Not connected". "Connect" sends the Owner to QuickBooks' own page to sign
  in with that Location's QuickBooks login and approve Backoffice; QuickBooks
  sends them back and the company's name is shown. "Connect again" replaces
  the approval; "Disconnect", after a styled confirmation, tells QuickBooks
  to forget it and removes it from Backoffice.
- **One company, one Location** (decision 189): a company already
  connected to another Location is refused.
- **Kept safe** (question 8): the keys QuickBooks gives are stored
  encrypted, never shown, logged or sent anywhere but QuickBooks. A one-time
  code ties QuickBooks' answer to the person and Location that asked, so an
  approval started anywhere else connects nothing. Backoffice asks only for
  accounting access and only reads (question 3).
- **Renewing:** Backoffice renews the keys itself before they run out and
  keeps the newest QuickBooks gives. If QuickBooks refuses, or the approval
  is within 14 days of ending, the exception engine opens "QuickBooks needs
  connecting again" for whoever manages Locations: Urgent once refused.
- **Switched off until the keys are in:** with no Intuit app keys on the
  server, the page says so and offers no Connect. The server reads Intuit's
  test companies unless it is set to production (question 4).
- **For Jacob, once:** the Intuit app needs this Redirect URI, for each
  server it is used on: the server's address followed by
  `/settings/integrations/quickbooks/callback`. The server's settings need
  `QUICKBOOKS_CLIENT_ID`, `QUICKBOOKS_CLIENT_SECRET` and, on staging,
  `QUICKBOOKS_ENVIRONMENT=sandbox`.

## Known limits of this plan

- **Live keys need Intuit's questionnaire,** which only Jacob can answer.
- **Net Profit % is only as current as the books:** costs not yet entered
  in QuickBooks are not in it.
- **A matched Location must exist in QuickBooks:** costs QuickBooks does
  not assign to a Location count only in the company's number.
