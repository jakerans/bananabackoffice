# Slice 10 — Pre-Production: Onboarding, the Rough Timeframe and Colors: Proposed Plan

Status: **proposed on 2026-10-13, for Jacob's approval. Nothing in it is
built, and Jacob has not chosen it as what comes next.** It was written so
that it is ready: it is what the implementation agent recommended after
slice 9, from the plan.

It follows [product-architecture.md](product-architecture.md), sections 2,
6.2, 6.3, 6.4, 6.5, 6.8 and 20, and builds on slice 8
([slice-8-projects-plan.md](slice-8-projects-plan.md)).

## Why this is next, from the plan

1. **The plan's own order.** Section 20 gives a customer's path. After "If
   Won: Sales → Production handoff → Coordinator if present → PM", which slice
   8 built, the next thing a sold job meets is pre-production (6.3): "manages
   Sales→Production handoff, colors, customer onboarding, readiness; rough
   timeframe before exact schedule".
2. **Today a Project stops the moment a Production Manager takes it.** It
   shows "Pre-production" in the Workspace and nothing more can be recorded
   on it: not that the customer was called, not when the work might start,
   not what colors it is.
3. **Colors have to be right, and kept.** "Every normal sold job ultimately
   has an authoritative Color Specification" (6.4), and closeout "requires an
   authoritative, durable color record by area/surface ... easy to retrieve
   years later" (6.8). The handoff asks only whether colors are chosen.
4. **It needs nothing that is not built.** A Project, its Production Manager
   and the Workspace exist. No payment, no QuickBooks, no message to a
   customer.
5. **What else could have been next, and why not first.** The deposit and
   payments (10.4, 10.6) touch QuickBooks, whose integration check has not
   been done. Average Job Size and Close-on-the-Spot Rate (5.2) are small and
   can be done at any time.

## What the plan already settles

| The plan says | Where |
| --- | --- |
| A Project has several separate states, never one status. Color Readiness is one of them. | 2 |
| Production works from its Workspace. No separate pages in the menu for Projects or Pre-Production. | 6.2 |
| Pre-production is the Production Manager's when there is no Coordinator. | 6.3; decision 130 |
| A rough timeframe comes before an exact schedule. Exact dates are usually set three to six weeks before the start. | 6.3, 6.5 |
| A Color Specification is by room or area and surface: manufacturer, color name and code, sheen, product, notes, and the customer's approval. It may be versioned. | 6.4 |
| The color record must still be easy to find years later. | 6.8 |

## What slice 10 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 10a | **Onboarding and the rough timeframe.** The Production Manager records the welcome call and when the work is roughly expected. The Workspace shows what each job in pre-production still needs. | Plan 6.2, 6.3, 6.5 |
| 10b | **The Color Specification.** Colors by area and surface, the customer's approval, and a new version when they change. Color Readiness as a state of its own. | Plan 2, 6.4, 6.8 |
| 10c | **Readiness.** One place that says whether a job is ready to be scheduled and what is holding it. | Plan 6.2, 6.3 |

Not in slice 10, each needing its own plan and approval:

- **Any message to the customer:** the confirmation email or text (6.3), a
  color approval link, the portal (13). No message to a customer has been
  authorized in any slice.
- **The exact schedule** (6.5 beyond the rough timeframe), crews,
  subcontractors and utilization.
- **Work orders** (6.6), **change orders** (6.7), **closeout** (6.8) and job
  costing.
- **The deposit and the deposit-paid handoff call.** They need payments.
- **A Project Coordinator.** Backoffice has no such role (decision 130).
- **Color Studio and Color Plans** (14), which are for inspiration and are
  not the production record.

## Step 10a — Onboarding and the rough timeframe

### What a person sees

- **On a Project in pre-production,** its Production Manager, or a manager,
  records the **welcome call**: the day they spoke, with whom, and what was
  agreed. Until it is recorded the Project shows "Customer not yet called".
- **The rough timeframe:** the earliest and the latest the work is expected
  to start, as two dates, with a note ("after the roof is done"). It is shown
  as "Late November to mid December", and says plainly that it is not a
  booked date.
- **It can be changed,** with a reason. What it was is kept.
- **Production > Workspace** shows, on each job in pre-production, what it
  still needs: the call, a timeframe. Jobs are listed soonest expected start
  first, with those that have no timeframe on top.
- **The salesperson who sold it** sees the timeframe on the Project and on the
  Lead, so they can answer the customer.

### What it stores

| Table | What |
| --- | --- |
| `projects` | The welcome call: when, with whom, the note, and who recorded it. The rough timeframe: earliest start, latest start, note. |
| `project_timeframes` (new) | Each timeframe a Project has had, with who set it, when and why, so a change keeps what it was. |

## Step 10b — The Color Specification

### What a person sees

