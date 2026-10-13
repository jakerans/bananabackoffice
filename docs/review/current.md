# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-8b-handoff`

**State:** slice 8, steps 8a and 8b are both built and wait for Jacob's review
and his word to merge. `main` and staging have neither. This branch holds
everything: the slice 8 plan, decisions 130 to 133 with the stakeholder
discovery record, step 8a and step 8b. Merging it brings them together.

Jacob said on 2026-10-13 that the answers were updated and the blocker was
gone, and to get started. He has not yet said to merge step 8a.

## What Jacob's three commits changed, as read back

Read in full before building: decisions 131 to 133,
[../stakeholder-discovery-open-questions.md](../stakeholder-discovery-open-questions.md)
and every changed passage of the product architecture.

- **For step 8b (decision 132), built here:** eight required handoff questions
  and an optional ninth, the eighth being the customer's priorities and
  expectations from WNBET discovery; "None" is an answer; Production sees a
  waiting Project and cannot take it until the handoff is complete; a prompt
  on every page and one bell and email reminder the next morning; the
  questions fixed for now. And one property for each proposal, the Lead's
  unless another is chosen, which its Project takes.
- **Confirmed: nothing now blocks step 8b.**
- **Settled for later work, not built here (decisions 131 and 133):**
  compensation plans, the deposit, payments and A/R timing, closeout, colors,
  subcontractors, PFAD, the QuickBooks boundary and the monthly close.
  Decision 133 says it records policy and authorizes no build outside an
  approved slice.
- **Three places where what is already built differs from what is now
  decided.** None was changed. See "Decisions needed", 3.

## Step

Slice 8, step 8b: the handoff. Plan:
[../slice-8-projects-plan.md](../slice-8-projects-plan.md), "Step 8b" and
"Step 8b as built". From the plan:
[../product-architecture.md](../product-architecture.md), 6.2, 6.3 and 20;
decisions 130 and 132. Step 8a is described in the same plan under "Step 8a as
built"; its handoff was the previous version of this page.

Objective: a sold job reaches Production with what the Production Manager
needs to know, and Production can see what is coming, take it and know whose
it is.

## What changed in step 8b

**The property of a proposal.**

- "Add proposal", "Change" and the after-action report ask which of the
  customer's properties the proposal is for, when the customer has more than
  one. It starts on the Lead's.
- The Project is for that property and named for its address. Work at two
  houses is two proposals and two Projects.

**The handoff.**

- A page of its own, made for a phone, with the nine questions: what is
  included; what is not; what was promised beyond the proposal; timing;
  access; colors (chosen, the customer will choose, or a color consultation
  is needed); condition and prep; what matters most to this customer; and an
  optional "anything else".
- Every question but the last needs an answer, and "None" is one. If the
  colors are chosen it asks which.
- For the salesperson who sold it, or a manager. It can be corrected later;
  what the answers were is kept and the Production Manager is told.
- **Production cannot take a Project until it is done,** and a manager cannot
  assign it either.
- **The salesperson is prompted on every page** while a job they sold has no
  handoff: "You have 1 sold job waiting for your handoff. Production cannot
  take it until you answer the handoff questions. Complete it now".
- **One reminder,** in the bell and by email, at 8:00 the next morning.
- The Lead's Proposals card and the Project page both say the handoff is
  needed and link to it.

**Production > Workspace** is now a real page, where "Coming soon" stood.
Three lists for one Location:

- **New from Sales:** handed over and ready, longest-waiting first, each with
  the customer, what was sold, for how much, by whom and how long ago. A
  Production Manager has "Take it"; a manager has "Assign".
- **Waiting for the salesperson's handoff:** sold and coming, marked "No
  handoff yet", with nothing to take.
- **Pre-production:** jobs that have a Production Manager, the viewer's own
  first. A manager can change one, with a note.

**Telling people,** all in the bell: the Production Managers at the Location
when a job is ready; the one a job is assigned to; the one it is taken from;
the salesperson, who is told who has their job; the Production Manager when a
handoff is changed or a sale is corrected.

