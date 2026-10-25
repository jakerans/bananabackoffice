# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-25 · **Branch:** `checkpoint-slice-23-performance-boards`

**State:** slice 23, Performance Boards, is built straight through
(decision 179) and **waits for Jacob's review. Not merged.**

## What to review

Plan and "as built": [../slice-23-performance-boards-plan.md](../slice-23-performance-boards-plan.md).

- **23a:** Production > Performance, the page the Production Center now
  opens on.
- **23b:** Finance > Performance, the page the Finance Center now opens on.
- **23c:** Sales > Leaderboard, its own page in the Sales menu.

## Decisions

None open.

## Browser review (local, fictitious data)

Done with Playwright: as Remy, the Production Center opened on its new
board; as Finley, the Finance Center opened on its board, with variable pay
owed; as Sam, the leaderboard showed him as defending champion. A
Production Manager's month includes warranty cost recorded in it, as the
Production Bonus does; the company's completed gross margin counts the
financial closes only, with warranty cost shown beside it on Finance.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
