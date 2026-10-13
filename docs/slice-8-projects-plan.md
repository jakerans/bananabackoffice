# Slice 8 — A Project When a Sale Is Sold, and the Handoff to Production: Proposed Plan

Status: **approved on 2026-10-13 (decision 130) in two steps, each stopping
for Jacob's review. Three of his answers changed the plan below: one Project
for each sold proposal (not one for the Lead), the cancellation rule accepted
"for now", and a handoff made of questions the salesperson has to complete.
Step 8a is approved to build. The handoff questions and property-per-proposal
rule were agreed on 2026-10-13 (decision 132); no product question now blocks
step 8b. Both steps were built and merged into `main` on 2026-10-13 with
Jacob's word ("merge it", decision 134). Slice 8 is complete. Decision 134
also changes how a Project is named.**

It follows [product-architecture.md](product-architecture.md), sections 2, 6.2,
6.3 and 20, the slice 5 rulebook
([slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md), rule 13),
and builds on slice 7
([slice-7-appointment-results-plan.md](slice-7-appointment-results-plan.md)).

## Why this is next, from the plan

1. **The plan's own order.** Section 20 gives a customer's path. Slices 4 to 7
   built it as far as "Pending/Rehash/Won/Lost". The next line is "If Won:
   Sales → Production handoff → Coordinator if present → PM".
2. **Rule 13 says when a Project begins:** "A Project is created when the sale
   is Won." Since step 7c a sale can be marked Sold, and nothing happens next.
   A sold job has nowhere to go.
3. **Everything in Production reads from it.** Pre-production, colors, the
   schedule, work orders, payments and job costing (plan, 6 to 10) all hang on
   a Project. None can be built before it exists.
4. **What else could have been next, and why not.** Pending ownership and
   Rehash (5.5) needs a period Sales Leadership has not confirmed. Commission
   (5.7) needs rules that are theirs too, and "only when the full deposit is
   collected", which needs payments. Both can wait; a sold job cannot.

## What changed in the plan since Projects were last discussed

Projects were planned once before (decision 54, step "B8", 2026-10-06) and not
built. The newer plan overrides parts of that, and the rulebook says decision
54 "gives no authority" until it is looked at again. This is that look:

| Decision 54 said | Now |
| --- | --- |
| A Project is created from a Qualified Lead, by a person | **Replaced by rule 13:** it is created when the sale is Sold. |
| A Project's sales outcome starts as Open, then Won or Lost | **Replaced by decision 124:** Pending, Sold and Lost belong to proposals. A Project exists only because something was sold. |
| The Sold Amount is typed in (decision 36) | **Decision 36 already said** the accepted proposal becomes the source once proposals exist. They exist. |
| A shared Projects page in the Sales and Production menus | **Replaced by the plan, 6.2:** "Do not create separate sidebar pages for Projects". Production works from its Workspace. |
| A required title, "Primary Service — short street address" | Kept. |
| A Project carries its own Services and Project Size, copied from the Lead | Kept. |
| Property and Location required; the Lead is where it came from | Kept. Every Project in this slice comes from a Lead. |
| A Sales Consultant and a Production Manager responsibility, which grant no access | Kept, as "Responsibility is not permission" (plan, 2). |

## What slice 8 is, and is not

Two reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 8a | **The Project.** One is made the moment a proposal is Sold, with what was sold and for how much. A Project page. It shows on the Lead and on the customer. | Rule 13; plan 2 and 20; decisions 36 and 130 |
| 8b | **The handoff.** The questions the salesperson has to complete for Production. Production > Workspace becomes a real page: sold jobs waiting for a Production Manager, and the ones each has. Assigning the Production Manager. Production is told when a job arrives. | Plan 6.2, 6.3 and 20; decision 130 |

Not in slice 8, each needing its own plan and approval:

- **The confirmation email or text to the customer** (6.3). No message to a
  customer has been authorized in any slice.
- **Deposits and payments** (10.4), and anything in Finance.
- **Colors** (6.4), the **schedule** (6.5), **work orders** (6.6), **change
  orders** (6.7), **closeout** and **job costing**.
