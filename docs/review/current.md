# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `checkpoint-slice-27-leadership-scorecards`

**State:** slice 27, Leadership Scorecards and TV Mode, is built straight
through (decision 187) and **waits for Jacob's review. Not merged.**

## What to review

Plan and "as built": [../slice-27-leadership-scorecards-plan.md](../slice-27-leadership-scorecards-plan.md).

- **27a:** each Location's general manager owns its weekly scorecard, chosen
  in Settings > Locations with its NSLI and cost-per-close targets. The
  scorecard shows sold revenue against plan, gross profit %, NSLI and cost
  per close; the manager chooses Green, Yellow or Red and says why for any
  that is not Green. Kept as submitted; corrected only with a reason.
- **27b:** Company > Leadership Scorecards, every Location side by side; a
  scorecard not submitted by the end of Monday becomes a Needs Attention
  item for its manager.
- **27c:** "Enter TV Mode" on the Executive Board and the leaderboard.

## Browser review (local, fictitious data)

Done with Playwright. As the Owner, Morgan was made Northside's scorecard
owner with a $1,500 NSLI target and a $400 cost-per-close target. As
Morgan, Home showed Northside's scorecard for 19 to 25 October due; the
scorecard showed $19,700 sold against $32,258 due of a $40,000 target, off
target. Submitting a Red and a Yellow without notes asked for them; with
notes it was submitted. A correction opened on Cancel and saved with its
reason. As the Owner, Company > Leadership Scorecards showed Northside
submitted with its statuses and notes, and the other Locations due. TV Mode
on a 1080p screen showed every Location, the company, the top Needs
Attention items and the leaderboard.

The review found that choosing statuses quickly lost all but the first;
choices now stay in the browser until Submit.

## Decisions

None open. QuickBooks, which brings Net Profit % to the scorecard, is
proposed as the next slice once this one is merged (decision 187).

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
