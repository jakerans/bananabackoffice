# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-8-plan`

**State:** a plan waits for Jacob's decisions. **Nothing is built.** Slice 7 is
complete: steps 7a to 7d are on `main` and staging, the last merged on
2026-10-13 with his word ("Merge it, and let's continue based on the plan.").

## What this is

The plan for the next slice, as the plan itself orders it:
**[../slice-8-projects-plan.md](../slice-8-projects-plan.md), "A Project when
a sale is Sold, and the handoff to Production".** It says from the product
architecture why this is next.

In short: since step 7c a sale can be marked Sold, and then nothing happens. A
sold job has nowhere to go. The plan's next line (section 20) is "If Won:
Sales → Production handoff", and rule 13 says a Project is created when the
sale is Won.

Two steps, each stopping for review:

| Step | Delivers |
| --- | --- |
| 8a | The Project: made the moment a sale is Sold, with what was sold and for how much. A Project page. Shown on the Lead and the customer. |
| 8b | The handoff: Production > Workspace becomes a real page listing sold jobs that wait for a Production Manager, and assigning one. Production is told when a job arrives. |

Not in it: any message to the customer, deposits and payments, colors, the
schedule, work orders, a Project Coordinator role, commission.

## Why it stopped here

The collaboration protocol says to stop before building when a step needs
decisions that are not recorded. This slice needs ten, and one of them is
**new permissions**, which only Jacob can approve. An earlier plan for
Projects (decision 54) was overtaken by the newer product architecture; the
plan shows, line by line, what is kept and what is replaced.

## Decisions needed from Jacob

All ten are in the plan under "Decisions needed", each with a recommendation.
"Follow your recommendations" is a complete answer. The four that matter most:

1. **One Project for a Lead, or one for each sold proposal?** Recommended: one
   for the Lead. A second sold proposal joins it and adds to its sold amount.
4. **What happens to a Project when a manager corrects the sale?**
   Recommended: it is marked "Cancelled: sale corrected", kept on the record,
   and brought back if the proposal is sold again.
5. **Handoff notes from the salesperson.** Recommended: one box, "What
   Production needs to know", offered when marking Sold. Not required, but a
   Project without them is flagged to Production and to the salesperson.
8. **New permissions.** Recommended: `projects.view` for Owner, Leadership,
   Location Manager, Sales Consultant, Production Manager and Finance; and
   `projects.manage` (edit the title, assign the Production Manager) for
   Owner, Leadership and Location Manager. A Production Manager can take a
   waiting job at their own Location. The Contact Center does not open
   Projects, but sees a Project's title and sold amount on the customer page,
   as the plan allows (11.4).

The other six: made automatically at the sale (2); the title (3); no
Coordinator role yet (6); who assigns the Production Manager (7); Finance sees
Projects everywhere (9); no message to the customer (10).

## Tests and checks

No code changed. This branch adds the plan and updates this page, the roadmap
and the document index. GitHub runs its two jobs on the branch all the same;
they are reported with it.

## The plan check after slice 7

- **Built from the plan in slice 7:** run or no-show; close out first, the
  prompt and the reminder (decision 126); proposals; their results; a Lead
  that is Sold or Lost; corrections (128); the after-action report; the
  Objections list (129); seven Sales figures.
- **In 5.6 and 5.2 and not built:** the voice note (decision 125); Average Job
  Size, Close-on-the-Spot Rate, Pending Rate, % Pending with Rehash booked,
  Rehash conversion.
- **Provisional, for Sales Leadership:** whose figure a Lead, an appointment
  and a sale count for when an appointment is moved between salespeople.
- **Still set aside from earlier slices:** the booking engine, confirmation,
  Google Calendar, the new-lead popup and snooze, Aged Leads, the short-call
  report and Connection Rate, recordings, Pending ownership and Rehash.
