# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-9a-pending-clock`

**State:** slice 9, step 9a is built and waits for Jacob's review and his word
to merge. `main` and staging do not have it. This branch also holds the slice
9 plan and decision 137, which approved it ("I agree with all of that. Go
ahead.").

## Step

Slice 9, step 9a: the clock on a pending proposal. Plan:
[../slice-9-pending-rehash-plan.md](../slice-9-pending-rehash-plan.md), "Step
9a" and "Step 9a as built". From the plan:
[../product-architecture.md](../product-architecture.md), 5.5; decisions 131
and 137.

Objective: every pending bid has a last day, the salesperson can see and move
it, and is warned before it runs out. Nothing is released yet; that is step
9b.

## What changed

**Every pending proposal has a "valid until" day.**

- It is seven days after the appointment the proposal was last presented at.
  Presented again at a return visit, it gets seven more days from that visit.
- The Lead's Proposals card shows it under each pending proposal: "Valid
  until Tue, Oct 20 · 7 days left", "Valid until today · last day", or
  "Expired 3 days ago".
- Above the proposals the card says when the Lead leaves its salesperson:
  "After Tue, Oct 20 this lead goes to the Contact Center for rehash, unless a
  return visit is booked or it is sold or lost." With two pending proposals
  it is the later day.

**The day can be moved,** in the proposal's "Change" dialog.

- The salesperson may go up to 30 days ahead on their own. Further is refused
  with "You can move it up to 30 days ahead, to Nov 12, 2026. A manager can
  set a later day."
- A manager may choose any day from today.
- What the day was is kept in the audit record.

**Sales > Calendar has a "Pending bids" list:** the salesperson's Leads with
something pending, soonest to expire first, each with what was pitched, the
price, the main objection and the days left. A manager sees everyone's at
their Locations, with the salesperson's name.

**A warning in the bell,** two days before the last day: "A pending bid of
yours expires in 2 days. The lead then goes to the Contact Center for rehash,
unless a return visit is booked or it is sold or lost." Once. If the day is
moved on, they are warned again when the new one is near. No email.

**Settings > Contact Center has "Pending proposals":** the number of days a
proposal is valid, starting at 7, from 1 to 90.

**Database:** one new column on each of three tables. Every proposal that is
pending when this is installed gets seven days from that day, so nothing is
already expired. **No new permission, no new package, nothing sent to a
customer.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,691 tests (2,690 passed, 1 skipped) in about 450 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 33, in `tests/Feature/Proposals/`:

- `ProposalValidityTest.php` (22): seven days from the visit; the number in
  the settings when it is presented; a proposal typed in late; the last day
  ending at midnight in the Location's time; a return visit giving seven more
  days and never fewer; the Lead held until the last pending proposal
  expires; moving the day and what is kept; four refusals, each at the day;
  30 days for a salesperson and further for a manager; who may move it and
  only while pending; a correction and an undone lost Lead starting the days
  again; the warning two days before, once, with nothing about the customer;
  a second warning after the day is moved on; four Leads nobody is warned
  about; the setting, its limits and who may change it; proposals that were
  pending before the clock existed.
- `PendingClockScreensTest.php` (11): the card's days left and the line about
  leaving; the last day and expired; no clock on a sold proposal and no line
  with a return visit booked; changing the day from the dialog and its
  refusal; the calendar's list in order; a manager's and another
  salesperson's view; Leads that are not on the list; the settings page and
  its refusals.

Existing tests that changed, none weakened or removed: three audit records in
`ProposalsTest.php` and `ProposalResultsTest.php` now also hold the day.

## Architectural decisions made while building

Listed in the plan under "Step 9a as built" and in decision 137. The ones
Jacob may want to change:

- **A proposal typed in late is valid until today at the earliest.** A
  proposal entered ten days after the visit would otherwise be expired the
  moment it was saved.
- **A proposal that a correction makes pending again starts its seven days
  again** from the day of the correction. The same when a lost Lead is
  undone.
- **Presenting a proposal again never shortens its time.** If a manager gave
  it until December, a return visit in October does not cut that to seven
  days.
- **A day cannot be moved to before today.**
- **A new number of days in the settings applies from then on.** A proposal
  that is already pending keeps its day.
- **The warning is counted with the timer's other warnings,** so the timer's
  report did not change shape.

## Deviations from the approved plan

One, small: the plan's table names a column `pending_warned_at` (when the
salesperson was warned). It is `pending_warned_for` (the day they were warned
about), so that moving the day on brings a new warning without anything
having to be reset.

## Known limitations

- **Nothing is released yet.** The card and the bell say the Lead "goes to
  the Contact Center for rehash" after its last day. Until step 9b is merged
  nothing moves it: it stays with the salesperson, shown as expired.
- **A Lead run with nothing presented has no clock yet.** Answer 5 gives it
  one; it is built in 9b with the release it belongs to.
- **"Still pending" does not add days.** Only presenting the proposal again,
  or moving the day, does.
- **An expired proposal can still be sold.** The day does not lock the price.
- **A number above 90 in the setting is stopped by the browser** before it
  reaches the server; the server refuses it too, which the tests prove.
- Local database only: the review moved one fictitious proposal's day and
  left it at tomorrow, and saved the setting to 10 and back to 7.

## Decisions needed from Jacob

1. **Review step 9a** and say whether to merge.
2. **Then step 9b,** Rehash: the release, the Rehash call queue with what was
   pitched on the card, taking a Lead back, and the clock for a Lead run with
   nothing presented. It is approved by decision 137 and starts on his word
   after this review, as each step stops for one.

## Browser review of step 9a (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and Leads, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**

| Step | Result |
| --- | --- |
| 1. The card | Pass. As the salesperson, a pending proposal showed "Valid until Tue, Oct 20 · 7 days left", matching the tables, and the card said "After Tue, Oct 20 this lead goes to the Contact Center for rehash, unless a return visit is booked or it is sold or lost." |
| 2. Moving the day | Pass. "Change" opened with the day filled in and "You can move it up to 30 days ahead. A manager can set a later day." Forty-five days ahead was refused at the day: "You can move it up to 30 days ahead, to Nov 12, 2026. A manager can set a later day." Ten days ahead saved: "Proposal changed.", and the card read "Valid until Fri, Oct 23 · 10 days left". |
| 3. Sales > Calendar | Pass. "Pending bids" listed the salesperson's three Leads with something pending, the same three the tables hold, soonest to expire first, each with what was pitched, the price and the days left. At a phone's width the list fitted the screen. |
| 4. A manager, and the warning | Pass. A manager moved the day sixty days ahead with no refusal, then to tomorrow. After the timer ran, the salesperson's bell had "A pending bid of yours expires tomorrow. The lead then goes to the Contact Center for rehash, unless a return visit is booked or it is sold or lost." |
| 5. The setting | Pass. Settings > Contact Center showed "Pending proposals" with 7. The browser would not send 120. Saved as 10: "Days saved."; put back to 7. |