- **A Project Coordinator.** The plan says "Coordinator if present". Backoffice
  has no such role. See question 6.
- **A Project with no Lead,** for repeat work (decision 27).
- **Moving a Project to another Location,** archiving one, attachments and
  photos (the rest of decision 54).
- **Commission.**

## Step 8a — The Project

### What a person sees

**When a proposal is marked Sold** (from the card or the after-action report),
a Project is made at once, with nothing more to fill in:

- its **title**, "Exterior Painting — 123 Oak St", from the Lead's main
  service and the property's street address, which can be edited (changed by
  decision 134: it is now what was sold and the address, "Lake house deck —
  9 Lake Rd");
- the **customer, contact and property** the Lead has;
- the **Location** the Lead is at;
- the Lead's **services and project size**, copied so that changing the Lead
  later does not change the Project;
- **what was sold:** the proposal, with its name, its price and its **sold
  amount**, which is the Project's sold amount and is never typed again;
- **who sold it and when.**

**One Project for each sold proposal** (decision 130). A Lead with two
proposals sold, "Whole exterior" and "Deck", has two Projects, each handed to
Production by itself.

**The Lead page** shows each sold proposal's Project with a link. **The
customer page** gets a Projects section. **The Project page** shows all of the
above, the appointments the proposal was presented at, and its Activity.

**If the sale is corrected** (a manager puts a sold proposal back to pending,
step 7c), its Project is not deleted. It is marked "Cancelled: sale
corrected" and brought back if the proposal is sold again (question 4).

### What it stores

| Table | Holds |
| --- | --- |
| `projects` (new) | One row per Project: the proposal it was made for, the Lead that came from, Location, customer, contact, property, title, description, project size as it was, the sold amount, who sold it and when, and its states (below). Never deleted. One proposal can have only one. |
| `project_service` (new) | The Project's services, one marked as the main one, copied from the Lead. |

**States.** The plan forbids "one giant Project status" (section 2). This step
adds only what it uses, each its own field:

- **Sales:** Sold. (There is no other; a Project exists because of a sale.)
- **Production:** New from Sales, then Pre-production once a Production
  Manager has it (step 8b). Later slices add to this, and add the other
  dimensions (payment, colors, job cost) as fields of their own.

### Step 8a as built (2026-10-13)

Built as planned above, with decision 130. What a reader of the code should
know:

- **The sale makes the Project.** `RecordProposalResult` calls
  `CreateProjectForSoldProposal` inside the same save as the sale, so the
  after-action report makes one too. Nobody makes a Project by hand.
- **One Project for one proposal,** enforced by the database: `projects` has a
  unique `proposal_id`.
- **Tables:** `projects` and `project_service`. A Project keeps the Lead's
  Location, customer, contact, property, services and project size as they
  were at the sale, the proposal's sold amount and day, the salesperson the
  Lead was with, and who recorded the sale. It cannot be deleted.
- **States are separate fields:** `production_state` (only "New from Sales"
  so far) and the cancellation fields. There is no single status.
- **A corrected sale** (`CorrectProposalResult`) calls
  `CancelProjectForCorrectedSale`: the Project is marked cancelled with the
  manager's note and kept. Selling the proposal again brings the same Project
  back with the new amount and day.
- **Two new permissions,** `projects.view` and `projects.manage`, as decision
  130 approved them. `ProjectPolicy` decides everything at the Project's own
  Location.
- **The page** is `pages::projects.show` at `/projects/{project}`. It has no
  menu item (plan, 6.2). It is reached from the Lead's Proposals card, the
  customer's page and the customer's Activity.
- **Four audit events,** with the Project as their subject: `project.created`,
  `project.brought_back`, `project.cancelled`, `project.edited`. The first
  three are in the customer's Activity.
- **Proposals sold before this step** are given their Project by a one-time
  migration, so no sold proposal is left without one.

Readings made while building, for Jacob to change if wrong:

