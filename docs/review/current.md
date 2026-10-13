# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-10a-onboarding-timeframe`

**State:** slice 10, step 10a is built and waits for Jacob's review and his
word to merge. `main` and staging do not have it.

Slice 9 is complete: step 9c is merged, on 2026-10-13 with Jacob's word
("Merge and approve.", decision 141). Both GitHub jobs passed on the merge and
staging deployed it.

## Step

Slice 10, step 10a: onboarding and the rough timeframe. Plan:
[../slice-10-pre-production-plan.md](../slice-10-pre-production-plan.md),
"Step 10a" and "Step 10a as built". From the plan:
[../product-architecture.md](../product-architecture.md), 6.2, 6.3 and 6.5;
decision 141.

Objective: once a Production Manager has a job, Backoffice shows whether the
customer has been called and roughly when the work is expected, and marks the
job that is falling behind.

## What changed

**The welcome call is a checklist item.**

- On a Project that has a Production Manager, the Project page has a
  "Pre-production" part. Until the call is ticked it reads "Customer not yet
  called."
- The Production Manager, or a manager, presses "Welcome call made", gives the
  day of the call and, if they want, a note. It then reads "Made on Tue, Oct
  13, 2026" with who ticked it.
- A tick made by mistake can be unticked, with a note saying why.
- Nothing is sent to the customer. How the call is made is the business's own
  procedure, outside Backoffice.

**Falling behind.** A job nobody has called gets a red "Falling behind" mark
two business days after its Production Manager took it, on its own page and
in Production > Workspace, for the Production Manager and for a manager.

**The rough timeframe.**

- An earliest and a latest start, with a note. It is shown in words, "Late
  November to mid December", with the two days under it and "Not a booked
  date."
- Changing it needs a reason. What it was is kept and shown: "Was: Late
  November to mid December".
- The salesperson sees it on the Lead, under the sold proposal, so they can
  answer the customer.

**Production > Workspace** now says what each job in pre-production still
needs: "Customer not yet called", "No rough timeframe", or the timeframe. A
person's own jobs come first; within that, the jobs with no timeframe, then
the soonest expected start.

**Database:** eight new columns on projects and one new table. **No new
permission, no new package, nothing sent to anyone.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,776 tests (2,775 passed, 1 skipped) in about 420 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. One layout-only change to the Project page was made after that run (a button kept beside its text); the Project tests were run again and passed. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 44, in `tests/Feature/Projects/`:

- `PreProductionTest.php` (37): ticking the welcome call, what is kept and
  ticking it again; who may, as a matrix of six people; four refusals, each at
  its field; a Project with no Production Manager and a cancelled one;
  unticking; falling behind at the right minute, across a weekend, for a job
  taken on a Saturday, and never for a Project that is not waiting; setting a
  timeframe and what is kept; changing one, the reason and both kept; six
  refusals; who may, as the same matrix; six ways a timeframe is said in
  words; and a kept timeframe refusing to be edited or deleted.
- `PreProductionScreensTest.php` (7): the pre-production part appearing only
  once a Production Manager has the job; ticking and unticking from the page;
  setting and changing the timeframe from the page, with its history; what the
  salesperson and another Production Manager are and are not offered; the
  "Falling behind" mark at the right minute on the page and in the Workspace;
  the Workspace's order and what each job needs; and the timeframe on the
  Lead.

Existing tests: none changed.

## Architectural decisions made while building

Listed in the plan under "Step 10a as built" and in decision 141. The ones
Jacob may want to change:

- **The tick asks for the day of the call and an optional note.** Not who
  they spoke to, and not a list of things covered.
- **Business days are Monday to Friday.** Backoffice does not know public
  holidays, so a holiday counts as a working day.
- **The two days run from when the job's present Production Manager got it.**
  If a manager gives the job to someone else, the two days start again.
- **"Falling behind" is a mark, not a message.** Nobody is sent a bell notice
  or an email.
- **Nothing is stored for "falling behind".** It is worked out each time from
  when the job was taken and whether the call is ticked.
- **A timeframe is shown in thirds of a month:** early is the 1st to the 10th,
  mid the 11th to the 20th, late the rest.
- **The salesperson is not told** when a timeframe is set or changed.

## Deviations from the approved plan

One, from decision 141 itself: the plan had the welcome call record "the day
they spoke, with whom, and what was agreed". Jacob made it a checklist item,
so it records the day and an optional note.

## Known limitations

- **Public holidays count as business days.**
- **A manager is not told** that a job is falling behind; they see it when
  they open the Workspace.
- **A rough timeframe reserves nothing.** No crew and no calendar is involved.
- **Colors and readiness are not built:** steps 10b and 10c.
- Local database only: for the review, the fictitious jobs at Northside were
  given a handoff and the fictitious Production Manager, one was made to look
  taken a week ago, and one was left with a welcome call ticked and a
  timeframe.

## Decisions needed from Jacob

1. **Review step 10a** and say whether to merge.
2. **Then step 10b,** the Color Specification: colors by area and surface, the
   customer's approval, and a new version when they change. It is approved by
   decision 141 and starts on his word after this review.

## Browser review of step 10a (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and jobs, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**

| Step | Result |
| --- | --- |
| 1. The Workspace | Pass. As the Production Manager, all five jobs in pre-production were listed, each with what it needs. The job taken that day read "Customer not yet called · No rough timeframe"; the one taken a week before also had "Falling behind". |
| 2. The welcome call | Pass. The Project read "Customer not yet called. Due within two business days of the job being taken, by Oct 15". "Welcome call made" opened with today filled in. A day three days ahead was refused: "The call cannot be on a day that has not come yet." Ticked: "Welcome call ticked.", then "Made on Tue, Oct 13, 2026" with the note and who ticked it. |
| 3. The rough timeframe | Pass. The days the wrong way round were refused: "The latest start cannot be before the earliest." Set: "Late November to mid December", "Work expected to start between Nov 24, 2026 and Dec 12, 2026. Not a booked date." |
| 4. Changing it | Pass. With no reason it was refused: "Say why the timeframe is being changed." With one it read "Mid to late January 2027", with "Now" and "Was: Late November to mid December" under it. |
| 5. Falling behind, and unticking | Pass. The job taken a week before read "Customer not yet called. Falling behind. It was due ... by Oct 8". It was ticked, then unticked: refused with no note, "Say why it is being unticked."; with one, "Unticked. This project is waiting for its welcome call again.", and the mark came back. |
| 6. The Workspace afterwards | Pass. The first job read "Mid to late January 2027" and no longer "Customer not yet called". |
| 7. The salesperson | Pass. On the Lead: "Rough timeframe: Mid to late January 2027. Not a booked date." On the Project they saw the pre-production part and were offered no button. |
| 8. A phone's width | Pass. The pre-production part fitted the screen. |
