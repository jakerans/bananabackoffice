# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-09 · **Branch:** `checkpoint-docs-slice-4-5-status`

**State:** slices 1 to 4 and the slice 5 rulebook are merged into `main`
(pull requests #2 to #7). Both GitHub jobs passed on the `main` commit that holds
all of it (`1a30c8d`), and Forge deployed that commit to staging. **Nothing is
waiting to be built, and nothing further is authorized.** What is waiting is
Jacob's review of the Call Queue (slice 4, step 4c), which nobody has yet looked
at in a browser, and the decisions listed below.

This change is **documents only**. It corrects status lines that still said step
4b or 4c was "awaiting review" or "not built" after they were merged, records the
staging scheduler check, and makes the two wording corrections the Reviewer
suggested for job #13.

## What changed

| Document | Change |
| --- | --- |
| [../roadmap.md](../roadmap.md) | "Where we are", the slice 4 row, the slice 4 boundary heading and the 4c row now say merged. The slice 5 row says the rulebook is merged and cites decisions 75 to 83 (was 75 to 82). The staging scheduler paragraph records that it is off. |
| [../slice-4-plan.md](../slice-4-plan.md) | The two status lines say 4c is merged. "The server's scheduler on staging" records the check and the site's real directory. |
| [../README.md](../README.md), [../navigation-map.md](../navigation-map.md), [../permission-model.md](../permission-model.md) | The 4c status wording says merged. No rule, permission or navigation entry changed. |
| [../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md) | Rule 12, last sentence: "otherwise cannot take an unassigned Lead", so it no longer reads as contradicting the released-Lead exception above it. No change of meaning. |
| This file | Replaced with this handoff. The job #12 and job #13 handoffs are in the Git history. |

No application code, table, permission or screen changed. **One test was
corrected, not weakened:** "only the enum, the role lists, the eligibility rule
and the Call Queue route and navigation item read the queue permission" in
`tests/Feature/Settings/ContactCenterSettingsTest.php` compared file paths
written with `/` against paths Windows writes with `\`, so it passed on GitHub
and failed on the Windows development machine. It now compares them with `/` on
every system. The list of files it expects is unchanged.

## Checks, with their real results

| Check | Result |
| --- | --- |
| GitHub "Checks and tests (SQLite)" on `main` at `1a30c8d` | **Passed** (run 37935214310). |
| GitHub "Migrations and tests (MySQL 8.4)" on `main` at `1a30c8d` | **Passed** (same run). |
| Staging deployment of `1a30c8d` | **Finished** in Forge, 2026-10-09 13:12 UTC. The three earlier slice 4 merges also deployed and finished. |
| Staging pages looked at after those deployments | **Not done.** Forge reports the deployments finished; nobody has opened staging since. |
| Staging scheduler | **Checked in Forge: off.** See below. |
| Open pull requests | None. |
| `composer test` on this branch, on the Windows development machine | **Passed:** style, static analysis, and 1,973 tests (1,972 passed, 1 skipped, 9,579 assertions) in 219 seconds. The skipped test is the device-time formatter (see "Known limitations"). Before the test correction below, the same run had one failure, described under "What changed". |
| GitHub jobs on this branch | Reported with the merge; not recorded here. |

## Staging scheduler: off

Forge reports the staging site's Laravel scheduler as not enabled, and neither
the site nor the server has a scheduled job that runs `schedule:run`. Nothing
was changed.

Effect: the Call Queue still hands out Leads, holds them and records attempts.
What does not run is the every-minute timer, so expired holds are only cleared
when someone next presses Next lead, and managers see the yellow "The queue
timer has not run lately" warning.

To turn it on, either of these (one step, staging only):

1. In Forge, open server "BackOffice", the staging site, and switch on the
   Laravel scheduler if the site offers it; or
2. add a scheduled job: user `forge`, every minute, command
   `php /home/forge/bananabackoffice-xg8eqczb.on-forge.com/current/artisan schedule:run`.

The implementation agent can do the second through Forge if Jacob says so.

## Decisions needed from Jacob

From slice 4, step 4c (the Call Queue):

1. **Should a Call Center Agent see the manager part of the Call Queue?** As
   built they do, at their own Locations (summary, timer warning,
   cadence-finished list, Put back in queue), because the existing role may
   assign Leads. Keeping it needs nothing. Hiding it is a product decision about
   the role. Recommendation: decide after seeing it (step 9 below).
2. **Skip after calling hours close.** As built the card disappears when hours
   close, so no skip reason is recorded and the hold simply ends.
   Recommendation: keep.
3. **The MySQL deadlock follow-up.** Two actions on the same hold within
   milliseconds can end in an error for one of them; nothing is half-saved.
   Approve it as its own job, or leave it recorded. Recommendation: approve it
   before real setters use the queue.
4. **The staging scheduler:** say whether to turn it on (above).

From slice 5 (the appointment rulebook). Neither blocks anything today:

5. **Confirm what "a manager" means:** Owner, Leadership or Location Manager at
   the Lead's Location, and not a Call Center Agent. Effect: an agent can book
   and rebook, but cannot reschedule a salesperson's appointment, move it, send
   an issued Lead back or mark one "booked in error". Recommendation: confirm.
6. **Confirm that a Sales Consultant booking their own Lead books it for
   themselves only.** Recommendation: confirm.

And what comes next:

7. **Which work to plan next.** The approved five-slice order is finished.
   Building appointments from the rulebook, Projects and the sales outcome, and
   production readiness are all unauthorized and need their own plan.

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
   Location Manager sees only their own Locations' numbers. The yellow timer
   warning shows while the scheduler is off. Then open the page as a Call Center
   Agent and note that the same manager part shows; that is decision 1.
10. **Cadence-finished list.** Press **Put back in queue**: the dialog needs a
    note. After saving, the Lead leaves the list.
11. **Put back from the Lead page.** A Lead whose cadence finished shows a "Call
    queue" panel with its state, history and Put back in queue. A Sales
    Consultant who can open the Lead does not see the panel.
12. **Two-person test.** Two setters, two browsers, same Location, at least ten
    Leads due. Both press Next lead at the same moment, then skip or log, many
    times over. They must never be shown the same Lead at the same time.

## Known limitations carried forward

- The fourteen follow-ups from the job #12 handoff are still open and none is
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
