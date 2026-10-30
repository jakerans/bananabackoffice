# Slice 35 — The Rest of Pricing Settings: Plan

Status: **proposed on 2026-10-30 after the plan check following slice 34,
and approved the same day with every recommendation, both steps to be built
straight through (decision 213).** Written as what the implementation agent
recommends.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 34 (the full check is in the review handoff of 2026-10-30):

1. **5.3, the Sales Workspace, is built** except what needs a provider or a
   decision (texting, Google Calendar, the emailed copy, the deposit taken
   at signing).
2. **Before salespeople stop using PaintScout,** discounts, tax rates, area
   templates and line item templates must be changeable in Backoffice. They
   are shown in Settings > Pricing Settings, read-only since 31a; a new
   discount, a changed city tax rate or a new room name needs the PaintScout
   import run again.

Estimates already keep their own copy of every discount, tax rate and line
item (31c), so a change in Settings reaches an estimate only when the
salesperson reprices it.

## Settled by Jacob (decision 213)

1. **Retired, never deleted.** A retired item stops being offered to new
   estimates; the estimates that used it keep it.
2. **Who:** whoever may change production rates (`settings.pricing.manage`: Owner
   and Leadership). Everyone who may see Pricing Settings sees them.
3. **Every change in the Audit Log,** from what to what, as rates are.
4. **Both steps built straight through.**

## Steps

| Step | Delivers |
| --- | --- |
| 35a | **Discounts and tax rates.** Add, change, reorder and retire a discount (a percentage or an amount) and a tax rate, in the default price book or a Location's own. Discounts marked "Starts on new estimates" and the one default tax rate are already chosen on a new estimate, and can be changed there as today. |
| 35b | **Area and line item templates.** Add, change, reorder and retire the room names an estimate offers (inside, outside or either) and the line items (a name, a description, a price or hours at an hourly rate), with the estimate types each is offered for. An estimate cannot be given a retired one, or one from another price book. |

## Who

- **Changing:** `settings.pricing.manage` (Owner and Leadership), in a book they may
  change: the default, or a Location's own once customized. As production
  rates.
- **Seeing:** everyone who may see Pricing Settings, as today.

## Not in slice 35

Deleting a discount, tax rate or template; a tax rate chosen from the
customer's address; PaintScout's per-surface overrides; Commercial
estimates.

## Step 35a as built

- **Settings > Pricing Settings > Discounts:** Add discount; each discount's
  Edit (its name, a percentage or an amount in dollars, "Starts on new
  estimates", "Offered on new estimates") and arrows to move it up or down.
  A retired discount shows "Retired"; one that starts on new estimates says
  so.
- **Hourly rates and tax > Tax rates:** the same for tax rates (a name and a
  percent with up to four decimals), with "The default". Making one the
  default takes it from the other, so a book has at most one.
- **A new estimate starts with them:** the discounts marked "Starts on new
  estimates" and the default tax rate, if they are offered. The salesperson
  can untick them as before. None of ABQ's is marked yet, so nothing changes
  until someone marks one.
- **An estimate keeps what it has:** it still lists a retired discount or
  tax rate it was given, marked "retired", so it can be taken off; other
  estimates are not offered it. A change reaches an estimate only when it is
  repriced, as before.
- **Recorded in the Audit Log** (Pricing): a discount or tax rate added, or
  changed with only what changed; a new default with the one it replaced;
  a move, with the list, the item and its new place.
- **Who:** `settings.pricing.manage` (Owner and Leadership), in the default
  book or a Location's own; the rest see the lists without the buttons.

**Decisions made while building:**

- A retired discount or tax rate cannot also be the one new estimates start
  with; the dialog says so.
- The book's own "Tax (%)" under Hourly rates is PaintScout's setting and
  no estimate uses it. It is kept, now explained as kept for reference; the
  named tax rates are what estimates are charged.

**Known limits:** a tax rate is matched to an estimate by its name when the
estimate is repriced, as since 31c, so renaming one leaves estimates that
have it as they were.
