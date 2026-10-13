# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-9c-pending-figures`

**State:** slice 9, step 9c is built and waits for Jacob's review and his word
to merge. `main` and staging do not have it. It is the last step of slice 9.
This branch also records decision 140: the repository is public.

Step 9b is merged, on 2026-10-13 with Jacob's word ("Merge and start.",
decision 139), and staging deployed it.

## The GitHub checks

**Both jobs have now run and passed** on step 9c and on step 9b's merge.

- On 2026-10-13 GitHub stopped starting the jobs: the month's free allowance
  of minutes for a private repository was used up. Its message was "The job
  was not started because recent account payments have failed or your
  spending limit needs to be increased."
- **Jacob made the repository public** the same day (decision 140). GitHub
  runs the checks on a public repository without that limit.
- The two runs it had refused were then run again on the same commits, and
  both jobs passed on each.
- What being public exposes, and what was checked first, is in decision 140
  and at the top of [../security-notes.md](../security-notes.md).

## Step

Slice 9, step 9c: the Pending figures. Plan:
[../slice-9-pending-rehash-plan.md](../slice-9-pending-rehash-plan.md), "Step
9c" and "Step 9c as built". From the plan:
[../product-architecture.md](../product-architecture.md), 5.2; decision 137,
answer 8.

Objective: Sales > Performance shows how many demos end pending, how many of
those leave with a return visit booked, and how many Leads that went to rehash
were sold afterwards.

## What changed

Sales > Performance has a third row of cards, for a month, a Location and one
salesperson or everyone:

- **Pending rate.** Of the Leads demoed for the first time in the month, the
  ones that left that demo with nothing sold and something still open. "1 of 2
  leads first demoed this month left the demo still pending."
- **Return visit booked.** Of those pending Leads, the ones with another
  visit booked by the end of the day of the demo. "1 of 1 pending lead had a
  return visit booked the day of the demo."
- **Rehash conversion.** Of the Leads that went from a salesperson to the
  Contact Center for rehash in the month, the ones sold since, by anyone. "1
  of 2 leads that went to rehash this month has been sold since."

"How these are counted" explains each. A salesperson sees their own; a manager
sees their Locations'.

**No database change, no new permission, no new package.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,732 tests (2,731 passed, 1 skipped) in about 390 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. Both ran only after the repository was made public; see "The GitHub checks" above. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 13, in `tests/Feature/Sales/PendingFiguresTest.php`: Pending Rate
for everyone and for each salesperson; a Lead sold a week later; a bid lost at
the demo, and one lost with another still open; a bid lost the day after; a
sale corrected in a later month, and one corrected the same day; a proposal
typed in days late; a return visit booked that day, the next day, and booked
in error; Rehash conversion before and after a sale, for the salesperson it
was released from, with cancelled releases left out; a Lead a manager sends
back by hand; a month with nothing; and the page.

Existing tests: none changed.

## Architectural decisions made while building

Listed in the plan under "Step 9c as built". The ones Jacob may want to
change:

- **"Nothing sold and nothing lost" is read as: nothing sold that day, and
  something presented still open.** A Lead with one proposal lost at the demo
  and another still open left pending.
- **Whether a Lead was sold at its demo is read from the record of sales,** so
  a sale corrected in a later month does not change the month of the demo
  (decision 133).
- **A return visit counts if it was booked by midnight of the demo's day,**
  even if it was cancelled later. One marked "booked in error" does not.
- **Rehash conversion is counted for the salesperson a Lead was released
  from,** whoever sold it afterwards. The sale itself is still the seller's.
- **A release to Rehash is now its own event in a Lead's history,** so it can
  be counted. This is a small change to what step 9b recorded.

## Deviations from the approved plan

None from decision 137.

## Known limitations

- **Rehash conversion for a past month goes up** when one of its Leads is
  sold later. That is what "later sold" means, and the page says so.
- **A proposal lost at the demo and corrected in a later month** does change
  the demo's month for Pending Rate. Only sales have a record that is never
  rewritten.
