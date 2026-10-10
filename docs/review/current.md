# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-call-queue-kept-notices`

**State:** Jacob approved and the implementation agent merged three steps into
`main` on 2026-10-10: the MySQL deadlock fix (decision 87), the three display
faults from the Call Queue browser review, and the manager-view switch for Call
Center Agents (decision 85). Their handoff is the previous version of this file
in the Git history. One small approved fix is built on this branch and waits
for Jacob's word to merge.

**Nothing else is approved to build.**

## The merge of the three steps

| Check | Result |
| --- | --- |
| Both GitHub jobs on the last commit of the branch (`a4a369c`) | **Passed** (run 38017246958), SQLite and MySQL 8.4. |
| Both GitHub jobs on `main` after the merge (`5c8cc07`) | **Passed** (run 38050755098), SQLite and MySQL 8.4. |
| Staging deployment of `5c8cc07` | **Finished** in Forge, 2026-10-10 12:06 UTC. |
| Staging database, read after the deployment | The new column `users.queue_manager_view` is present, no migration is pending, nobody is switched on, and the queue timer had run 40 seconds earlier. |
| Staging pages opened in a browser | **Not done** by anyone. |

**Every Call Center Agent's manager-view switch is off on staging**, as decision
85 asks. Switch people on at Company > Settings > Contact Center, in the last
section, "Manager view for Call Center Agents".

## Step: keep a refusal on the Call Queue page until it has been read

Jacob approved this on 2026-10-10 ("fix the disappearing message").

Objective: on the Call Queue page, a refusal such as "This lead was just changed
by someone else, so nothing was saved." must stay on screen until the person's
next action. It used to vanish within ten seconds.

### What changed

- **Why it vanished.** The framework carries an error from one request to the
  next only when it is named after a field of the page. This page asks the
  server again every ten seconds, and a refusal that belongs to no field was
  dropped at that next request.
- **The fix.** The Call Queue page now keeps such refusals itself and shows
  them again on every request, until the messages are cleared. Every dialog
  already clears them when it opens and every successful save clears them;
  pressing Next lead now clears them too. The browser cannot set what is kept:
  only the server does.
- **One file of application code:**
  `resources/views/pages/marketing/⚡call-queue.blade.php`. No table,
  permission, audit event, route or package. No other page refreshes itself,
  so no other page had the fault.
- **Documents:** the slice 4 plan's known limits and this file.

### Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine | **Passed:** style, static analysis, and 2,018 tests (2,017 passed, 1 skipped, 10,115 assertions) in 293 seconds. |
| Both GitHub jobs on this branch | Reported with the branch; not recorded here. |
| Browser | **Not looked at in a browser.** The fault itself was seen in a browser on 2026-10-09; the fix is proved by the tests below, which fail without it. |

New tests in `tests/Feature/Queue/QueueScreenTest.php`:

- "a refusal that belongs to no field stays through the page refresh until the
  next action": the refusal is still there, once, after two refreshes, and is
  gone once the person opens the dialog again.
- "pressing next lead clears an earlier refusal".
- "the browser cannot plant a refusal of its own".

No existing test changed.

### Known limitations

- The refusal stays until the person does something. If they do nothing, it
  stays; there is no close button on it.
- This is specific to the Call Queue page. If another page is ever given a
  timer of its own, it will need the same care.

## Still owed from the merged steps

**A browser look at the manager-view switch.** Nobody has seen it in a browser.
On the local site or staging, signed in as an Owner:

1. Open Company > Settings > Contact Center and scroll to the last section,
   **Manager view for Call Center Agents**. It lists each active Call Center
   Agent with where they work and a switch that is off. Other roles are not
   listed.
2. View Backoffice as an agent and open Marketing > Contact Center > Call
   Queue. You see the due count and Next lead, and none of: the four summary
   numbers, the yellow timer warning, the "Cadence finished, no contact" list.
3. Return to the Owner and switch that agent on. A message confirms it. View as
   the agent again: the summary and the list are back.
4. As the agent, open a Lead whose cadence finished: the "Call queue" panel
   shows. Switch the agent off and reload the Lead: the panel is gone.
5. View as a Location Manager: the manager part shows whatever any agent's
   switch says.

## A timing-dependent test to watch

The step 4b test "a change that takes a lead away while it is being claimed…"
(the access revoked case) failed once locally on 2026-10-09 with "The claim
never got in first". It needs both orders of a real race to happen at least
once within its rounds, and in that run one order never did. It passed when run
again, in every full local run and in every GitHub run. Not changed; mentioned
so a red run is recognised.

## Decisions needed from Jacob

1. **Merge this fix?** It redeploys staging.
2. **What to plan next:** building appointments from the rulebook, Projects and
   the sales outcome, or production readiness. Each needs its own plan and
   approval. Nothing is being built until you choose.

## Known limitations carried forward

- Open follow-ups from the step 4c handoff, none authorized: the held and
  cadence-finished lists stop at 25 with no notice; the card shows the Lead's
  inquiry phone while the attempt form pre-fills the linked contact's phone;
  losing access while the Skip dialog is open shows a "forbidden" page.
- Deliberately not retried on a deadlock: the timer's own work, Skip and Put
  back in queue, and capturing a new Lead (see the slice 4 plan).
- The device-time formatter test skips itself on the Windows development
  machine, because it looks for Node with a command Windows does not have.
- Staging runs PHP 8.5 while local development and both GitHub jobs use PHP 8.4.
- Local database only: seven seeded fictitious staff, three Locations (one set
  to Pacific) and 14 fictitious Leads named "Queue Review … (Sample)", left
  from the browser review.
