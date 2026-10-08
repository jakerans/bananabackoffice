# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-08 · **Branch:** `checkpoint-slice-3-contact-attempts`
**State:** approved slice 3 is built, independently reviewed, browser-verified and
green in GitHub SQLite and MySQL 8.4. Integration into `main` and its normal
automatic staging deployment are authorized; slice 4, B8, production and provider
integration are not.

## Objective

Review the bounded contact-attempt evidence foundation: append-only manual facts
linked to a Lead, with actor and impersonation provenance, current linked-record
and location snapshots, channel/direction, actual time, factual outcome and a
lifecycle snapshot. Manual evidence is never provider verification and cannot
change the Lead lifecycle.

## What changed

- Added the `contact_attempts` table, typed enums, immutable model and factory.
  Rows preserve Lead, customer/contact, recorded location, responsible rep,
  impersonator, source, channel, direction, actual event time, number, duration,
  answered/connected facts, disposition and lifecycle snapshot. Manual records
  force recording and transcript references to remain empty.
- Added one transactional action for manual recording. It locks and reloads the
  Lead, re-authorizes the current user against its current location, derives every
  protected snapshot server-side, validates factual combinations, and makes no
  status, assignment, follow-up, audit or Customer Activity change. A Lead-scoped
  submission identity makes an exact request retry idempotent; the stored actor,
  impersonation context and payload fingerprint reject a reused identity with
  changed facts or a different actor.
- Added a clearly labelled manual-entry modal and newest-actual-time-first history
  to Lead detail. Fixed dispositions derive their answered/connection truth;
  `Other` requires explicit facts. Phone-only and message-channel dispositions are
  filtered in the form and independently enforced by the action. Message sent and
  voicemail are not connections.
- History authorization is evaluated per attempt's recorded location. Unrouted
  history requires the existing company-wide permission. A historical contact is
  rendered only while its recorded customer is currently visible.

No existing permission, role, policy, lifecycle action, Customer Activity source,
dependency or URL changed. No provider, outbound communication, dialer, queue, AI,
nurture/cadence, manager-review penalty, B8 or slice 4 behavior is included.

## Tests and checks

- Final local `composer test`: passed Pint, PHPStan and **1,527 tests / 6,660
  assertions**.
- Focused contact-attempt and adjacent Lead regression run: **48 tests / 278
  assertions**.
- Dedicated contact-attempt coverage includes factual source/snapshots, lifecycle
  no-side-effects, exact-retry idempotency, changed-payload/actor rejection,
  channel/disposition compatibility, invalid/future/tampered input, write-time
  access revocation, direct-action authorization, historical location/customer
  privacy, deterministic actual-time ordering, immutability and impersonation
  provenance.
- `npm run build`: passed outside the restricted sandbox. The existing optional
  Fontaine optimization warning remains non-failing.
- `vendor/bin/pint --dirty --format agent`, PHPStan and `git diff --check`: passed.
- GitHub run `37706813800` passed both Checks and tests (SQLite), and Migrations
  and tests (MySQL 8.4), on reviewed feature SHA
  `61922a7ab1ba41d626d25dbea7698e4f9b9bd10d`.

## Architectural decisions made while building

No new product, finance, ownership or personnel-policy decision was made. The
bounded technical choices are:

- Each newly opened form receives a new opaque submission identity, so separate
  intentional attempts remain distinct even when their facts match. A retry of
  one form is idempotent under the Lead lock; mismatched actor, impersonation
  context, current Lead snapshot or submitted facts are rejected as stale.
- Attempt evidence is append-only and keeps display-name snapshots so later user
  deletion cannot erase who was responsible or who impersonated them.
- Read authorization follows the recorded location rather than the Lead's current
  location. Related contact identity additionally requires current visibility of
  the customer that was linked at the time.
- Actual event time, then record ID, determines history order. Creation time is
  retained separately as evidence of when the manual record was entered.

## Browser review

The actual local Chrome Owner session at `backoffice.test` rendered the Lead card,
empty state and manual-entry modal correctly. The review verified the explanatory
source/lifecycle copy, prefilled phone number, actual-time control, channel and
direction controls, duration input, `Connected` deriving Answered and Real two-way
connection as Yes, and Escape closing the modal without a write. After the review
fix, switching Phone to Email visibly removed call duration and replaced the stale
phone disposition with `Message sent` and its No/No factual summary.

With Jacob's explicit approval, the browser then saved one fictitious Connected,
42-second outbound Phone attempt on local Lead 5. The rendered history showed
Manual, the formatted fictitious number, responsible/recording actor, Yes answered,
Yes manually reported two-way connection, No lifecycle change, the Qualified
snapshot and Four Corners location. A database check confirmed exactly one row,
null provider recording/transcript references, and no change to current Lead
status, assignment or follow-up.

## Known limitations and decisions still needed

There is no known code blocker or new architecture decision. Provider choice,
provider-verified recordings/transcripts, suspicious-pattern manager review,
automated work/cadence, outbound communication and all slice 4 behavior remain
intentionally unimplemented and require later explicit decisions and approval.
