# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-slice-2-customer-activity-notes`
**State:** approved slice 2 is built, independently reviewed, browser-verified and
green in GitHub SQLite and MySQL 8.4. Integration into `main` and its normal
automatic staging deployment are authorized; production and slice 3 are not.

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
- Focused workspace suite: **7 tests / 59 assertions**, covering customer and
  related-Lead allow/deny boundaries, loss of access after page load, note
  aggregation across multiple authorized Leads, provenance/add/removal history,
  raw audit-value exclusion, curated sources, historical event-location and
  central visibility, deterministic chronology and independent pagination, and
  safe return context.
- Adjacent customer and Lead-note regression run: **275 tests / 1,519 assertions**.
- `npm run build`: passed outside the restricted sandbox. The existing optional
  Fontaine optimization warning remains non-failing.
- `git diff --check`: passed.
- GitHub run `37701752970` passed both Checks and tests (SQLite), and Migrations
  and tests (MySQL 8.4), on reviewed feature SHA
  `6b7176512b36c66a32368e31f0b717f8c66233db`.
- Independent local Chrome review passed on that SHA for customer and linked-Lead
  entry/back navigation, the separate Notes and Activity histories, empty-note
  validation, an authorized fictitious note add and its original-Lead provenance,
  remove-modal Escape/Cancel/focus/no-deletion behavior, curated newest-first
  Activity and actor/impersonation display, sensitive payload exclusion, Owner and
  scoped Consultant views, and unauthorized-customer refusal.

## Architectural decisions made while building

No new product or ownership decision was made. The bounded technical choices are:

- Notes remain Lead-owned; the workspace is a permission-filtered unified view,
  so no duplicate Account note store or authorization path was introduced.
- A removed note is represented by a tombstone and never by its body, preserving
  removal history without undoing the established "removed from view" behavior.
- The Activity allowlist is intentionally conservative and renders no audit
  payload fields. New domains require an explicitly approved event mapping before
  they can appear here.

## Browser-review coverage limit

The available fictitious browser fixture did not contain a customer with multiple
simultaneously accessible Leads, a removed-note tombstone, more than ten rows for
pagination, or a historical transferred-location event. Those paths are covered
by focused automated tests rather than claimed as browser evidence. The browser
review added one explicitly fictitious local note and made no other lasting change.

## Known limitations and decisions still needed

The slice intentionally does not add projects, conversations, proposals,
production, payments or any B8+ source that is not already represented by approved
B1–B7 records. There is no product or architecture decision still needed and no
known merge blocker remains. Slice 3 requires its own reviewed plan and explicit
implementation approval.
