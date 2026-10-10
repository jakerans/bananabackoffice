# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6c-plan`

**State:** a plan waiting for decisions. Slice 6, step 6c is planned in detail
and **nothing of it is built or approved.** Jacob answered questions 25 and 26
on 2026-10-10 (decisions 115 and 116). Questions 23 and 24 need his
confirmation, and 27 and 28 he asked to have explained; all four are below.
This branch holds documents only.

Step 6b is built, reviewed in the browser and **merged into `main` on
2026-10-10** (`9f7dc63`) at Jacob's word ("merge and continue"). After that
merge: both GitHub jobs passed on `9f7dc63` (SQLite, and MySQL 8.4); staging
deployed it; on staging the two new tables and five new columns exist, the
three widened columns are forty characters, no migration is pending, the
Cancellation Reasons list holds Jacob's four, the new tables are empty, and the
call-queue timer was still ticking. Nobody has opened the staging pages in a
browser. The 6b handoff, with its browser-review results, is in this file's Git
history.

## Step

Slice 6, step 6c: release and return. Plan:
[../slice-6-appointments-plan.md](../slice-6-appointments-plan.md), "Step 6c".
Rules: [../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md),
rule 8 (its last three points) and rule 12. Decisions 98 and 114 in
[../decisions.md](../decisions.md).

Objective: a cancelled Lead that its salesperson does not rebook goes back to
the call center by itself; the call center works those Leads from a button of
their own; and the salesperson can take one back when the customer comes back
to them.

## The proposal, in short

- **Release.** After 30 days (an adjustable number) without a new appointment,
  the timer takes a cancelled Lead from its salesperson. It is then as a Lead a
  manager sent back: Qualified, with nobody, marked as back from Sales, still
  counted as issued.
- **A second button on the Call Queue,** "Next cancelled appointment", with its
  own count. The Lead it hands out carries the notice built in 6b, so the
  agent knows the script before dialling.
- **Booking from that group** is an ordinary booking with any salesperson, and
  a new issued Lead with its history kept (rule 8).
- **Take back.** The salesperson a Lead was released from can take it back
  with a short note. It returns to them as Appointment cancelled, with no new
  issued count (rule 12).
- **Sales > Calendar** shows the days left on each Lead to rebook, and a third
  list, "Released from you".
- **One new setting,** no new permission, no new package, nothing sent to a
  customer.

## Decisions needed from Jacob

Each has a recommendation, so "agreed to all" is an answer. They are questions
23 to 28 in the plan, which gives the alternatives.

| # | Question | Recommended |
| --- | --- | --- |
| 23 | Which Leads belong in Cancelled Appointments? | Every Lead that comes back to the Contact Center with its last appointment cancelled: released at 30 days, sent back by a manager, or moved to another Location. They need the same script. **This changes what 6b built for a manager's send-back,** which today goes into the ordinary queue (decision 105). |
| 24 | How does the button work "beside new Leads"? | Two buttons side by side, each with its own count. "Next lead" never hands out a cancelled appointment, so the agent always knows which script is next. The most recent return is handed out first. |
| 25 | How often is a Lead in the group called? | **Answered (decision 115):** the same cadence and calling hours as every other Lead, from attempt 1. |
| 26 | The 30 days. | **Answered (decision 116):** one number on Contact Center settings, 1 to 180, starting at 30, changed by Owner and Leadership. A Lead is released once that many full days have passed since the cancellation. |
| 27 | Is the salesperson warned before a Lead is taken? | Yes: "To rebook" shows the days left, the bell warns 5 days before and tells them again at release. No email. |
| 28 | Who may take a Lead back, and for how long? | Only the salesperson it was released from by the timer, while it is still with the Contact Center at the same Location. No time limit. Not a Lead a manager sent back. |

Tradeoffs worth knowing:

- **23.** With the recommendation, one button means one script. Without it, an
  agent pressing "Next lead" can still be handed a cancelled appointment that
  a manager sent back, and learns it from the notice on the card.
- **24.** Two buttons leave it to the agent, or their manager, to decide when
  to work cancelled appointments. One combined button enforces the order and
  the agent finds out which script only when the Lead appears.
- **26.** Releasing at any time of day means a Lead can leave a salesperson's
  list in the middle of their day. Releasing only overnight is tidier and
  needs a second timer.
- **28.** No time limit matches the rule's own example (a homeowner calling
  two months later). It also means "Released from you" can grow long.

## Readings the plan makes

Built this way unless Jacob says otherwise: a released Lead still counts as
issued and booking it from the group is a new issue; the 30 days count from the
latest cancellation; a Lead with no salesperson is still released; a Lead with
an upcoming appointment is never released.

## Still open from step 6b

Five choices made while building 6b stand unless Jacob changes them: the
counting question defaults to "still counts" outside the Send back dialog; a
Lead that came back and is booked again is a new issued Lead; booking with a
different salesperson tells only the new one; "Booked in error" is still
offered on a passed appointment; "Other" is stored last in the reasons list.

## Tests and checks

Documents only. No application code, test or migration changed on this branch.
`composer test` was not run for it. The GitHub jobs are reported with the
branch, not recorded here.

## Browser review

None. Nothing on this branch changes a screen.
