# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-b-continuation`
**Step:** B7 owner-review corrections, decision 56 in [../decisions.md](../decisions.md).
**State:** implementation and local checks complete; GitHub checks pending.
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
- `git diff --check`: passed. GitHub SQLite / MySQL 8.4 results pending push.
- Automated coverage includes due/future and central callbacks, location/role
  scoping, search, pagination, dialog authorization and stale confirmation,
  no lifecycle write on opening, and validation after changing reasons.

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

- Jacob's keyboard/focus and visual re-review remains. Automated Livewire tests do
  not execute the browser's focus handling; the modal uses the installed Flux
  implementation and the app's existing dialog pattern.
- The local review dataset originally had only one Location. Cross-location review
  needs another existing local Location; no Locations or review data were changed
  by this patch.
- The earlier B7 reconciliation and its additive lifecycle revision column remain
  prerequisites. Historical stored values are preserved. The guard still covers
  lifecycle mutations rather than every Lead edit.
- No staging or production commands were run. Stop for owner re-review.
