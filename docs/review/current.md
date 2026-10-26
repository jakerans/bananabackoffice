# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `checkpoint-slice-26-needs-attention`

**State:** slice 26, the Exception Engine and Needs Attention, is built
straight through (decision 185) and **waits for Jacob's review. Not
merged.**

## What to review

Plan and "as built": [../slice-26-needs-attention-plan.md](../slice-26-needs-attention-plan.md).

- **26a:** twelve rules, swept every 15 minutes, find what needs someone,
  each with an owner and a severity; each closes by itself once fixed. An
  Urgent one rings its owner's bell once.
- **26b:** each person's own list on Home with a count in the header; a
  manager's Locations on Home; Company > Needs Attention for the Owner and
  Leadership. Useful, not useful, or dismiss with an optional reason.
- **26c:** a short morning briefing at the top of Home for the person's
  role.

## Browser review (local, fictitious data)

Done with Playwright after a local sweep, which found three of Sam's
pending proposals past their validity. As Sam: the briefing showed 3
customers with a pending proposal and 2 visits to close out; the header
counted 3. Sam marked one useful and dismissed another with a reason; the
dialog opened on Cancel, Escape closed it and returned focus; the list and
the header dropped to 2. As Morgan (Northside's manager): the briefing
showed 3 open at their Locations and the list showed each "For Sam Sales".
As the Owner: Home linked to Company > Needs Attention, which listed all
three. On a phone the rows stack; the header flag is left off there.

The phone header already scrolls sideways before this slice (the separate
top-bar task); nothing below it does.

## Decisions

None open. "Appointments to confirm" counts every standing appointment
from today to four days out, because Backoffice does not record a
confirmation call yet.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
