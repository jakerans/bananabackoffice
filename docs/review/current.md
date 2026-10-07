# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-b-continuation`
**Step:** B7 reconciliation only, approved in decision 55 of
[../decisions.md](../decisions.md). No B8 or architecture-roadmap work is included.
**State:** implementation complete; final checks and CI verification in progress.
Not merged or deployed. Jacob's browser review and explicit merge decision remain.

## What changed, and why

- **Deferred Follow-up / Schedule follow-up** replaces the product wording
  "Nurture". It is a deliberate manual callback with a required date and note.
  Due/overdue dates, assignee responsibility and existing Follow-ups queues keep
  their behavior. This is separate from future multichannel nurture.
- **Unable to Contact is historical only.** It cannot close a Lead now, nor be
  newly selected by a correction, regardless of elapsed time or role. The three-day
  rule and waiver action are removed, with no replacement threshold. Existing
  records retain the reason; their notes can be corrected, a legitimate reason can
  replace it, and authorized people can reopen them.
- **Qualified is the existing qualification milestone only.** The dialog and Lead
  page explain that it does not mean booked, Lead Issued, demoed, sold or
  Project-created. Qualification requirements and first-qualification history stay.
- **Stale lifecycle changes are rejected atomically**, including same-status
  follow-up reschedules and disqualification corrections. One additive
  `lifecycle_version` column starts existing Leads at zero. A conditional update
  claims the expected revision, status, Location and assignee before validation
  and writes. Assignment changes, history, audit and revision share the transaction.
  Unchanged saves do not advance the revision. Dialog revisions are server-locked;
  immediate Start working/Resume actions retain the rendered page's revision.
- Stored `nurture` values, event identifiers and historical audit data are not
  renamed or rewritten. Legitimate disqualification, duplicate handling, reopening
  and existing authorization remain. Legacy waiver grants are retained but no
  lifecycle action consumes them.

Canonical behavior: [../checkpoint-b.md](../checkpoint-b.md), "Qualification,
disqualification and deferred follow-up, as built in step B7". The older downstream
roadmap is not authorization to start new work.

## Checks

- `composer test`: passed; Pint and PHPStan passed, **1,440 tests / 6,177 assertions**.
- Targeted lifecycle and audit tests cover same-second stale writes, same-status
  dialogs, status returning to its prior value, stale assignment context,
  historical reasons/audit preservation, duplicate rules, authorization and rollback.
- `php artisan migrate --no-interaction`: the additive revision migration passed
  against the existing local SQLite database.
- Final CI-equivalent local check and GitHub SQLite/MySQL 8.4 results: pending.
- No staging or production commands were run.

## Browser review for Jacob

At [the local Leads page](http://backoffice.test/leads), refresh existing tabs first.
Use fictitious local Leads. Settings > Users and Access > a local Sales Consultant
> Impersonate is available when reviewing as that role.

1. On a Working Lead assigned to that consultant, choose **Schedule follow-up**.
   Empty note/date and a past date must fail. Save a future date and note: the badge
   reads **Deferred Follow-up**. Set today to see **Follow-up due**. Check Follow-ups,
   then Resume working: the callback clears and the same person remains assigned.
2. Open the same deferred Lead in two tabs and open **Reschedule follow-up** in
   both. Save a changed date or note in tab A, then submit tab B. B must refuse the
   stale save; reopen its dialog to see A's values. Repeat changing only the note.
3. **Qualify** a Working Lead: missing required information still blocks it. A
   complete Lead qualifies, shows the milestone-only explanation and retains its
   assignee. Return to working and qualify again; first qualified stays unchanged.
4. As both the consultant and Owner, open **Disqualify** on a Working Lead.
   **Unable to Contact** and the wait-waiver checkbox must be absent; ordinary
   nonresponse offers **Schedule follow-up instead**. Legitimate reasons still work;
   Other requires a note, and Duplicate Lead requires an original or explanation.
5. As Owner, use **Correct reason** on a disqualified Lead in two tabs. Save tab A,
   then tab B: B must refuse even though the status is still Disqualified. Reopen
   the dialog and verify A's correction. Reopen the Lead with a reason: it becomes
   Working and unassigned.
6. If a historical Unable to Contact Lead exists locally, verify it remains
   readable. Its current reason can stay while its note is corrected, including
   when that managed option is inactive. Correcting a different Lead must not offer
   Unable to Contact. Historical preservation is also covered by automated tests.
7. Move a deferred Lead to another Location or return it to central as an authorized
   distributor. Verify its follow-up remains in the appropriate existing Follow-ups
   view, labeled Unassigned. A consultant still cannot act on another person's Lead
   or reopen/correct disqualification; impersonation does not add Owner privileges.

## Remaining risks and limits

- Owner browser review remains; automated Livewire tests are not a visual sign-off.
- The revision guards B7 lifecycle operations, not every other Lead edit. Stale-save
  tests use competing snapshots and dialogs, not a multiprocess load test.
- The new column must accompany this code. Rolling it back drops revision tokens;
  use a forward fix after release rather than retaining this code without it.
- Existing historical closure reasons are not automatically reclassified or reopened.
  Internal names still use `nurture` for compatibility. No automation, attempt
  tracking, reminders, appointments, Projects, Contact Center, Inbox or AI is built.
- The earlier production-readiness proposal remains unapproved and outside this
  task; its prior handoff is preserved in Git history. No new product decision is
  needed to review this bounded reconciliation.