**The Project page** shows the handoff answers, the Production Manager, and
"Take this project" for a Production Manager who may.

**Database:** two new tables and four new columns on projects. **No new
permission, no new package, nothing sent to a customer.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,637 tests (2,636 passed, 1 skipped) in about 410 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 84, in `tests/Feature/Projects/`:

- `ProposalPropertyTest.php` (9): the default and a chosen property; another
  customer's, an archived one and one that does not exist refused; the Project
  taking the proposal's property and its address; changing a pending
  proposal's property and what is kept; the card asking only when there is a
  choice; the browser sending another customer's property; the report.
- `ProjectHandoffTest.php` (39): completing it and who is told; "None";
  each of eleven refusals at its own question, and all of them at once; who
  may, as a matrix of seven people; a correction, what is kept and who is
  told; a cancelled Project; the reminder at 8:00 and not at 7:59, once, in
  the app and by email, by the Location's own morning, and not when it is not
  owed; every notice read for the customer's name and the address; the
  prompt; who may open the page; the form itself; the Project page and the
  Lead's card.
- `ProductionManagerTest.php` (24): taking, the history and who is told; who
  may take, as a matrix; nobody taking or being given a Project before its
  handoff; two people taking one after the other; assigning; six people who
  cannot be given a Project; who may assign; changing with a note; a corrected
  sale; holding a Project granting nothing.
- `ProductionWorkspaceTest.php` (12): the page being live and who may open it;
  each job in the right list; taking and assigning from the page; a job
  somebody else took a moment ago; a job waiting for its handoff refused
  whatever the browser sends; only the Location shown, and never one that is
  not theirs; cancelled Projects in no list; taking from the Project page.

Existing tests that changed, none weakened:

- `QueueTickTest.php`: the timer's result has one more count,
  `handoffs_reminded`.
- `ProposalResultsTest.php`: the record kept when a proposal is changed now
  also holds its property.

## Architectural decisions made while building

Listed in the plan under "Step 8b as built". The ones Jacob may want to
change:

- **The handoff is saved whole,** not kept half-finished as a draft.
- **A manager cannot assign before the handoff either.**
- **Production is told when a job is ready to take,** not at the sale.
- **"The next morning" is 8:00 am** in the Location's time.
- **A manager assigns and does not take.** Only someone with the Production
  Manager role at that Location can hold a Project.
- **No notice carries the Project's title,** because the title has the street
  address in it. A notice says "A sold job at Northside is ready for
  Production." and links to it.

## Deviations from the approved plan

None from decisions 130 and 132.

## Known limitations

- **No reminder after the first.** The prompt stays until the handoff is done.
- **Nothing escalates** a handoff that is still not done after days. A manager
  can complete it themselves, and sees it in Production > Workspace.
- **A Production Manager is one person.** No Coordinator (decision 130).
- **Pre-production is one state.** Readiness, colors, the schedule and the
  rest of 6.3 are later slices.
- **The deposit-paid handoff call** from the stakeholder answers needs
  payments, which are not built.
- **The Project page still has no menu row behind it** for someone who came
  from Sales. Production reaches it from the Workspace, which has one.
- **Two people pressing "Take it" at the same instant** is guarded by a lock
  on the Project's row and tested one after the other, not with two real
  processes at once.
- Local database only: a fictitious Production Manager at Northside and a
  second fictitious property were added for the review, and the review left
  two Projects handed over.

## Decisions needed from Jacob

1. **Review 8a and 8b** and say whether to merge. They are on one branch and
   merge together.

2. **A Project's title.** It starts with the Lead's main service, as decision
   130 kept it. Now that each sold proposal is its own Project, that can read
   oddly: in the browser review a deck sold from an interior-painting Lead
   became "Interior Painting — 9 Lake Rd". Recommended: name it for what was
   sold, "Lake house deck — 9 Lake Rd". It stays editable either way.

