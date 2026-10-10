# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-docs-switch-review-and-local-https` (documents only)

**State:** Jacob approved and the implementation agent merged three steps into
`main` on 2026-10-10: the MySQL deadlock fix (decision 87), the three display
faults from the Call Queue browser review, and the manager-view switch for Call
Center Agents (decision 85). The fix that keeps a refusal on the Call Queue
page was merged the same day. The earlier handoffs are previous versions of
this file in the Git history.

**Nothing is waiting to be built, and nothing further is approved.** This
branch only records what was checked after those merges; it is documents only
and waits for Jacob's word to merge.

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

Jacob approved this on 2026-10-10 ("fix the disappearing message") and its
merge the same day ("Merge it"). It is `main` commit `4e188c3`: both GitHub
jobs passed on it (run 38053748850), Forge finished deploying it to staging at
12:55 UTC, and the queue timer on staging had run 42 seconds before it was read.

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
| Both GitHub jobs on its branch (`6d92187`) | **Passed** (run 38051184352). |
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

## Browser look at the manager-view switch (done 2026-10-10)

Done on the local site by the implementation agent at Jacob's request, signed in
as the seeded fictitious Owner. **It works as designed.**

| Step | Result |
| --- | --- |
| 1. The settings section | Pass. "Manager view for Call Center Agents" is the last section of Company > Settings > Contact Center. It listed the one fictitious agent, Casey Callcenter, with "All locations" and a switch that was off. No other role was listed. |
| 2. The agent's view with the switch off | Pass. The Call Queue showed the due count and Next lead, and none of the summary numbers, the timer warning, the cadence-finished list or Put back in queue. A Lead whose cadence had finished opened with no "Call queue" panel. |
| 3. Switching on | Pass. One click on the settings page; the switch showed on at once and was saved. As the agent, the summary, the timer warning and the cadence-finished list were back, with Put back in queue. |
| 4. The Lead page | Pass with the switch on: the "Call queue" panel, its history and Put back in queue showed. |
| 5. Switching off again, and the Location Manager | Checked from the command line, not in the browser: switching the agent off through the application's own action took the manager part away from the agent, and the Location Manager had it throughout. The automated tests cover both. |

Not seen: the confirmation message after a switch is changed. The page was read
as text a moment later and the message was not in it; whether it showed and
went, or the reading missed it, is not known. The switch itself was saved.

The local agent's switch was put back to off afterwards.

## The local site is now served over HTTPS

On 2026-10-10 Jacob turned on Herd's "Secure" option for the local site, to see
whether it would stop the Claude desktop app asking for approval on every
browser action. It did not. The site stays at `https://backoffice.test`: the
local `.env` address was changed to match (that file is not in version control),
and passkeys can now be tried locally. Nothing in the application changed.

## A timing-dependent test to watch

The step 4b test "a change that takes a lead away while it is being claimed…"
(the access revoked case) failed once locally on 2026-10-09 with "The claim
never got in first". It needs both orders of a real race to happen at least
once within its rounds, and in that run one order never did. It passed when run
again, in every full local run and in every GitHub run. Not changed; mentioned
so a red run is recognised.

## Decisions needed from Jacob

1. **Merge this documents change?** It redeploys staging and changes nothing
   there.
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
