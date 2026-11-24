# First walkthrough on production: what was found, and what happens to each

Jacob's notes from the first walkthrough of the production app, 2026-11-23
(decision 233), sorted. Nothing below "Fixed" is approved or built.

## Fixed

- **"I cannot update a person's access: The location field is required, but
  it is already put in."** The Location box showed the first Location as if
  it were chosen when nothing was. It now starts on "Choose a location".
- **"Mark the GM as PM and as salesperson as well."** Already possible: a
  person holds any number of roles, one grant each, in Settings > Users and
  Access. The bug above is what stopped it.

## Already in slice 38 (QuickBooks), not built yet

- "Doesn't seem to be syncing to QuickBooks: did a test estimate."
- "We made invoices in the app and nothing got sent to QB."
- "Deposit needs to be collected by the homeowner, not the PM": the deposit
  invoice QuickBooks emails to the customer (decision 232).
- **New for slice 38:** "Sales reps need the ability to adjust the deposit %
  or change to a flat amount," on the estimate, before the customer signs.

## New work, proposed as slices, in the order recommended

| Slice | What | From the notes |
| --- | --- | --- |
| 39 | **Production from the accepted estimate.** The work package is filled from the option the customer signed, as PaintScout does; its days set the end date from the start date; the Brand Ambassador option chosen is carried over; Production sees a Project only once its deposit is collected or waived; one straight screen-to-screen path through production. | "Work Package needs to pull automatically from the estimate"; "automatically take the days"; "not pulling anything from the estimate into production"; "Production should not see any new projects until the deposit"; "the production flow is strange" |
| 40 | **The estimate and its presentation.** A description for each option, written by the rep, with its rates; quick choices (all rooms, add or remove closets, all ceilings, all baseboards); on the terms page, "I have read and agree to the terms below" at the top, which jumps to the signature. | "Custom presentation"; "Easy Options"; "put something at the top of the terms page" |
| 41 | **The handoff.** Saved as it is typed; a Company Cam video link. | "Project handoff" (two notes) |
| 42 | **Goals by year, quarter and month,** each month set by itself, for seasonality. | "Goals" |
| 43 | **Calendar.** A real calendar view beside the list; a new salesperson is asked to connect their business calendar. | "Calendar"; "prompt sales rep to connect calendar" |
| 44 | **Color management.** Pick from the areas in the accepted estimate; one color for all or a number of colors per area; manufacturer, sheen and product chosen from lists. | "Color management" |
| 45 | **Production edge cases.** A subcontractor taken off a job and a second brought in to fix it; the completed walkthrough needs a photo of the walkthrough document, or is filled in the app and shared by link. | "sub gets fired"; "Collected walkthrough" |

Small, any time:

- **Sales > Reports:** content spread too far left and right.
- **Hover explanations** on terms and figures throughout.
- **The reschedule notice names the customer and the address.** Today every
  notice leaves the customer out on purpose, so this changes an earlier
  decision.

## Answered

Decisions 234 and 235: slices 38 to 41 are built back to back; the answers
for each are in [decisions.md](decisions.md).

## Questions for Jacob, as they were asked

1. **The order.** *Recommended:* slice 38 (QuickBooks) first, since two of
   the notes are it; then 39, 40, 41, then the rest.
2. **The reschedule notice:** the customer's name and address in the notice
   inside the app only, or in the email too? *Recommended:* inside the app
   only; email is less private.
3. **Calendars:** which does Banana's business email use, Google or
   Microsoft?
4. **Albuquerque's QuickBooks** still needs connecting on production
   (Settings > Integrations) before slice 38's tax check.
