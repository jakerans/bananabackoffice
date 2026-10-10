# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `checkpoint-docs-slice-4-5-status`

**State:** slices 1 to 4 and the slice 5 rulebook are merged into `main`
(pull requests #2 to #7). This change corrects the status records, records
Jacob's five decisions of 2026-10-09 (decisions 85 to 89) and records that the
staging scheduler is now on. Jacob approved merging it.

**Waiting on Jacob:** the browser review of the Call Queue, and the questions
about the Call Center Agent switch below. **Approved and not yet built:** the
MySQL deadlock fix (decision 87).

## What changed

Documents, and one test correction. No application code, table, permission or
screen changed.

| Document | Change |
| --- | --- |
| [../roadmap.md](../roadmap.md) | "Where we are", the slice 4 row, the slice 4 boundary and the 4c row say merged. The slice 5 row says the rulebook is merged, cites decisions 75 to 83, and notes the two confirmed readings. The staging scheduler is recorded as on. Jacob's three slice 4 decisions are listed under the slice 4 boundary. |
| [../decisions.md](../decisions.md) | **Decisions 85 to 89** added. Decision 71 points at 85; decision 84 notes that both readings are confirmed. |
| [../slice-4-plan.md](../slice-4-plan.md) | Status lines say 4c is merged. The manager-part reading points at decision 85, the skip reading is corrected by decision 86, the deadlock follow-up is marked approved (decision 87), and "The server's scheduler on staging" records that it was off, was switched on, and how that was confirmed. |
| [../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md) | "Readings to confirm" is now "Readings confirmed" (decisions 88 and 89). Rule 12's last sentence reads "otherwise cannot take an unassigned Lead". |
| [../permission-model.md](../permission-model.md), [../navigation-map.md](../navigation-map.md), [../README.md](../README.md), [../security-notes.md](../security-notes.md) | Status wording says merged; the permission model and security notes point at decisions 85 and 87. No rule, permission or navigation entry changed. |
| This file | Replaced with this handoff. The job #12 and job #13 handoffs are in the Git history. |

**The test correction (not a weakening):** "only the enum, the role lists, the
eligibility rule and the Call Queue route and navigation item read the queue
permission" in `tests/Feature/Settings/ContactCenterSettingsTest.php` compared
file paths written with `/` against paths Windows writes with `\`, so it passed
on GitHub and failed on the Windows development machine. It now compares them
with `/` on every system. The list of files it expects is unchanged.

## Decisions recorded (2026-10-09)

| Decision | What Jacob decided |
| --- | --- |
| 85 | Call Center Agents see the Call Queue's manager part only when Leadership switches it on. Decided; not planned, not built. Until it is built the screen stays as it is. |
| 86 | Skip after calling hours close stays as built: the card disappears and the hold ends. |
| 87 | The MySQL deadlock fix is approved as its own job. |
| 88 | "A manager" in the appointment rulebook means Owner, Leadership or Location Manager, not a Call Center Agent. |
| 89 | A Sales Consultant booking their own Lead books it for themselves only. |

## Staging scheduler: on

With Jacob's approval the staging site's Laravel scheduler switch was turned on
through Forge on 2026-10-09. Forge added one scheduled job for the site, run by
user `forge` every minute. About a minute later the staging database's
`queue_tick` heartbeat showed a run seven seconds before it was read. Nothing
else on the server was changed. Managers should no longer see the yellow timer
warning on the Call Queue.

## Checks, with their real results

| Check | Result |
| --- | --- |
| GitHub jobs on `main` at `1a30c8d`, before this change | **Both passed** (run 37935214310). |
| Staging deployment of `1a30c8d` | **Finished** in Forge. Nobody has opened the staging pages since. |
| `composer test` on this branch, Windows development machine, first commit (`cd46f77`) | **Passed:** style, static analysis, and 1,973 tests (1,972 passed, 1 skipped, 9,579 assertions) in 219 seconds. Before the test correction the same run had the one failure described above. |
| GitHub jobs on this branch, first commit (`cd46f77`) | **Both passed** (run 38003291255). |
| Checks on the second commit (the decision records) | Reported with the merge; not recorded here. |
| Open pull requests | None. |

## Decisions needed from Jacob

For the Call Center Agent switch (decision 85). It cannot be planned until these
are answered; none is assumed:

1. **How many switches?** One for all agents, one for each Location, or one for
   each agent. Recommendation: one for all agents. It is the simplest thing that
   does what you described, and it can be made finer later.
2. **Does it start off or on?** Today agents see the manager part.
   Recommendation: off, since the point of the switch is that agents do not see
   it unless Leadership allows it.
3. **Who may change it?** Recommendation: the people who already manage the
   Contact Center settings (Owner and Leadership), on that same settings page,
   with each change recorded in the audit history.
4. **What does it cover?** On the Call Queue page: the summary, the timer
   warning, the cadence-finished list and Put back in queue. Recommendation:
   all four, and also the "Call queue" panel, queue history and Put back in
   queue on the Lead page, so an agent does not keep a manager tool through a
   side door.

And what comes next:

5. **Which work to plan after the deadlock fix.** The approved five-slice order
   is finished. Building appointments from the rulebook, Projects and the sales
   outcome, and production readiness each need their own plan and approval.

## Browser review of the Call Queue

Nobody has looked at this screen in a browser. It can be reviewed locally at
`http://backoffice.test` (the local copy is up to date, migrated and rebuilt) or
on staging. Use fictitious Leads only.

1. **Each role's menu.** As an Owner, a Leadership user, a Location Manager and a
   Call Center Agent in turn: Marketing > Contact Center shows **Leads** and
   **Call Queue**. As a Sales Consultant, a Production Manager and a Finance
   user: no Call Queue, and typing `/marketing/contact-center/queue` gives
   "forbidden". Clicking Contact Center itself still opens Leads.
2. **Next lead.** As a Call Center Agent open Call Queue. You see "N leads due
   now" and **Next lead**, and no list of Leads. Press it. One card appears.
3. **The card and its times.** Name, phone, Location, "Attempt 1 of …", what was
   asked for, earlier attempts, a countdown for the hold, and a link to the full
   Lead. Each time shows your device's time and, beside it, the Location's time.
   Compare a Lead at an Eastern Location with one at a Pacific Location.
4. **Log attempt.** The dialog says outbound phone call, has no channel or
   direction choice, and starts with the cursor on Cancel. Choose "No answer",
   enter a duration and save. The card is replaced by "Your call was logged."
   The Lead page shows one new attempt and the next attempt scheduled.
5. **Skip.** Saving with no reason is refused; "Other" with no note is refused;
   with a note it saves. Escape closes any dialog and focus returns to the
   button you pressed.
6. **Disqualify.** Choose a reason (try Duplicate and its "original" choice) and
   save. The card is replaced by a message and Next lead.
7. **The gone-hold message.** Hold a card. In another browser, as a manager,
   disqualify that Lead, move it to another Location, or wait out the hold.
   Within about 10 seconds the card is replaced by a short reason and shows
   nothing about the Lead. Also open Log attempt, let the hold run out, and
   save: the call should still be saved.
8. **Nothing due, and closing time.** With no Lead due: "Nothing is due for you
   right now." Outside calling hours it also says when they open.
9. **Manager summary and timer warning.** As a Location Manager or Owner: Due
   now, Longest wait, Leads held now, and Cadence finished, no contact. A
   Location Manager sees only their own Locations' numbers. On staging there
   should be no yellow timer warning now. Locally expect the warning, because
   nothing runs the timer on the development machine. A Call Center Agent
   still sees this manager part until the switch (decision 85) is built.
10. **Cadence-finished list.** Press **Put back in queue**: the dialog needs a
    note. After saving, the Lead leaves the list.
11. **Put back from the Lead page.** A Lead whose cadence finished shows a "Call
    queue" panel with its state, history and Put back in queue. A Sales
    Consultant who can open the Lead does not see the panel.
12. **Two-person test.** Two setters, two browsers, same Location, at least ten
    Leads due. Both press Next lead at the same moment, then skip or log, many
    times over. They must never be shown the same Lead at the same time.

## Known limitations carried forward

- The other follow-ups from the job #12 handoff are still open and none is
  authorized. The ones a reviewer will notice on screen: the held and
  cadence-finished lists stop at 25 with no notice; the card shows the Lead's
  inquiry phone while the attempt form pre-fills the linked contact's phone; and
  losing access while the Skip dialog is open shows a "forbidden" page instead
  of the access-changed notice.
- The device-time formatter test skips itself on the Windows development
  machine, because it looks for Node with a command Windows does not have.
  This is follow-up 8 of the job #12 handoff and is unchanged.
- Leads captured since step 4b are already due, so the first "due now" count on
  a database with existing Leads will be large.
- Staging runs PHP 8.5 while local development and both GitHub jobs use PHP 8.4.
