# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-8a-project`

**State:** slice 8, step 8a is built and waits for Jacob's review and his word
to merge. `main` and staging have none of it. This branch sits on top of the
slice 8 plan branch (`checkpoint-slice-8-plan`, also not merged), so merging it
brings the plan, decision 130 and the build together.

**The step 8b product questions are settled** by decision 132. The exact
handoff and property rules are now in the slice 8 plan. Step 8a still waits for
the word to merge; implementation has not moved on to 8b.

## Step

Slice 8, step 8a: the Project. Plan:
[../slice-8-projects-plan.md](../slice-8-projects-plan.md), "Step 8a" and
"Step 8a as built". From the plan:
[../product-architecture.md](../product-architecture.md), 2, 6.2 and 20;
rulebook rule 13; decision 130.

Objective: the moment a proposal is sold there is a Project for it, with what
was sold, for how much, for whom and where, that Sales, Production and Finance
can each open.

## What changed

- **A Project is made the moment a proposal is marked Sold,** from the card or
  from the after-action report, with nothing more to fill in.
- **One Project for each sold proposal** (decision 130). A Lead with two sold
  proposals has two Projects.
- **It carries:** a title ("Interior Painting — 123 Oak St", editable), the
  customer, contact and property, the Location, the services and project
  size, the proposal and its sold amount, and who sold it and when.
- **The Project page** shows all of that, the appointments the proposal was
  presented at, its states (Sold; Production: New from Sales) and its
  Activity. A manager can edit the title and add a description.
- **On the Lead,** each sold proposal shows "Project: ..." with a link.
- **On the customer page,** a new Projects section. **In the customer's
  Activity,** "Project created from a sale".
- **If a manager corrects the sale,** the Project is marked "Cancelled: sale
  corrected" with the note, and kept. If the proposal is sold again, the same
  Project comes back.
- **Two new permissions,** as approved: who can see Projects, and who can edit
  one. A Production Manager and Finance can now open a Project; the Contact
  Center cannot, but sees its title and sold amount on the customer page.
- **Two new tables.** A one-time step gives a Project to any proposal that was
  already sold before this step. **No new package, nothing sent to a
  customer.**

Also in this branch: the slice 8 plan and decision 130.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,553 tests (2,552 passed, 1 skipped) in about 400 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed.** Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 38, in `tests/Feature/Projects/`:

- `ProjectFromSaleTest.php`: the sale making the Project in the same save,
  field by field; one Project for each sold proposal, and the second one's
  title; none for a lost or pending proposal; the after-action report making
  them; the salesperson being the Lead's whoever records the sale; the Project
  keeping what the Lead had when the Lead changes later; a corrected sale
  cancelling and keeping it, and the same one coming back when sold again; one
  correction leaving the Lead's other Project alone; the database refusing a
  second Project for a proposal; no deleting; who may see and who may edit, as
  a matrix of ten people; editing, what is kept, and its refusals; and the
  one-time step for proposals already sold.
- `ProjectScreensTest.php`: the page's contents; who may open it, as a matrix
  of seven people, and a guest; a Production Manager shown what they need with
  no link to the Lead or the customer; editing from the page; a cancelled
  Project; the link on the Lead's card, with and without the right to open it;
  the customer page's Projects section for a manager and for the Contact
  Center; and the customer's Activity.

Existing tests that changed, none weakened:

- `RoleTest.php` pins each role's permissions; it now includes the two new
  ones for the roles decision 130 named.

## Found while building

- **A proposal sold before this step would have had no Project.** Staging has
  none today, but one could be marked sold there before this merges. A
  one-time migration gives each such proposal its Project, and a test proves
  it makes the same Project a sale would have.

## Architectural decisions made while building

Listed in the plan under "Step 8a as built". The ones Jacob may want to
change:

- **A second Project from the same Lead also says what was sold in its
  title,** "Interior Painting — 123 Oak St (Deck)", because the service and
  address alone would read the same as the first.
- **The Project page shows the contact's phone and email and the property's
  address** to everyone who may see Projects there. A Production Manager
  cannot open the Lead or the customer page, so this is where they get them.
- **The customer page lists a Project** to anyone who may see Projects at its
  Location or may see its Lead. The Contact Center sees the title and the
  sold amount that way, and cannot open it.
- **Nobody is told when a Project is made.** Telling Production is step 8b.

## Deviations from the approved plan

None from decision 130.

## Known limitations

- **A Project is on the Lead's property.** Jacob's "separate houses" needs a
  choice of property on a proposal. See "Decisions needed", 3.
- **The Project page has no menu behind it.** It belongs to no menu item until
  step 8b lists Projects in Production > Workspace, so the second row of the
  menu is empty on it. It is reached from the Lead and the customer.
