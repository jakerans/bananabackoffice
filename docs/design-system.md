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
   does not define success, warning or danger hues. In use: green for active, amber
   for invited or pending, grey for inactive, always with a text label and an icon,
   and only for status.
8. **The shell is one layout**, `resources/views/layouts/app.blade.php`, with its
   parts in `resources/views/components/shell/`. The starter kit's sidebar and
   header layouts are retired.
9. **Tables use the free Flux table.** Nothing built so far needs Flux Pro.
10. **The Ink header is the only dark region.** It carries a `dark` class so Flux
    draws its controls for a dark surface there; the rest of the interface is light.
11. **Display type appears in two places only so far:** the home page greeting and
    the empty state on a section that is not built yet. Section titles are straight
    Poppins, because a section page is an operational page. Center landing pages,
    which the guide lists as a place for display type, no longer exist.
12. **The header brand is the supplied Banana Backoffice horizontal logo**, used
    unaltered (2026-10-04). Its lettering is dark, so it sits on a Cream block at the
    start of the Ink header, the same place the guide's mock-up puts its brand
    block. The header height is unchanged. The brand mark is never redrawn in HTML.
    Open: a version of the logo made for dark backgrounds would let it sit directly
    on Ink; the guidelines sheet shows one, but no such file has been supplied.
13. **The favicon, touch icon and in-page icon are the supplied Banana Backoffice
    icon files**, used unaltered (2026-10-04). The sign-in pages show the horizontal
    logo. Nothing derived from the public Banana Painting logos remains in the
    application. Note: the supplied touch icon has a transparent background, which
    some phones fill with black when the site is added to a home screen.
14. **Unbuilt sections in the second row** are muted and not clickable. "Coming
    soon" appears as a tooltip on hover and as hidden text for screen readers, so
    the state is never carried by color alone.
15. **Application confirmations use one styled Flux modal pattern** (2026-10-07).
    The safe Cancel action receives initial focus; keyboard focus stays in the
    dialog; Escape and Cancel dismiss it without a mutation and return focus to
    the trigger. The destructive action is explicit and visually dangerous.
    Livewire locks the pending record identifier, then the confirmation action
    resolves and authorizes that target again before using the existing domain
    action. Application templates do not use native `wire:confirm`,
    `window.confirm`, `alert` or `prompt`. Browser- and operating-system-owned
    permission prompts are not application UI and are never bypassed.

## Assets noted on inspection

- `docs/reference/brand/01`–`06` are the public **Banana Painting** logos (PNG only).
- `Banana Backoffice Brand Guidelines.png` is a single sheet showing the logo
  variants, favicon sizes and colors together. It is a reference, not a source
  file, and nothing is cut out of it.
- The owner supplied the standalone **Banana Backoffice** files on 2026-10-04:
  `backoffice-logo-horizontal.png` (764 by 259, transparent, dark lettering), the
  icon in several sizes (`favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`,
  `favicon-48x48.png`, `icon-192.png`, `icon-512.png`, `apple-touch-icon.png`) and
  `backoffice-icon-source-1024.png`.
- Those files are copied into `public/` byte for byte. The copies in `public/` are
  part of the application and are committed; `docs/reference/` itself stays out of
  version control and nothing is served from it.
- Still not supplied: a logo for dark backgrounds, and vector (SVG) versions.