3. **Three places where what is built differs from what decision 133 now
   says.** Decision 133 does not authorize changing them, so they are as they
   were:
   - **Close Rate, whose win it is.** The plan now says the win follows the
     salesperson who owned the Lead when the proposal was sold. Sales >
     Performance counts it for the salesperson of the Lead's first run
     appointment. They differ only when a Lead changes salesperson between
     its first visit and the sale.
   - **Corrections.** The plan now says a correction makes "an auditable
     metric adjustment rather than silently rewriting history". Today a
     corrected sale simply stops counting; the correction is in the audit
     record, but there is no adjustment entry in the figures.
   - **A no-demo's coaching reason.** The plan now says an appointment run
     with nothing presented "records the coaching reason". Nothing asks for
     one today.

   Recommended: one small step after slice 8, "Sales figures follow decision
   133", for all three.

4. **What comes after slice 8.** See "The plan check" below.

## The plan check after slice 8

Decision 122 asks for this after each slice.

- **Built from the plan in slice 8:** a Project when a sale is Won (rule 13);
  separate states (2); the Sales to Production handoff, PM assignment and the
  start of pre-production (6.2, 6.3); every ownership transfer telling the new
  owner (20); the handoff questions and property rule (decision 132).
- **In 6.2 and 6.3 and not built:** the confirmation email or text; the
  Coordinator; customer onboarding; Color Readiness; rough scheduling; work
  orders; crew and subcontractor assignment; and everything after.
- **Built and not in the plan before its decision:** the one-time step for
  proposals already sold; the readings listed above.

**What the plan says could be next,** none of it approved:

1. **Pending ownership and Rehash** (5.5). Decision 131 unblocked it: a
   proposal is valid for seven days by default, Sales ownership ends with it,
   and the Pending then goes to Contact Center Rehash. Today a Pending has no
   clock at all. Recommended next, with the small step in 3 above: both
   finish what Sales already does before Production's side grows.
2. **Pre-production** (6.3, 6.4): rough timeframe, colors and readiness on
   the Project. This is the next step on the customer's path.
3. **The deposit and payments** (10.4, 10.6): needed for the deposit-paid
   handoff call, the commission advance and every Finance rule now settled.
   It touches QuickBooks, whose integration check has not been done.

Each needs its own plan and Jacob's approval before anything is built.

## Browser review of step 8b (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and Leads, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**
The handoff was filled in at a phone's width. Step 8a's review is recorded in
the previous version of this page.

| Step | Result |
| --- | --- |
| 1. The prompt | Pass. The salesperson, with two sold jobs not handed over, saw "You have 2 sold jobs waiting for your handoff. Production cannot take it until you answer the handoff questions. Complete it now", linking to the older one's handoff. |
| 2. Another house | Pass. "Add proposal" offered the customer's two properties. A proposal added for the second showed "For: Lake house (Sample) — 9 Lake Rd". Sold: its Project was named for 9 Lake Rd, and the card showed "Complete the handoff for Production". |
| 3. The handoff | Pass. The page named the Project, the customer, the property and what was sold. Saved empty, it refused eight questions, each at its own field. "Which colors?" appeared only after "Chosen". Completed: "Handoff complete. Production can take this project now."; the Project page showed every answer and the Lake house as its property. |
| 4. The Production Manager | Pass. Their bell had "A sold job at Northside is ready for Production." Workspace listed the job under "New from Sales" with the customer, what was sold, $2,400 and "Sold by Sam Sales", and the other sold jobs under "Waiting for the salesperson's handoff" with nothing to take. "Take it": "It is yours", and it moved to Pre-production as "Yours". They were not offered "Assign". |
| 5. A manager | Pass. A manager completed another Project's handoff, then in Workspace was offered "Assign" and not "Take it". The dialog offered the Location's Production Manager; saved, the job moved to Pre-production under their name. Choosing the same person again was refused. |
| 6. Who was told | Pass. The salesperson's bell had "Remy Production (Sample) is now the Production Manager for a job you sold." twice, and their prompt was down to one job. The Production Manager's bell had "You were made the Production Manager of a project." |

Not covered in the browser: the next-morning reminder, which needs a day to
pass and which the automated tests cover minute by minute; and changing a
Production Manager to another person, because the local Location has one.