- **Production is not told and has no list yet.** Step 8b.
- **No handoff yet.** Step 8b.
- Local database only: the review left one fictitious Project, and the
  one-time step made one for a proposal sold in an earlier review.

## Decisions needed from Jacob

The broader stakeholder questionnaire has also been reconciled. Its answered
items are now in the product architecture; all 31 questions and approved
answers are in
[../stakeholder-discovery-open-questions.md](../stakeholder-discovery-open-questions.md).
The first two are decision 132 and the remaining 29 are decision 133. None is
waiting on an answer. Intentional deferrals wait for evidence or their future
slice rather than inviting a builder to guess.

1. **Review 8a** and say whether to merge it.

2. **The handoff questions for step 8b — answered as proposed in decision
   132.** Jacob asked for questions the
   salesperson has to complete, because thin handovers have caused trouble
   for project managers. Proposed, for him to cut, add to or reword:

   | # | Question | Answer |
   | --- | --- | --- |
   | 1 | **What exactly is included?** Rooms, sides of the house, surfaces, coats. | Text, required |
   | 2 | **What is not included,** that the customer might assume is? | Text, required ("Nothing" is an answer) |
   | 3 | **What did you promise the customer** beyond the proposal? Dates, extras, touch-ups, a discount. | Text, required ("Nothing") |
   | 4 | **When does the customer expect the work?** Any date that must be hit or avoided. | Text, required |
   | 5 | **Access.** Who will be home, gate or lockbox codes, parking, pets. | Text, required |
   | 6 | **Colors.** | One of: Chosen (say which) / The customer will choose / Needs a color consultation |
   | 7 | **Condition and prep.** Peeling, rot, repairs, heights, anything that will take longer than it looks. | Text, required |
   | 8 | **Anything else Production should know** about this customer or job? | Text, optional |

   And three rules to go with them. Recommended:

   - **(a) Production cannot take a Project until its handoff is complete.**
     Until then it is listed for Production as "Waiting for the salesperson's
     handoff", so they can see it coming.
   - **(b) The salesperson is prompted on every page** while a sold job of
     theirs has no handoff, the same way as "You have 1 appointment to close
     out", and reminded once in the bell and by email the next morning.
   - **(c) The questions are fixed in Backoffice for now,** not a list Jacob
     edits. Changing them is a small change to make when he asks. An editable
     list of questions can come later.

3. **Separate houses — answered as recommended in decision 132.** Today a proposal, and so its Project, is on the
   Lead's property. To sell work on another of the customer's houses from the
   same Lead, "Add proposal" would ask which of the customer's properties it
   is for, starting on the Lead's. Recommended: add it with step 8b. The
   alternative that works today is a new Lead for that property.

## The plan check for this step

- **From the plan:** rule 13 (a Project when the sale is Won); section 2
  (separate states, responsibility is not permission); section 20 (the path
  after Won); decision 36 (the sold amount comes from the proposal).
- **In the plan and not built in this step:** the handoff, Production's
  Workspace and the Production Manager (step 8b); the confirmation to the
  customer, the Coordinator, colors, readiness and the schedule (later).
- **Built and not in the plan:** the one-time step for proposals already sold.

## Browser review (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed.** The only errors the browser reported were the two
refusals that step 5 expects.

| Step | Result |
| --- | --- |
| 1. A sale | Pass. The salesperson marked a pending proposal sold for $5,400: "Sold: Interior, main floor. The lead now shows Sold." The card showed "Project: Interior Painting — 7119 Oak St" as a link, where there had been none. |
| 2. The Project page | Pass. The title; "Sold" and "Production: New from Sales"; the proposal, "Priced at $5,600", "Sold for $5,400", the salesperson, the appointment it was presented at; the customer, contact and property; the service and size; Activity "Project created from a sale". The salesperson was not offered "Edit title". |
| 3. A manager edits the title | Pass. An empty title was refused ("Give the project a title."). Saved with a description: "Project saved.", both shown, and Activity "Title or description changed". |
| 4. The customer | Pass. The customer page listed the Project with its proposal, property, Location, salesperson, $5,400 and "Sold", as a link. The customer's Activity showed "Project created from a sale". |
| 5. Who cannot open it | Pass. A Production Manager of another Location was refused the page (403). A Call Center Agent was refused the page (403) and, on the customer page, saw the Project's title with no link. |
| 6. A corrected sale | Pass. After "Correct result" the Lead went back to "Appointment run" and the card no longer showed the Project. The Project page read "Cancelled: sale corrected" with the manager's note and "If the proposal is sold again, this project comes back.", and offered no edit. Sold again, the card linked to the same Project. |

Not covered in the browser: a Production Manager at the Project's own
Location, because the local sample Production Manager is at another one. The
automated tests cover that view.
