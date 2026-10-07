# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-b-continuation`
**Step:** B7 owner-review corrections, decision 56 in [../decisions.md](../decisions.md).
**State:** implementation, local checks and an independent local Chrome re-review
pass. Both GitHub jobs pass on the implementation handoff; the final browser-review
documentation commit is local only because this environment denied the push.
No merge, deployment, B8 or new architecture work is included.

## What changed, and why

- **Follow-ups badge:** the old count added a due/overdue cutoff that the table did
  not use, so future callbacks appeared as rows without being counted. The badge
  and rows now share the same eligibility and search helper. It counts the full
  matching result across pages. Location/authorization rules, transferred
  unassigned callbacks and central/unrouted callbacks remain intact. Assigned to
  me and the Assign dialog's workload counts retain their existing due-date rules.
- **Return/Resume working:** both actions open the existing Flux modal pattern,
  with an explanation, Cancel (initial focus), and Return to working. There is no
  native JavaScript confirmation in this flow. Opening or dismissing the dialog
  does not change the Lead. Submission retains authorization and the atomic
  lifecycle revision guard, using the revision captured when the dialog opened.
  Assignee, qualification history and follow-up history behavior stay unchanged.
- **Reason-switch validation:** changing the disqualification reason clears only
  its related field errors. Other's old note error no longer lingers on Duplicate
  Lead. Entered notes are retained; submission still validates the selected reason.

Canonical behavior: [../checkpoint-b.md](../checkpoint-b.md). No schema, domain
rules, permissions or audit actions were changed. No deviation or new stakeholder
decision was needed for this bounded patch.

## Checks

- Targeted B7 follow-up, qualification, disqualification and concurrency tests:
  **141 passed / 872 assertions**.
- Dirty PHP formatting: `php vendor/bin/pint --dirty --format agent` passed.
- Full local `composer ci:check` (invokes `composer test`): **passed**, including
  Pint, PHPStan and **1,444 tests / 6,235 assertions**.
- [GitHub run 37669939744](https://github.com/jakerans/bananabackoffice/actions/runs/37669939744)
  on implementation commit `d1e60e8`: **both jobs passed**, each with **1,444 tests /
  6,235 assertions**. SQLite ran the full CI checks. MySQL verified an empty
  **8.4.11** database, applied every migration, ran the full suite, and confirmed
  **40 migrations** were applied by that suite to MySQL.
- [GitHub run 37670526216](https://github.com/jakerans/bananabackoffice/actions/runs/37670526216)
  on remote branch HEAD `61e3c2b` before this browser-review handoff: **both SQLite
  and MySQL 8.4 jobs passed**.
- `git diff --check`: passed. The implementation is pushed on the checkpoint
  branch; this final handoff update changes documentation only.
- Automated coverage includes due/future and central callbacks, location/role
  scoping, search, pagination, dialog authorization and stale confirmation,
  no lifecycle write on opening, and validation after changing reasons.

## Independent local Chrome re-review

Completed on 2026-10-07 against `http://backoffice.test` with fictitious local
data through Jacob's existing Chrome session:

- Follow-ups showed matching badge and row totals for due and future callbacks,
  current search, zero results and clearing search. Owner scope included the
  unrouted callback; the Sales Consultant saw and counted only their assigned
  callback and was refused the unrouted Lead.
- A second fictitious local Location proved cross-location behavior: the callback
  disappeared from Four Corners and appeared/counting as Unassigned at the
  destination, then was returned to central with its callback intact. The temporary
  Location remains active locally with no Leads or grants; no tracked, staging or
  production data changed.
- Return to working and Resume working both opened the styled Flux dialog with no
  native confirm. Cancel received initial focus; Tab and Shift+Tab never reached a
  background control (Chrome reports `body` at the native dialog's cycle boundary,
  then the next key returns to the dialog). Escape and Cancel closed it and restored
  focus to the triggering button. Confirming both actions retained the assignee;
  the qualified milestone and callback were restored afterward for the review
  fixtures.
- A two-tab reschedule made the open Resume confirmation stale. Confirming it was
  refused with the stale message, left the Lead in Deferred Follow-up and preserved
  the newer callback.
- In both Disqualify and Correct reason, Other's note error disappeared after
  switching to Duplicate Lead; Duplicate's own validation still failed when no
  original or explanation was provided; switching back restored Other's required
  explanation.

## Owner browser scenarios to re-check

Refresh existing local tabs before testing. Use the existing fictitious B7 Leads.

1. **Badge:** as Owner in All Locations, schedule one callback for today and one
   for a future date. Follow-ups must show both and count both. Compare the badge
   with the full result (not only the current page if paginated). Search for one
   Lead, then no matching Lead, then clear search: counts must match each result.
2. **Scope:** return a deferred Lead to central. It remains in Follow-ups for
   people allowed to see unrouted Leads, even with a future date and no assignee.
   Re-check as the Sales Consultant: only their own eligible follow-ups appear and
   count. If another local Location exists, move a callback there and verify the
   destination distributor sees/counts it as Unassigned; the old location does not.
3. **Styled confirmation:** on a Qualified Lead choose Return to working; on a
   deferred Lead choose Resume working. Both must open a styled modal, never a
   browser confirm. Check initial Cancel focus, Tab/Shift+Tab staying in the modal,
   Escape and Cancel closing it, and focus returning to the triggering button.
   Cancellation leaves status, assignee and callback unchanged. Confirming returns
   it to Working with the same assignee; the deferred callback clears and historical
   qualification/follow-up entries remain.
4. **Stale confirmation:** open Resume working in tab A. Reschedule that callback
   in tab B. Confirm in A: it must refuse the stale change and keep B's callback.
   Reopen the confirmation to act on the current state.
5. **Reason error:** select Other in Disqualify, submit without a note, then choose
   Duplicate Lead. The previous Other error must disappear; Duplicate's controls
   appear. Submit without an original or explanation: its own validation must still
   fail. Switch back to Other and confirm it still requires an explanation. Also
   check the same reason switching in Correct reason as Owner.

## Remaining risks and limits

- The earlier B7 reconciliation and its additive lifecycle revision column remain
  prerequisites. Historical stored values are preserved. The guard still covers
  lifecycle mutations rather than every Lead edit.
- The final documentation-only browser handoff is committed locally but could not
  be pushed: this execution environment denied `git push origin
  checkpoint-b-continuation` to the configured GitHub remote as sensitive egress.
  Exact-final-SHA CI therefore has not run.
- No staging or production commands were run. Stop for the owner's merge decision.
