# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `checkpoint-slice-28-quickbooks`

**State:** slice 28, the QuickBooks Connection and Net Profit, is built
straight through (decision 189) and **waits for Jacob's review. Not
merged.**

## What to review

Plan and "as built": [../slice-28-quickbooks-plan.md](../slice-28-quickbooks-plan.md).

- **28a:** Settings > Integrations: each Location connects its own
  QuickBooks company by signing in on QuickBooks' own page. Read only; keys
  kept encrypted and renewed; "QuickBooks needs connecting again" in Needs
  Attention if QuickBooks refuses.
- **28b:** each Location's profit and loss, read every night for this
  month and last, and on "Refresh now"; shown on Finance > Performance.
- **28c:** Net Profit % as the scorecard's fifth KPI, with a target per
  Location, and on the Executive Board and its TV view.

## Browser review (local, fictitious data)

Done with Playwright. This computer has no Intuit keys, so Settings >
Integrations said QuickBooks is not set up yet and listed every Location
"Not connected"; Finance > Performance showed each "Not connected to
QuickBooks"; a 12% Net Profit target was saved in Settings > Locations and
showed on Northside's scorecard; the Executive Board and its TV view showed
the Net profit row and "—". Connecting, renewing, refusals and reading the
report are covered by tests against QuickBooks' replies, faked. The TV's
"Gross margin" label was shortened to "Margin" to fit four numbers.

## To try it with QuickBooks (Jacob, outside Backoffice)

1. At developer.intuit.com, create an app for QuickBooks Online
   accounting. Intuit gives it sandbox companies with made-up numbers.
2. In the app's settings, add the Redirect URI: the staging address
   followed by `/settings/integrations/quickbooks/callback`.
3. In Forge, put the app's development Client ID and Client Secret into
   staging's environment as `QUICKBOOKS_CLIENT_ID` and
   `QUICKBOOKS_CLIENT_SECRET`, with `QUICKBOOKS_ENVIRONMENT=sandbox`. Never
   in a chat.
4. In Backoffice, Settings > Integrations, press Connect for a Location and
   sign in to a sandbox company.

## Decisions

None open. Banana's three real QuickBooks companies are connected only on
production, once production is approved (decision 189).

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
