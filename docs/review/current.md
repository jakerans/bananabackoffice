# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-slice-2-customer-activity-notes`
**State:** approved slice 2 is built, locally verified and green in GitHub SQLite
and MySQL 8.4. The required independent local Chrome review remains before merge.

## Objective

Review the bounded canonical slice 2: one full-page customer workspace with a
curated major-business-event Activity timeline and context-linked Notes unified
across the customer's currently authorized Leads. Preserve note history and
provenance, use existing B1–B7 records/actions, enforce current related-record
and location access, and return safely to the authorized page that opened it.

## What changed

- Added `/customers/{account}/workspace`, reachable from both customer detail and
  a linked Lead. The Back link accepts only an authorized related Lead context or
  the current customer; arbitrary return URLs are never accepted.
- Notes from the customer's currently visible Leads are unified newest-first.
  Every note keeps and displays its original Lead context, location, author and
  timestamp. Removed notes remain as provenance tombstones while their removed
  text stays out of the rendered page.
- Adding and removing notes reuse the existing `AddNote`, `RemoveNote` and
  `NotePolicy` behavior. A note context is selected from only the related Leads
  where the current viewer may write; every action re-resolves and re-authorizes
  that Lead or note.
- Activity reads the immutable audit record through a fixed allowlist of major
  customer and Lead lifecycle, routing and ownership events. It renders only a
  human label, authorized record context, location, timestamp and recorded actor.
  Raw before/after values and reasons are never rendered, and note events, field
  corrections, contact/property administration, list changes and unrelated
  audit sources are excluded. Each Lead event is also filtered by the location
  where it occurred; central events require the existing company-wide unrouted
  Lead permission, so a later transfer cannot expose an earlier location's event.
- Notes and Activity paginate independently, newest first with ID tie-breaking.
  Every subsequent Livewire request rechecks current customer access, and every
  query begins from the currently visible related Leads.

No migration, dependency, role grant, permission enum, policy, domain action,
audit event, existing note record or existing URL changed. No B8 or later slice
work is included.

## Tests and checks

- Final local `composer test`: passed Pint, PHPStan and **1,509 tests / 6,555
  assertions**.
- Focused workspace suite: **7 tests / 57 assertions**, covering customer and
  related-Lead allow/deny boundaries, loss of access after page load, note
  provenance/add/removal history, raw audit-value exclusion, curated sources,
  historical event-location and central visibility, deterministic chronology and
  independent pagination, and safe return context.
- Adjacent customer and Lead-note regression run: **275 tests / 1,519 assertions**.
- `npm run build`: passed outside the restricted sandbox. The existing optional
  Fontaine optimization warning remains non-failing.
- `git diff --check`: passed.
- GitHub run `37701314810` passed both Checks and tests (SQLite), and Migrations
  and tests (MySQL 8.4), on authorization-correction SHA
  `3202d38e7b263c8f1a0a9e92128265ed43645b64`.
- Local Chrome review: blocked because this task has no local browser-control
  session exposed. The installed computer-use workflow requires that interface;
  no substitute browser was used or reported as an independent Chrome review.

## Architectural decisions made while building

No new product or ownership decision was made. The bounded technical choices are:

- Notes remain Lead-owned; the workspace is a permission-filtered unified view,
  so no duplicate Account note store or authorization path was introduced.
- A removed note is represented by a tombstone and never by its body, preserving
  removal history without undoing the established "removed from view" behavior.
- The Activity allowlist is intentionally conservative and renders no audit
  payload fields. New domains require an explicitly approved event mapping before
  they can appear here.

## Exact browser review

Use only fictitious local data at `http://backoffice.test`.

1. As an Owner, open a customer with at least two linked Leads. From customer
   detail choose **Activity & notes**. Confirm the full page, newest-first Notes
   and Activity, independent pagination, Lead/location context and actor/time
   provenance. Confirm no raw audit payload or finance/cost/pay/GP/commission data.
2. Add a note after choosing each available Lead context. Open that Lead and
   confirm the same note remains attached there. Remove a note from the workspace;
   confirm the styled modal traps focus, Escape/Cancel preserve the note, confirm
   returns focus safely, and the workspace shows a tombstone without the body.
3. Open the workspace from a linked Lead and confirm **Back** returns to that Lead.
   Open it from customer detail and confirm **Back** returns to customer detail.
4. As a location-scoped user, use a customer shared across two locations. Confirm
   only the permitted location's Lead contexts, notes and activity appear, and a
   hidden Lead cannot be selected by changing the URL or request state.
5. While a workspace is open, remove the test user's relevant local grant in a
   second authorized session, then try to add/remove/paginate. Confirm the request
   is refused and no data changes. Restore the fictitious grant afterward.

## Known limitations and decisions still needed

The slice intentionally does not add projects, conversations, proposals,
production, payments or any B8+ source that is not already represented by approved
B1–B7 records. There is no product or architecture decision still needed. Merge is
blocked only on the independent local Chrome review.
