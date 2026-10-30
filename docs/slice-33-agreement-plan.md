# Slice 33 — The Agreement: Plan

Status: **proposed on 2026-10-29 (decision 210) and approved the same day
with every recommendation, both steps built straight through (decision
211).** Written as what the implementation agent recommends, after the
plan check following slice 31. Numbered 33 because slice 32, Reports, is
already proposed.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 31:

1. **The selling flow in 5.3 is built up to the presentation:** the
   estimate, its options, the pricing and the presentation view (slice 31),
   and the after-action report (slice 7).
2. **The agreement is its last unbuilt piece** (5.3 "agreement", "accepted
   agreement"). Today "Sold" is a salesperson pressing a button and nothing
   is signed. Change orders (6.7, slice 18) are already written as
   amendments to an agreement that Backoffice does not hold, and a work
   package lists "signed agreement" (6.6).
3. **It is what still keeps PaintScout in use,** with the customer's copy
   and changing discounts and tax rates. Banana's contract, "Customer
   Agreement & Warranty Terms" (ABQ's "2026 CONTRACT" in PaintScout), is
   accepted there today.
4. **The brief already constrains it:** accepted proposals are stored as
   versioned snapshots (decisions, "Future decisions already constrained by
   the brief").

## Settled by Jacob (decision 210)

1. **The customer signs on the tablet,** with a finger, in Backoffice.
2. **The wording is ABQ's "2026 CONTRACT" from PaintScout,** imported from
   the saved settings file, which stays outside the repository.
3. **The customer gets a copy once they sign,** by email, but **not in this
   slice:** email to customers is set up later.

## What the salesperson and customer do

1. In the presentation view, the customer chooses an option and the
   salesperson presses **Accept this option**.
2. The **agreement page** opens on the tablet: the customer and the
   property; the option chosen, with its rooms, written line items,
   discounts, tax and price; the deposit due (the Location's deposit
   policy, 50% by default, from slice 17); and the contract's wording, with
   its 62 numbered sections and a table of contents to jump through them.
3. The customer types their name, ticks "I have read and agree to the
   Customer Agreement & Warranty Terms", signs with a finger in the box, and
   presses **Sign**.
4. The estimate is **Sold for that option**, through what exists since 31e,
   so the Project is made as it is today, and the agreement is kept
   unchanged. The page then shows the signed agreement.

## What is kept, for the record

- **The signed agreement:** what the customer saw (customer, property,
  option, prices, deposit due), the version of the wording, the name typed,
  the signature as an image in private storage (slice 16), the time, and
  the salesperson who presented it. Never changed afterwards.
- **The wording, in versions.** A new version never changes an agreement
  already signed. It is stored as numbered sections of plain paragraphs and
  lists, not as web code, so nothing in it can run in a browser.
- **The Audit Log** records each version of the wording and each signing.

## Proposed steps

| Step | Delivers |
| --- | --- |
| 33a | **The wording.** The contract's wording, in versions, imported once from ABQ's saved settings; shown read-only in Settings > Pricing with its table of contents; the Owner and Leadership may load a new version. |
| 33b | **Signing.** "Accept this option" in the presentation view; the agreement page; the signature box; signing sells the estimate for that option; the signed agreement shown on the estimate, the Lead and the Project, as a page that prints. |

## Questions, settled by decision 211 with every recommendation

1. **Signing at the appointment, before the after-action report.** The
   customer signs during the appointment, but the appointment is only marked
   run, and the estimate presented, in the report afterwards.
   *Recommended:* signing marks the estimate presented at that appointment
   and Sold; the report then shows it already sold and asks only the rest
   (notes, other estimates). Signing is offered only at the salesperson's
   appointment for that Lead that has already started that day.
2. **May "Sold" still be pressed without a signature?** For a paper
   contract, or a sale agreed by phone after a return visit.
   *Recommended:* yes, as today, and the Lead and Project show "Not signed
   in Backoffice" for it.
3. **One wording for the company, or one per Location?** Farmington's
   PaintScout settings are the same as ABQ's (decision 197).
   *Recommended:* one for the company now; a Location's own later, if ever
   needed.
4. **The deposit at signing.** *Recommended:* the agreement shows the
   deposit due from the Location's policy; nothing is collected in
   Backoffice at signing, and the deposit is recorded on the Project from
   QuickBooks, as today (slice 17).
5. **A signed agreement that was a mistake** (wrong option, wrong
   customer). *Recommended:* a manager corrects the sale as today (decision
   128); the agreement is kept and marked void with the reason, never
   removed.

## Not in slice 33

Emailing the customer a copy (later, with email to customers); signing away
from the tablet, such as by a link (13, the customer portal); taking the
deposit by card; Commercial estimates; signing change orders in Backoffice
(slice 18 keeps the signed copy, as now).

## Step 33a as built

- **The wording, in versions** (`agreement_wordings`): a version number, the
  title, the wording as plain text, where it came from, and who saved it. A
  version is never changed; the newest is the one customers sign.
- **The plain text:** one line per thing. "18. PAYMENT TERMS", a number and
  a title in capitals, starts a section; "- " starts a list item and "1) " a
  numbered list item; every other line is a paragraph. It is shown escaped,
  so nothing typed can run in a browser. PaintScout's emphasis (one "No
  Warranty" in bold) is dropped; the words are kept.
- **The import:** `php artisan backoffice:agreement-import <file>` reads the
  terms named "2026 CONTRACT" from the saved PaintScout settings file and
  saves them as the next version, refusing the same wording twice. Banana's
  contract reads as 62 sections with 31 lists, nothing left over.
- **Settings > Pricing Settings > Agreement wording**
  (`/settings/pricing/agreement`), from an "Agreement wording" button on
  Pricing Settings: the title, a table of contents, each section, and the
  versions, any of which can be read. Whoever may see prices reads it; the
  Owner and Leadership change it, in a form holding the wording as plain
  text, saved as a new version. A change made meanwhile by someone else is
  refused rather than overwritten.
- **The Audit Log** records each version: "Pricing · Agreement wording saved",
  with its number, title, how many sections and where it came from, linked
  to that version.

**Known limits:** the contract says the accepted Quote states the warranty
period and the preparation level; Backoffice's estimates have no field for
either yet, so they appear only where a line item says so.