- **A "Colors" part of the Project page:** a list of lines, each an area
  ("Living room"), a surface ("Walls"), the manufacturer, the color's name
  and code, the sheen, the product and a note.
- **It starts from the handoff.** If the salesperson answered "Chosen" and
  said which, that text is shown above the list to be turned into lines. If
  they answered "The customer will choose" or "A color consultation is
  needed", the Project says so.
- **The customer's approval** is recorded by staff until the portal exists:
  who approved, how (in person, by phone, by text or email) and when.
- **Changing an approved specification makes a new version.** The old one is
  kept and can be read. The new one needs approval again.
- **Color Readiness** shows on the Project and in the Workspace: Not started,
  In progress, Waiting for the customer's approval, or Approved.

### What it stores

| Table | What |
| --- | --- |
| `color_specifications` (new) | One row for each version of a Project's colors: its number, who made it, and the approval (who, how, when, recorded by whom). Never deleted. |
| `color_specification_lines` (new) | The lines of a version: area, surface, manufacturer, color name, color code, sheen, product, note, order. |
| `projects` | `color_readiness`, a state of its own beside the production state. |

## Step 10c — Readiness

### What a person sees

- **On the Project, a short checklist:** handoff complete; Production Manager
  assigned; customer called; rough timeframe set; colors approved. Each is
  ticked by what is recorded, never by hand.
- **Production > Workspace** splits pre-production in two: **Getting ready**,
  each job with what it still needs, and **Ready to schedule**.
- **A manager sees the same for the whole Location,** and how long each job
  has been in pre-production.

### What it stores

Nothing new. Readiness is worked out from what steps 10a and 10b record, so it
cannot disagree with them.

## Who may do what

No new permission.

| What | Who |
| --- | --- |
| See the call, the timeframe, the colors and the checklist | Anyone with `projects.view` at the Project's Location. |
| Record the call; set or change the timeframe; write the colors; record the customer's approval | The Project's Production Manager, or anyone with `projects.manage` there. |
| Nothing | The salesperson who sold it reads all of it and writes none of it. See question 7. |

## Audit and Activity

Each is an audit event saved with its change and shown in the customer's
Activity: welcome call recorded; rough timeframe set or changed; colors
written; colors approved; a new version of the colors.

## Tests

As slices 8 and 9 had: who may and may not, as a matrix of roles and
Locations; every refusal; nothing half-saved; a new version keeping the old
one exactly; the Workspace showing only the Location's own jobs and each in
the right list; readiness against worked examples; and the migrations on
MySQL 8.4, with every new stored name checked against its column.

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **What "customer onboarding" is.** *Recommended:* one recorded welcome
   call by the Production Manager: the day, who they spoke to, and a note.
   Nothing is sent to the customer. *Alternative:* a list of things the call
   has to cover, each ticked off.
2. **How a rough timeframe is written.** *Recommended:* an earliest and a
   latest start, as two dates, shown in words ("late November to mid
   December"). *Alternative:* a single month.
3. **Should the Workspace flag a job nobody has called?** *Recommended:* yes,
   after two working days in pre-production with no welcome call, shown to the
   Production Manager and to a manager. No email.
4. **Who writes the colors and records the approval.** *Recommended:* the
   Production Manager or a manager. The customer's approval is recorded by
   them, with how it was given, until the portal lets the customer approve.
5. **Changing colors after approval.** *Recommended:* a new version, the old
   one kept, and approval needed again.
6. **Lists or free text.** *Recommended:* sheen from a short fixed list
   (Flat, Matte, Eggshell, Satin, Semi-gloss, Gloss); manufacturer, color and
   product typed in. Managed lists can come later.
7. **Can the salesperson write colors too?** They often know them from the
   visit. *Recommended:* not in this slice. They gave what they knew in the
   handoff; one person owns the specification after that.
8. **Do colors have to be approved before a job is "ready to schedule"?**
   Colors are often settled late. *Recommended:* no. Ready to schedule needs
   the handoff, a Production Manager, the call and a timeframe. Approved
   colors are shown beside it and will be required before a work order, in a
   later slice. *Alternative:* yes, nothing is ready until colors are approved.
9. **No message to the customer** in this slice. *Recommended:* none.

To start, Jacob needs to approve the plan, or step 10a alone, and answer
questions 1 to 3. Questions 4 to 7 are needed before 10b, and 8 before 10c.

## Known limits of this plan

- **Nothing tells the customer anything.** The welcome call is a phone call
  the Production Manager makes; Backoffice only records it.
- **A rough timeframe reserves nothing.** No crew and no calendar is involved
  until the schedule is built.
- **The customer's approval is somebody's word** that the customer approved,
  until the portal exists.
- **Photos and documents** of colors are not kept: Backoffice stores no
  uploaded files yet.
