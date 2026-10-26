# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `checkpoint-slice-25-marketing-executive`

**State:** slice 25, Marketing Spend, Marketing Performance and the
Executive Board, is built straight through (decision 183) and **waits for
Jacob's review. Not merged.** Every Center now opens on a working board.

## What to review

Plan and "as built": [../slice-25-marketing-and-executive-boards-plan.md](../slice-25-marketing-and-executive-boards-plan.md).

- **25a:** Marketing spend by Lead Source and month (`/marketing/spend`),
  corrected with why; each Location's monthly sales target in Settings >
  Locations.
- **25b:** Marketing > Performance: spend, leads, appointments, sales and
  what each cost, return on spend and close rate, by Lead Source.
- **25c:** Company > Executive Board: each Location beside the others and
  the company, each number with its own status; the leaderboard's top three.

## Browser review (local, fictitious data)

Done with Playwright: as Morgan, the Marketing Center opened on its board;
Morgan recorded $1,200 of spend at Northside and corrected it by $200; the
board showed $1,000 spent, 40 leads, $25 per lead. As the Owner, a $40,000
monthly target for Northside was set in Settings > Locations, and Company
opened on the Executive Board with every Location side by side: Northside
$19,700 of $40,000, Behind. Most local sample leads have the "Unknown"
source, so the spend sits against a source with no leads yet.

The company's target adds up only the Locations that have one, while its
sold revenue counts every Location.

## Decisions

None open. Direct ad-platform connections come last of everything
(decision 183).

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