- **A Lead released to Rehash before this step is not counted,** because it
  was recorded as "returned from Sales". Staging has none.
- **Not built from the plan's list (5.2):** Average Job Size and
  Close-on-the-Spot Rate.
- Local database only: one fictitious Lead was made for the review, released
  and sold. The local web server stopped again during the review and was
  started again with `herd restart`.

## Decisions needed from Jacob

1. **Review step 9c** and say whether to merge. Both GitHub jobs have passed
   on it.
2. **What comes next.** Slice 9 is complete with this step. See "The plan
   check" below.
3. **When the repository goes back to private** (decision 140). Nothing is
   needed now; it is listed in the security notes to be settled before
   production holds real customer data.

## A plan for what could come next

**A proposed plan for slice 10, pre-production, is written and waits for
Jacob's approval:**
[../slice-10-pre-production-plan.md](../slice-10-pre-production-plan.md).
It is on its own branch, `checkpoint-slice-10-plan`. Nothing in it is built,
and he has not chosen it; it is the first of the three options below.

| Step | Delivers |
| --- | --- |
| 10a | **Onboarding and the rough timeframe.** The Production Manager records the welcome call and when the work is roughly expected. The Workspace shows what each job still needs. |
| 10b | **The Color Specification.** Colors by area and surface, the customer's approval, and a new version when they change. |
| 10c | **Readiness.** Whether a job is ready to be scheduled, and what is holding it. |

It has nine questions, each with a recommendation. To start 10a he needs to
approve it and answer the first three: what the welcome call is, how a rough
timeframe is written, and whether the Workspace flags a job nobody has called.

## The plan check after slice 9

Decision 122 asks for this after each slice.

- **Built from the plan in slice 9:** Pending as a clock, with "Offer Valid
  Until" and "Salesperson Ownership Until" kept separate (5.5); the release to
  Contact Center Rehash (5.5 and 20); the Rehash queue (4.2); taking a Lead
  back (rule 12); Pending Rate, % Pending with Rehash booked, and Rehash
  conversion (5.2).
- **In 5.2 to 5.6 and not built:** Average Job Size and Close-on-the-Spot
  Rate; targets; the Leaderboard; Sales confirmation calls; the Sales
  Workspace's selling flow (discovery, pricing, options, agreement, deposit);
  Google Calendar; the booking engine; the voice note; commission.

**What the plan says could be next,** none of it approved:

1. **Pre-production** (6.3, 6.4): rough timeframe, colors and readiness on a
   Project. It is the next step on the customer's path after the handoff, and
   needs nothing that is not built. Recommended next.
2. **The deposit and payments** (10.4, 10.6): needed for the deposit-paid
   handoff call, the commission advance and every Finance rule now settled.
   It touches QuickBooks, whose integration check has not been done.
3. **The two small Sales figures left,** Average Job Size and
   Close-on-the-Spot Rate (5.2). Small, and all the records they need exist.

Each needs its own plan and Jacob's approval before anything is built.

## Browser review of step 9c (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and Leads, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**
The salesperson's month was first counted a second way, straight from the
tables, to compare the page against.

| Step | Result |
| --- | --- |
| 1. Pending Rate and return visits | Pass. For the salesperson's month the page read "9 of 9 leads first demoed this month left the demo still pending", 100%, and "1 of 9 pending leads had a return visit booked the day of the demo", 11%. Both matched the separate count. |
| 2. A Lead goes to rehash | Pass. After the timer released a Lead whose bid had expired, Rehash conversion went from "No lead went to rehash this month." to "0 of 1 lead that went to rehash this month has been sold since.", 0%. |
| 3. It is sold | Pass. The call center booked it a return visit, it was run, presented again and sold. The same month then read "1 of 1 lead that went to rehash this month has been sold since.", 100%. |
| 4. How it is counted | Pass. "How these are counted" has a line for each of the three. |
| 5. The salesperson, and a phone | Pass. The salesperson saw the same three figures for themselves. At a phone's width the cards fitted the screen. |