- **The title of a second Project from the same Lead** would read the same as
  the first, same service and same address. It also says what was sold:
  "Interior Painting — 123 Oak St (Deck)". *Replaced by decision 134: every
  Project is named for what was sold, so two at one address already differ.*
- **A proposal takes its Lead's property,** so a Project does too. Selling
  work on another of the customer's houses from the same Lead needs a choice
  of property on the proposal. Not built; see the handoff.
- **The customer page lists a Project** to anyone who may see Projects at its
  Location or may see the Lead it came from. That is how the Contact Center
  sees the sale and its amount (plan, 11.4) without being able to open the
  Project.
- **The Project page shows the contact's name, phone and email and the
  property's address,** because a Production Manager cannot open the Lead or
  the customer's page and needs them.
- **Only the title and description can be edited,** by a manager. A cancelled
  Project is not edited.
- **Nobody is told** when a Project is made in this step. Telling Production
  is step 8b.

## Step 8b — The handoff

### What a person sees

**Production > Workspace** (today "Coming soon") becomes a page with two
lists, for the Location:

- **New from Sales:** sold jobs with no Production Manager yet, oldest first,
  with the customer, the title, what was sold, the sold amount, the
  salesperson and how long it has waited.
- **Pre-production:** the jobs that have a Production Manager, by person.

**Assigning the Production Manager.** A manager at the Location assigns one,
choosing from the people with the Production Manager role there. A Production
Manager can also take a waiting job at their own Location. It can be changed,
with a note.

**The handoff.** Questions the salesperson has to complete for each Project,
so the Production Manager is not left guessing (decisions 130 and 132). The
first version requires:

1. exact included scope: rooms, sides, surfaces and coats;
2. exclusions the customer might otherwise assume;
3. promises outside the proposal: dates, extras, touch-ups or discounts;
4. requested timing and dates to hit or avoid;
5. site access: who will be home, gates/lockboxes, parking and pets;
6. colors: selected details, customer will choose, or consultation needed;
7. existing condition and preparation risks;
8. customer priorities and expectations learned during WNBET discovery; and
9. an optional “anything else Production should know?” field.

Required questions accept an explicit “None”. Production can see a waiting
Project but cannot take it until the handoff is complete. Until then the
salesperson sees a persistent prompt and receives one bell/email reminder the
next morning. These questions are fixed in the first version rather than an
editable managed list.

**Property per proposal.** Every proposal selects one of the customer's
properties, defaulting to the Lead's property. A proposal and its Project cover
exactly one property. Work at another property uses another proposal and
therefore another Project.

