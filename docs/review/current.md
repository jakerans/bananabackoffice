# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-10b-colors`

**State:** slice 10, step 10b is built and waits for Jacob's review and his
word to merge. `main` and staging do not have it.

Step 10a is merged, on 2026-10-13 with Jacob's word ("Merge and start.",
decision 142). Both GitHub jobs passed on the merge and staging deployed it.

## Step

Slice 10, step 10b: the Color Specification. Plan:
[../slice-10-pre-production-plan.md](../slice-10-pre-production-plan.md),
"Step 10b" and "Step 10b as built". From the plan:
[../product-architecture.md](../product-architecture.md), 2, 6.4 and 6.8;
decision 141, questions 4 to 7.

Objective: a sold job has one record of its colors that Production paints
from, that the customer approved, and that is kept as it was whenever it
changes.

## What changed

**A "Colors" part of the Project page,** once the job has a Production
Manager.

- **One line for each surface of each area:** "Living room · Walls", then
  "Sherwin-Williams · Agreeable Gray (SW 7029) · Eggshell · Duration Home".
  The area, surface, manufacturer, color name and sheen are needed. The code,
  the product and a note are optional.
- **It starts from the handoff:** what the salesperson answered about colors
  is shown above the list.
- **"With the customer for approval"** records that the colors have gone to
  the customer. Backoffice sends nothing.
- **"The customer approved"** records who said yes, how (in person, by phone,
  by text or by email) and on which day. It then reads "Approved by Pat
  Customer, by phone, on Oct 8, 2026."
- **Changing approved colors starts a new version.** The page says so before
  saving: "The customer approved version 1. Saving this starts version 2,
  which needs their approval again." Version 1 stays readable under "Earlier
  versions", exactly as it was approved.

**Color Readiness** is a state of its own, shown as a badge on the Project and
on each job in Production > Workspace: Not started, In progress, Waiting for
the customer's approval, Approved.

**Who:** the Project's Production Manager, or a manager there. The
salesperson, and anyone else who may see the Project, reads the colors.

**Database:** two new tables and one new column on projects. **No new
permission, no new package, nothing sent to anyone.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,813 tests (2,812 passed, 1 skipped) in about 455 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 37, in `tests/Feature/Projects/`:

- `ColorSpecificationTest.php` (31): the first line starting version 1 and
  what is kept; changing and removing a line in the same version; nine
  refusals, each at its field, with nothing started; who may write, as a
  matrix of six people; a line that is another Project's or made up; a Project
  with no Production Manager and a cancelled one; marking as with the
  customer, once, and a change taking it back; the approval and what is kept;
  five refusals of an approval; colors with no line, and who may approve;
  changing approved colors starting version 2 with version 1 compared before
  and after; adding and removing starting it too; an unchanged save starting
  nothing; and approved lines refusing to be edited or deleted.
- `ColorScreensTest.php` (6): the Colors part appearing once a Production
  Manager has the job, with the handoff's answer; writing, sending and
  approving from the page; changing an approved color and seeing version 1 as
  it was; removing a line and a line the browser made up; what readers are
  and are not offered; and the badge on the Project and in the Workspace.

Existing tests: none changed.

## Architectural decisions made while building

Listed in the plan under "Step 10b as built". The ones Jacob may want to
change:

- **Manufacturer and sheen are needed on every line,** because closeout needs
  them (6.8).
- **Any change to approved colors needs approval again,** a corrected spelling
  included.
- **An approval recorded by mistake is not undone.** It is put right by
  changing the colors, which starts the next version.
- **"With the customer for approval" is optional.** The approval can be
  recorded without it.
- **Each line added, changed or removed is in the audit record, and not in
  the customer's Activity,** which shows colors started, with the customer,
  approved, and a new version.
- **Lines stay in the order they were added.**

## Deviations from the approved plan

None from decision 141.

## Known limitations

- **The approval is somebody's word** that the customer approved, until the
  portal lets the customer do it.
- **Nothing tells the customer** their colors are waiting, and nothing tells
  the Production Manager that an approval is overdue.
- **No photos or documents** of colors: Backoffice stores no uploaded files
  yet.
- **Lines cannot be reordered** and a whole room cannot be copied to another.
- **Readiness as a whole is not built:** step 10c.
- Local database only: the review left one fictitious job with a version 2
  being written and version 1 approved.

## Decisions needed from Jacob

1. **Review step 10b** and say whether to merge.
2. **Then step 10c,** readiness: one checklist on the Project, and the
   Workspace split into "Getting ready" and "Ready to schedule". It is
   approved by decision 141 and starts on his word after this review. It is
   the last step of slice 10.

## Browser review of step 10b (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and jobs, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**

| Step | Result |
| --- | --- |
| 1. Before anything is written | Pass. As the Production Manager the Project read "Colors: not started", "From the handoff: The customer will choose" and "No colors are written down yet." |
| 2. Writing lines | Pass. Saved empty, a line was refused at five fields: the area, the surface, the manufacturer, the color's name and the sheen. Two lines were written, and the badge at the top of the Project changed to "Colors: in progress" with "Version 1". |
| 3. With the customer, then approved | Pass. "With the customer for approval" changed the badge to "Waiting for the customer's approval". "The customer approved" opened with the Project's contact and today filled in; with no "how" it was refused. Saved: "Approval recorded. These colors are now the ones to paint.", the badge read "Colors: approved", and the Activity had "Colors approved by the customer". |
| 4. Changing an approved color | Pass. The dialog warned "The customer approved version 1. Saving this starts version 2, which needs their approval again." Saved: "Saved as version 2. It needs the customer's approval again.", "Version 2", "In progress", and under "Earlier versions" version 1 with both lines as they were approved. |
| 5. Removing a line | Pass. Removed from version 2 only; still two versions. |
| 6. The Workspace | Pass. The job read "Mid to late January 2027 · Colors: in progress". |
| 7. The salesperson | Pass. They read the colors and were offered no button. |
| 8. A phone's width | Pass. The Colors part fitted the screen. |
