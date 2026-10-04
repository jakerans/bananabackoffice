# Design System — Application Decisions

The visual authority is
`docs/reference/brand/Banana-Backoffice-Application-Design-System.pdf`. This
document records how that guide is applied in code. If the two disagree, the PDF
wins and this document is corrected.

## Principles (from the guide)

- 90% calm professional application, 10% unmistakable Banana personality.
- CRM screenshots define layout and density. Banana defines visual identity.
- When in doubt: clarity, speed and consistency over decoration.
- The test: without the logo the interface should still feel intentional, and no
  employee should have to fight the brand to do their job.

## Color tokens (from the guide)

| Token | Value | Use |
| --- | --- | --- |
| Banana Yellow | `#FFD21F` | Primary action, current-item marker. An accent, never a large background. |
| Ink | `#111111` | Header, text, structure. |
| Cream | `#FFF8E8` | Workspace background. |
| Card | `#FFFCF4` | Surfaces: cards, tables, panels. |
| Wall Teal | `#17707A` | Restrained secondary emphasis; focus rings. |

## Typography (from the guide)

| Role | Typeface | Rule |
| --- | --- | --- |
| Navigation, buttons | Poppins 600–800 | Straight, compact, legible. |
| Body, forms, tables | Outfit 400–600 | The default. |
| Money, dates, customer data | Outfit, tabular numerals | Straight and aligned. |
| Branded display | Knewave 400 | Center landing pages, onboarding, selected empty states only. |
| Handwritten accent | Permanent Marker 400 | Rare decorative aside only. |

## Shell (from the guide)

- Header: Ink, compact; brand left, center context, authorized location selector,
  user controls.
- Primary navigation: horizontal; current item uses weight plus a small Yellow marker.
- Workspace: Cream canvas, Card surfaces, wide enough for real tables and boards.
- Page actions: one clear Yellow primary action; secondary actions recede.
- Location switching: authorized locations only; All Locations needs explicit permission.

## Components (from the guide)

| Component | Rule |
| --- | --- |
| Buttons | Yellow primary. Ink strong secondary when justified. Card or outline ordinary secondary. Direct labels. |
| Cards | Restrained radius and shadow. Never tilted. One treatment per region. |
| Tables | Straight grid, useful sorting and filtering, aligned numbers, visible row actions. No decoration. |
| Forms | Label above, helper text below, error beside the field. Teal focus. Yellow main submit. |
| Status chips | Compact, text-labelled, semantic. Color supports the label, never replaces it. |
| Modals, drawers | Focused tasks only. No long workflows in small modals. |
| Empty states | The best place for a small Banana doodle or a display-font moment. |
| Dashboards | Real metrics only. Never invent analytics to fill a layout. |

## Hard guardrails (from the guide)

- Never Knewave or handwriting for money, dates, customer names, table headers,
  form labels, legal language, pricing, margins, payroll, commissions or errors.
- Never make a critical control playful enough to be ambiguous.
- Never rotate anything decoratively inside dense data areas.
- Readable contrast, visible keyboard focus, generous click targets, text or icon
  labels for state. Never communicate status by color alone.

Personality belongs on: center landing pages, onboarding, empty states, success
confirmations. It stays away from: customer tables, estimate line items,
scheduling grids, financial reports, payroll, commissions, job costing, error
states, legal and proposal terms, dense forms.

## Implementation decisions

Status: **Approved** (2026-10-04).

1. **Tokens in one place.** Colors and fonts are declared once in
   `resources/css/app.css` as Tailwind theme tokens. No hex values in templates.
2. **Flux accent is Banana Yellow with Ink text.** Flux's "primary" button then
   follows the guide automatically. Ink on Yellow is high contrast.
3. **Yellow is never used for text on Cream or Card.** The contrast is too low to read.
4. **Light theme only.** The guide defines a light workspace and no dark palette.
   The starter kit's dark mode and Appearance page are removed rather than left
   half-styled. A dark theme can be designed later.
5. **Fonts load through the font loader already in the starter kit.** All four
   typefaces are free and open-licensed, are downloaded at build time and served
   from this application. No new package.
6. **Tabular numerals** on every money, quantity and date column.
7. **Status colors.** The guide names five colors and asks for semantic chips but
   does not define success, warning or danger hues. Proposed: muted green, amber
   and red, always with a text label, used only for status.
8. **Header is the starter kit's header layout**, restyled. The sidebar layout is
   retired.
9. **Tables use the free Flux table.** Nothing in this plan needs Flux Pro.

## Assets noted on inspection

- `docs/reference/brand/01`–`06` are the public **Banana Painting** logos (PNG only).
- The **Banana Backoffice** logo exists only inside the guidelines sheet image; there
  is no standalone logo or favicon file for it.
- Until standalone Backoffice logo files are supplied, the header uses the wordmark
  treatment drawn in the design guide (a Yellow block reading BANANA in Ink), which
  needs no image file.
- Brand files used by the application are copied into `public/` at the size
  needed. Nothing is served from `docs/reference/`.