**Telling people** (plan, 20: "every ownership transfer creates an Activity
event and a notification to the new owner"): the Production Managers at the
Location are told in the bell when a job arrives; the one who is assigned is
told; the salesperson is told who has it. No email in this slice, and nothing
to the customer.

### What it stores

On `projects`: the Production Manager and when, the handoff notes and who
wrote them. A history of who has held it.

### Step 8b as built (2026-10-13)

Built as planned above, with decisions 130 and 132. What a reader of the code
should know:

- **A proposal's property.** `AddProposal` and `EditProposal` take one of the
  customer's properties, the Lead's unless another is chosen, and refuse one
  that is another customer's or archived. `CreateProjectForSoldProposal` gives
  the Project the proposal's property and names it for that address. The card
  and the after-action report ask only when the customer has more than one.
- **The handoff** is `project_handoffs`, one row for a Project, written by
  `CompleteProjectHandoff`. The nine questions are fixed in code
  (`ProjectHandoff::TEXT_ANSWERS` and the `HandoffColors` choices). It is its
  own page, `pages::projects.handoff`, made for a phone.
- **The gate.** A Project with no handoff row cannot be taken
  (`ProjectPolicy::take`) or assigned (`AssignProductionManager`). Production
  sees it listed as waiting.
- **The prompt** is `pages::sales.handoff-prompt`, under the header beside the
  close-out prompt. **The reminder** is `RemindToCompleteHandoff`, run by the
  timer that already runs every minute and counted as `handoffs_reminded`; it
  is due at 8:00 on the morning after the sale by the Location's own clock,
  and `projects.handoff_reminded_at` keeps it to one.
- **The Production Manager.** `TakeProject` and `AssignProductionManager`
  share one rule (`place`). The first time, the Project's production state
  goes from New from Sales to Pre-production. `project_production_managers`
  keeps who has held it. Both lock the Project's row, so two people cannot
  take it at once.
- **Production > Workspace** is `pages::production.workspace` at
  `/production/workspace`, opened with `production.access`: three lists for
  one Location.
- **Notices** are a new `ProjectNotice`, all inside the app except the
  reminder, which is also emailed. None names the customer, and none carries
  the Project's title, because the title has the street address in it.
- **Five audit events:** `project.handoff_completed`,
  `project.handoff_changed`, `project.production_manager_assigned`,
  `project.production_manager_changed`, `project.taken`. All but the changed
  handoff are in the customer's Activity.

Readings made while building, for Jacob to change if wrong:

- **The handoff is one form, saved whole.** There is no half-finished handoff
  kept as a draft. It can be corrected afterwards by the salesperson or a
  manager; what the answers were is kept, and the Production Manager is told.
- **A manager cannot assign before the handoff either.** "Production cannot
  take it until the handoff is complete" was read as covering a manager
  assigning it as well as a Production Manager taking it.
- **Production is told when a job is ready to take,** that is when its handoff
  is completed, not at the sale. They can already see it in the waiting list.
- **"The next morning" is 8:00 am** on the day after the sale, in the
  Location's time.
- **Colors that are "Chosen" must say which.** The other two choices need
  nothing more.
- **Changing a Production Manager needs a note; the first assignment does
  not.** The one it was taken from, the new one and the salesperson are told.
- **A manager assigns and does not take.** Taking is for someone with the
  Production Manager role at that Location. An Owner can assign anywhere.
- **A Project brought back after a corrected sale keeps its handoff and its
  Production Manager.**
- **The title still starts with the Lead's main service.** A deck sold from an
  interior-painting Lead reads "Interior Painting — 9 Lake Rd". See the
  handoff.

### Changed after slice 8 (2026-10-13, decision 134)

**Merged into `main` on 2026-10-13** with Jacob's word ("merge it", decision
135).

- **A Project is named for what was sold:** the proposal's name and the short
  street address of the proposal's property, "Lake house deck — 9 Lake Rd".
  The Lead's main service is no longer in it, and the "(Deck)" ending for a
  second Project is gone because it is no longer needed. It can still be
  edited by a manager. Projects that already exist keep the title they have.
- **The sale is also written to the record the Sales figures count from**
  (`sale_entries`; see the slice 7 plan, "Changed after slice 8"). A corrected
  sale still cancels and keeps its Project, and now also takes the sale off
  the month it was corrected in.
## Who may do what

**This slice needs new permissions,** which need Jacob's approval. None exist
for Projects today; the old list in permission-model.md was never built.
Proposed, all decided at the Project's own Location:

| Permission | Lets a person | Proposed for |
| --- | --- | --- |
| `projects.view` | See Projects at the Location | Owner, Leadership, Location Manager, Sales Consultant, Production Manager, Finance |
| `projects.manage` | Edit a Project's title and description; assign or change its Production Manager | Owner, Leadership, Location Manager |
| *(no permission)* | Take a waiting job as its Production Manager | A Production Manager at that Location, by policy |
| *(no permission)* | Write the handoff notes | The salesperson who sold it, or anyone with `projects.manage` |

- **A Production Manager cannot see Leads** today and still will not. They
  see the Project, and on it what Sales recorded that Production needs: the
  appointment times, the proposals and the notes. Not the call history.
- **The Contact Center** does not get `projects.view`. The plan lets it see
  "sales outcome" and "sold amount" (11.4): the customer page will show a
  Project's title and sold amount to anyone who can open that customer, with
  the link only for those who may open the Project.
