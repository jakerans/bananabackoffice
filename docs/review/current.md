# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-b-continuation`
**Step:** B7 owner-review corrections and the bounded application-confirmation
follow-up, decisions 56 and 57 in [../decisions.md](../decisions.md).
**State:** implementation, local checks and local Chrome re-review pass. Both
GitHub jobs pass through the pushed confirmation review commit `a05df3f`. The
final concurrent-refusal corrections described below are local only, so their
exact final SHA has not run in GitHub Actions.
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

### Application confirmation follow-up

- The complete production inventory had nine browser-native confirmations and no
  application `alert()` or `prompt()` calls. Customer archive, customer-contact
  removal, property archive, Lead-note removal, Lead-customer unlink,
  list-option deactivation, Location deactivation, staff deactivation and access
  revocation now use one shared styled Flux confirmation component.
- Cancel receives initial focus. The styled dialog keeps keyboard focus inside,
  Escape and Cancel dismiss without mutation, focus returns to the trigger, and
  the destructive action is explicit. No system- or browser-owned permission
  prompt is bypassed.
- Each opening action resolves and authorizes the target without changing it.
  Confirmation resolves and authorizes the locked record again, then calls the
  existing domain action. The list dialog also locks the list identity, so a
  crafted list switch cannot substitute a same-ID option from another table.
- Lead-customer unlink also locks the Account, Contact and Property identities.
  Confirmation refreshes the Lead and refuses a stale dialog if any part of that
  relationship changed, preserving the newer link. The domain action repeats
  that comparison while holding a row lock and performs a conditional update, so
  a change between the component check and the write is also preserved.
  Permission is rechecked too.
- Domain refusals render inside the open dialog for customer, contact, property,
  Lead unlink, list option, Location, staff and access-grant actions. Reopening a
  dialog clears only its own stale error; the existing domain error is not caught,
  renamed or moved to another scope.
- Contact removal and property archive also re-read and reauthorize the parent
  customer at confirmation time. If it was archived concurrently, the shared
  dialog renders the existing customer-scoped refusal without mutating the child;
  if access was revoked, the request remains forbidden.
- The durable prohibition on application-native confirms, alerts and prompts is
  recorded in `.ai/guidelines/collaboration.md`, [../design-system.md](../design-system.md)
  and decision 57. A repository-wide regression test covers view and JavaScript
  source plus bare, `window`, `globalThis` and `self` call forms.

## Checks

- Targeted B7 follow-up, qualification, disqualification and concurrency tests:
  **141 passed / 872 assertions**.
- Focused confirmation-dialog and Lead-customer suites after the final review
  corrections: **60 passed / 383 assertions**.
- Confirmation-dialog and affected customer, Lead, list, Location and access
  suites: **297 passed / 1,549 assertions**.
- Dirty PHP formatting: `php vendor/bin/pint --dirty --format agent` passed.
- Final local `composer test`: **passed**, including Pint, PHPStan and **1,470
  tests / 6,404 assertions**.
