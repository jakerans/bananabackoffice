# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `docs/architecture-roadmap-reconciliation`
**State:** documentation proposal awaiting owner decisions. No implementation,
merge, deployment or B8 work is authorized by this proposal.

## Objective

Reconcile the post-B7 roadmap and supporting documents with the canonical
[product architecture](../product-architecture.md), while preserving B1–B7 as-built
history and older decisions as historical context.

## Recommendation

Approve this sequence one bounded slice at a time:

1. **Five-Center shell alignment.** Marketing, Sales, Production, Finance and
   Company; Settings under Company; Color Studio external. Review the complete
   route/ID/access compatibility map before coding. No permission changes.
2. **Shared Customer Activity and unified Notes.** Preserve note context,
   provenance and history; Activity contains major business events, not every
   audit event.
3. **Contact-attempt evidence foundation.** Durable attempt records with manager
   pattern review and no automated punishment. Provider choice remains pending;
   no personnel policy is introduced.
4. **Scheduled work and atomic due queue.** Cadence is configurable. Decide
   scheduler, timezone, retry and failure contracts first. Keep manual Deferred
   Follow-up distinct from automated work.
5. **Appointment issuance and ownership-handoff contract.** Leave exact Project
   creation transition and Pending ownership/validity policy pending.

Product architecture statuses and implementation statuses are separate. A
`LOCKED` target is not approval to code it. Every item above is **proposed**.

## Decisions needed now

1. Approve, revise or reject the five-slice order.
2. If approved, authorize **slice 1 only** as the next implementation-planning
   boundary.
3. Confirm the compatibility recommendation: preserve working IDs and URLs with
   explicit aliases or redirects; remove nothing until its map is reviewed.
4. Confirm slice 1 changes navigation placement and labels only. In particular,
   Finance → People & Access does not give the Finance role staff-administration
   access, and Contact Center, Inbox, Sales Workspace and Production Workspace do
   not gain feature behavior yet.

## Constraints carried forward

- Shared Customers and related records remain permission-filtered canonical
  records. Related-record access never exposes unrelated work or sensitive GP,
  commission, payout or job-cost fields.
- Responsibility and assignment never grant access; separate grants do not union
  into authority neither grant provides alone.
- Old B8–B12 ordering, Project-from-Qualified and B9-only Open/Won/Lost assumptions
  remain historical pending revalidation. No exact Project or Pending rule was
  invented.
- No production action, feature implementation or deployment is part of this
  review.
