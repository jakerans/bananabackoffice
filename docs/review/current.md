# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `office/13-slice-5-appointment-and-ownership-handof`
· **Pull request:** not opened yet (the office opens it after the review)

**State:** Job #13 (Plan) is written and waiting for review. It is **documents
only**. No application code, table, permission, screen or test was added or
changed, and no check was run by this job. Nothing is deployed by it.

## Step

Slice 5: the appointment issuance and ownership handoff rulebook. Design only.

Objective: write Jacob's fourteen decisions from conversation on 2026-10-08 into
the project documents, so that later appointment work has one rulebook to
follow, without building anything and without implementing Projects.

## What changed

| Document | Change |
| --- | --- |
| [../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md) | **New.** The fourteen rules, what counts as issued, what is still open, two readings to confirm, and the gaps a later build plan must bring to Jacob. |
| [../decisions.md](../decisions.md) | **Decisions 75 to 84** added. |
| [../roadmap.md](../roadmap.md) | The slice 5 row, the "Where we are" paragraph and the historical B8 row now point at the rulebook and decision 82. |
| [../README.md](../README.md) | The rulebook is listed. |

In one paragraph: a Qualified, routed Lead with a customer and property address
can be booked with a salesperson; saving the appointment is the moment the Lead
is "issued" and handed to Sales with the new status Appointment Booked. The
rulebook then covers rescheduling, moving to another salesperson, a customer
cancelling (with a 30-day window before the Lead goes to the call center's
Cancelled Appointments group), sending a Lead back by hand, "booked in error",
the cancellation reasons list, a salesperson picking a released Lead back up, a
Project being created only when the sale is Won, and provisional Pending
ownership of 30 days.

Three earlier decisions are changed by Jacob's newer ones, and each change is
written where it applies:

- **Decision 54** (and decision 27, and the historical B8 sequence): a Project
  is no longer assumed to come from a Qualified Lead. It is created when the
  sale is Won (decision 82). The affected historical permission rows are listed
  in the rulebook, rule 13. They are not in the code and were not rewritten.
- **Decision 39:** the managed lists were fixed at five. A sixth, cancellation
  reasons, is reserved (decision 79). It is not created yet.
- **Decision 44:** Sales Consultants still do not claim Leads, with one narrow
  exception: taking back a Lead they were issued before (decision 81).

No conflict was found that Jacob's decisions leave unsettled, so the job did not
stop for a question.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` | **Not run by this job.** It changes documents only. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | **Not run yet.** The office runs them on the pull request after the review. |
| Browser review | Not applicable: nothing visible changed. |
| Staging and production | Not touched. |

## Decisions needed from Jacob

1. **Confirm what "a manager" means in the rulebook.** It is written as Owner,
   Leadership or Location Manager at the Lead's Location, and **not** a Call
   Center Agent, because that is how the documents already use the word and
   because your booking rule names the Call Center Agent separately. The effect:
   a Call Center Agent can book and can rebook a Lead from Cancelled
   Appointments, but cannot reschedule a salesperson's existing appointment,
   move it to another salesperson, send an issued Lead back, or mark one
   "booked in error". Recommendation: confirm. If you want agents to be able to
   reschedule when a customer calls in, say so and it becomes a rule.
2. **Confirm that a Sales Consultant who books their own Lead books it for
   themselves only.** Picking a colleague would be handing a Lead out, which
   decision 44 keeps with management and the call center. Recommendation:
   confirm.

Neither blocks this job. Both are marked "to confirm" in the rulebook and in
decision 84.

## Known limitations

- **The rulebook has gaps on purpose.** The fourteen decisions do not say, for
  example, which status a Lead shows after it goes back to Contact Center, what
  happens to an appointment when its Lead moves to another Location or is
  disqualified, or what happens when the company (not the customer) cancels.
  These are listed in the rulebook under "Not decided here" so that the build
  plan asks instead of guessing. No rule was invented for them.
- **Still open, as you recorded:** which salesperson an issued Lead counts for
  after a reassignment or re-issue; offer validity; the cancellation reasons
  themselves; no-shows and appointment outcomes. The Pending ownership period is
  provisional until Sales Leadership confirms.
- **Documents not changed by this job:**
  [../permission-model.md](../permission-model.md) and
  [../checkpoint-b.md](../checkpoint-b.md) still show the historical Project
  rows as they were (already marked historical there);
  [../product-architecture.md](../product-architecture.md) still lists the
  Pending ownership period as pending, which stays true; and the slice 4 status
  lines in the roadmap were left as job #12 wrote them.

## Still waiting from job #12 (slice 4, step 4c: the Call Queue screen)

The repository this job started from shows pull request #6 merged into `main`.
**This job did not verify the staging deployment, and nobody has yet looked at
the Call Queue in a browser.** The full job #12 handoff, with the twelve
browser-review steps, the Forge scheduler step and the fourteen follow-ups, is
the previous version of this file in the Git history ("Architect: review handoff
for job #12"). Its decisions are still waiting:

1. Should a Call Center Agent see the manager part of the Call Queue (summary,
   timer warning, cadence-finished list, Put back in queue)? Recommendation:
   decide after seeing it on staging.
2. Skip after calling hours close: keep as built (the card disappears and the
   hold simply ends)? Recommendation: keep.
3. Confirm or change the other readings and builder's choices in that handoff.
4. Whether to approve the MySQL deadlock follow-up as its own job.
5. Whether staging's scheduler is on in Forge. The Call Queue's yellow timer
   warning, seen as a manager two minutes after a deployment, tells you.

Also still to do from that handoff: the browser review, the two-person test, and
recording the final test count and duration.