- [GitHub run 37669939744](https://github.com/jakerans/bananabackoffice/actions/runs/37669939744)
  on implementation commit `d1e60e8`: **both jobs passed**, each with **1,444 tests /
  6,235 assertions**. SQLite ran the full CI checks. MySQL verified an empty
  **8.4.11** database, applied every migration, ran the full suite, and confirmed
  **40 migrations** were applied by that suite to MySQL.
- [GitHub run 37670526216](https://github.com/jakerans/bananabackoffice/actions/runs/37670526216)
  on remote branch HEAD `61e3c2b` before this browser-review handoff: **both SQLite
  and MySQL 8.4 jobs passed**.
- [GitHub run 37679413429](https://github.com/jakerans/bananabackoffice/actions/runs/37679413429)
  on pushed commit `a05df3f`: **both SQLite and MySQL 8.4 jobs passed**. The run
  completed successfully in 3m 40s.
- `git diff --check`: passed. The branch is pushed through `a05df3f`. The final
  account-refusal and atomic-unlink corrections are local-only code and docs;
  exact-final-SHA CI has not run.
- Automated coverage includes due/future and central callbacks, location/role
  scoping, search, pagination, dialog authorization and stale confirmation,
  no lifecycle write on opening, validation after changing reasons, every new
  confirmation wrapper's open/confirm boundary, cross-list target substitution,
  stale Lead-customer replacement, locked relationship tampering, permission loss
  after opening, in-dialog refusal/error clearing, and a source inventory with
  detection fixtures that reject native application dialogs and aliases.
  The final additions cover a customer archived after opening contact/property
  confirmation, permission loss on that refusal path, and a relationship replaced
  after an unlink caller captured its expected IDs.

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
  Location was subsequently deactivated after it had no Leads or grants; no
  tracked, staging or production data changed.
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

### Application-confirmation Chrome re-review

Completed in a fresh tab in Jacob's connected local Chrome session after reloading
the current code:

- Opened every one of the nine affected actions. Each displayed the styled Flux
  dialog with the exact target and consequence; `getJsDialog()` returned no native
  JavaScript dialog for every flow. Cancel was initially focused.
- On the shared Location dialog, Tab moved from Cancel to the destructive action,
  Shift+Tab returned to Cancel, Escape closed the dialog, and focus returned to
  the Deactivate trigger. Cancel on the other flows likewise changed nothing.
- The empty `B7 Review Location (Fictitious)` is visibly inactive with zero ZIP
  codes and zero access grants. Four Corners remained active; no production,
  staging or unrelated local record was changed.
- A minimal local note, `Confirmation dialog browser review fixture (fictitious).`,
  was added to Lead 4 because no removable note existed. Its removal dialog was
  reviewed and cancelled. The note remains visible; no delete-like browser action
  was taken without action-time confirmation.
- The only captured console error was an extension message-channel closure from
  the earlier stale tab, not an application exception. A fresh Chrome tab remained
  connected throughout the completed review.
- After the independent review corrections, a fresh Chrome tab again showed the
  customer refusal dialog as styled application UI with Cancel focused and no
  JavaScript dialog. Its destructive submit was not clicked because computer-use
  policy requires action-time confirmation even for an expected refusal; the
  refusal rendering and stale/concurrent paths are covered by Livewire tests.
- After the final corrections and complete local suite, a fresh Chrome tab opened
  contact removal for Adam Lopez (Sample). The styled dialog named the target,
  Cancel had initial focus, Tab moved to Remove, Shift+Tab returned to Cancel,
  Escape dismissed without mutation and restored focus to the same Remove trigger;
  `getJsDialog()` reported no native JavaScript dialog.

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
6. **Application confirmations:** open customer archive, contact removal, property
   archive, note removal, Lead-customer unlink, list-option deactivation, Location
   deactivation, staff deactivation and access revocation. Each must use the styled
   dialog, initially focus Cancel, contain Tab/Shift+Tab, dismiss on Escape/Cancel,
   restore trigger focus and change nothing on dismissal. Confirm only a disposable
   fictitious target and verify the existing action semantics and audit entry.

## Remaining risks and limits

- The earlier B7 reconciliation and its additive lifecycle revision column remain
  prerequisites. Historical stored values are preserved. The guard still covers
  lifecycle mutations rather than every Lead edit.
- The branch is pushed through `a05df3f`, whose SQLite and MySQL jobs pass. The
  final concurrent-refusal corrections remain local until an authorized push.
  Exact-final-SHA CI therefore has not run and must run before an owner merge
  decision.
- Jacob requested a comparison with the original ChatGPT Backoffice conversation.
  The connected Chrome inventory showed that tab, but access was policy-denied
  because it could expose unrelated private conversation data. It was not read and
  no message was posted.
- Local note ID `1` on Lead `4`, text `Confirmation dialog browser review fixture
  (fictitious).`, remains visible. Removal is soft and retains history, but there
  is no application restore-note workflow; it was not removed without action-time
  confirmation.
- No staging or production commands were run. Stop for the owner's merge decision.