- **Responsibility is not permission** (plan, 2). Being the Project's
  salesperson or Production Manager grants nothing by itself.

## Audit and Activity

Each is an audit event saved with its change and shown in the customer's
Activity: Project created; a sold proposal added to it; its title changed;
Production Manager assigned, changed or taken; handoff notes written; Project
cancelled because the sale was corrected.

## Tests

As slices 6 and 7 had: who may and may not, as a matrix of roles and
Locations; every refusal; nothing half-saved (the sale and its Project are one
save); two sales to one Lead at the same instant making one Project (real
processes); the lists showing only what a person may see; the sold amount
always equal to the sold proposals; and the migrations on MySQL 8.4.

## Decisions needed

**Answered by Jacob on 2026-10-13 (decision 130).** Questions 1 and 5 were
answered differently from the recommendation, and question 4 was accepted
"for now": one Project for each sold proposal; the handoff is questions the
salesperson has to complete; the cancellation rule may be adjusted later. The
other seven are as recommended. The questions are kept below as they were
asked.

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **One Project for a Lead, or one for each sold proposal?** A Lead can have
   two proposals sold, "Whole exterior" and "Deck". *Recommended:* **one
   Project for the Lead.** A second sold proposal joins the same Project and
   adds to its sold amount. Decision 125 already says a second job, booked
   later, is its own Lead, and so it would be its own Project. *Alternative:*
   a Project for each sold proposal, which suits jobs done months apart but
   makes two handoffs out of one sale.
2. **Is the Project made automatically the moment something is Sold?**
   *Recommended:* yes, in the same save as the sale, with nothing for the
   salesperson to do. Rule 13 says "created when the sale is Won".
3. **The title.** *Recommended:* "Main service — street address", filled in
   and editable, as decision 54 had it.
4. **What happens to a Project when the sale is corrected** (a manager puts
   its only sold proposal back to pending)? *Recommended:* the Project is
   marked **Cancelled: sale corrected**, with the manager's note. It stays on
   the record, leaves Production's lists, and its Production Manager is told.
   If the proposal is sold again, the same Project is brought back, not a
   second one made. Once later slices add a schedule or payments, a Project
   that has those will refuse this until they are dealt with.
5. **Handoff notes.** *Recommended:* one box, "What Production needs to know",
   offered when a sale is marked Sold and editable on the Project afterwards.
   **Not required**, so a sale is never blocked by it; a Project without them
   is flagged "No handoff notes yet" in Production's list and to the
   salesperson. *Alternative:* required before Production can take the job.
6. **The Project Coordinator.** The plan says "Coordinator if present;
   otherwise PM". *Recommended:* no new role in this slice. The job goes to
   the Production Managers. A Coordinator role is added when Jacob has someone
   to fill it.
7. **Who assigns the Production Manager?** *Recommended:* a manager at the
   Location assigns, and a Production Manager there can take a waiting job
   themselves.
8. **The new permissions,** as the table above. *Recommended:* approve as
   proposed. The one to look at: every Sales Consultant at a Location can see
   every Project there, not only their own, because Location is the boundary
   and assignment is not (decision 31).
9. **Finance.** *Recommended:* Finance can see Projects everywhere, since the
   sold amount is theirs to see; nothing else for Finance in this slice.
10. **No message to the customer.** The plan lists a confirmation email or
    text after a sale (6.3). *Recommended:* not in this slice. It needs its own
    decision about what may be sent, and when.

Still open elsewhere and not needed to start: the Pending ownership period;
whose figure a Lead, an appointment and a sale count for when an appointment
is moved between salespeople. Pending ownership is now tied to proposal expiry,
whose default is seven days; attribution remains question 3 in
[stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md).

## Known limits of this plan

- A Project here is the record of a sold job and who has it. It has no
  schedule, no colors, no work order, no payment and no cost.
- Pre-production is one state. The plan's readiness steps (6.3) come later.
- A Project cannot be made without a Lead, moved to another Location or
  archived.
- Production is told inside Backoffice only.
